<?php

use Igniter\Flame\Currency\Currency;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use IgniterLabs\SquarePOS\Classes\Dispatcher;
use IgniterLabs\SquarePOS\Classes\MoneyAmount;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Classes\VersionGate;
use IgniterLabs\SquarePOS\Exceptions\ClientException;
use IgniterLabs\SquarePOS\Exceptions\SyncException;
use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use IgniterLabs\SquarePOS\Jobs\SyncInventoryJob;
use IgniterLabs\SquarePOS\Jobs\SyncOrdersJob;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Tests\Support\CatalogResult;
use IgniterLabs\SquarePOS\Tests\Support\Square;
use Illuminate\Support\Facades\Bus;
use Square\Exceptions\SquareApiException;
use Square\Types\CatalogIdMapping;

it('converts money in both directions', function(): void {
    expect(MoneyAmount::fromCents(null))->toBe(0.0)
        ->and(MoneyAmount::fromCents(''))->toBe(0.0)
        ->and(MoneyAmount::fromCents(250))->toBe(2.5);

    $legacy = MoneyAmount::toMoney(4.5, 'gbp');
    expect($legacy->getAmount())->toBe(450)
        ->and($legacy->getCurrency())->toBe('gbp');

    $modern = MoneyAmount::toSquareMoney(1);
    expect($modern->getAmount())->toBe(100)
        ->and($modern->getCurrency())->toBe(MoneyAmount::currency());
});

it('reads the application currency', function(): void {
    expect(MoneyAmount::currency())->toBeString()->not->toBeEmpty();

    $currency = Mockery::mock(Currency::class);
    $currency->shouldReceive('getUserCurrency')->andReturn('');
    app()->instance(Currency::class, $currency);

    expect(MoneyAmount::currency())->toBe('USD');
});

it('tracks nested remote applies', function(): void {
    expect(SyncContext::isApplyingRemote())->toBeFalse();

    $value = SyncContext::applying(function() {
        expect(SyncContext::isApplyingRemote())->toBeTrue();

        return SyncContext::applying(fn(): string => 'inner');
    });

    expect($value)->toBe('inner')
        ->and(SyncContext::isApplyingRemote())->toBeFalse();
});

it('clears the remote apply flag when the callback throws', function(): void {
    expect(fn() => SyncContext::applying(function(): void {
        throw new RuntimeException('stop');
    }))->toThrow(RuntimeException::class);

    expect(SyncContext::isApplyingRemote())->toBeFalse();
});

it('decides whether a linked record should be pushed', function(): void {
    expect(VersionGate::shouldPush(1, null, false, true))->toBeTrue()
        ->and(VersionGate::shouldPush(1, 1, false, false))->toBeTrue()
        ->and(VersionGate::shouldPush(1, 1, true, true))->toBeTrue()
        ->and(VersionGate::shouldPush(1, 1, false, true))->toBeFalse()
        ->and(VersionGate::shouldPush(1, 2, false, true))->toBeTrue();
});

it('indexes catalog mappings, objects, and related objects', function(): void {
    $mapping = new CatalogIdMapping([
        'clientObjectId' => '#temp',
        'objectId' => 'REMOTE',
    ]);

    $object = Square::squareObject('ITEM', 'REMOTE');
    $related = Square::squareObject('CATEGORY', 'CAT');

    $response = new CatalogResponse(new CatalogResult([$object], [$mapping], 'cursor', [$related]));

    expect($response->getMapping('#temp')->getObjectId())->toBe('REMOTE')
        ->and($response->getMapping('missing'))->toBeNull()
        ->and($response->getObject('CAT')->getType())->toBe('CATEGORY')
        ->and($response->getObjects())->toHaveCount(2)
        ->and($response->getMappings())->toHaveCount(1)
        ->and($response->getResponse()->getCursor())->toBe('cursor');

    $empty = new CatalogResponse(new stdClass);
    expect($empty->getMapping('x'))->toBeNull()
        ->and($empty->getObject('x'))->toBeNull()
        ->and($empty->getMappings())->toBeNull()
        ->and($empty->getObjects())->toBeNull();
});

it('formats square client errors', function(): void {
    $api = new SquareApiException('failed', 400, ['errors' => [[
        'category' => 'INVALID_REQUEST_ERROR',
        'code' => 'BAD_REQUEST',
        'detail' => 'no',
        'field' => 'id',
    ]]]);
    $wrapped = new ClientException($api);
    expect($wrapped->getMessage())->toContain('BAD_REQUEST')
        ->and($wrapped->getPrevious())->toBe($api)
        ->and($wrapped->getCode())->toBe(400);

    $emptyApi = new class('failed', 422, null) extends SquareApiException
    {
        public function getErrors(): array
        {
            return [];
        }
    };
    expect((new ClientException($emptyApi))->getMessage())->toContain('failed');

    expect((new ClientException('plain', 7))->getMessage())->toBe('plain')
        ->and((new SyncException('sync'))->getMessage())->toBe('sync');
});

it('builds and runs a sync dispatcher', function(): void {
    Square::configureSquare();
    $client = Square::bindSquare();

    $task = Dispatcher::create(SyncCatalogJob::class)
        ->uuid('dispatcher-test')
        ->systemOfRecord(Settings::SQUARE_SOR)
        ->batchSize(5)
        ->syncInventory(false)
        ->import()
        ->dispatchSync();

    expect($task->description)->toBe('Pulling catalog from Square')
        ->and($task->isSystemOfRecordSquare())->toBeTrue()
        ->and($task->getConfigData('mode'))->toBe('import')
        ->and($task->getConfigData('createMissing'))->toBeTrue()
        ->and($task->batchSize())->toBe(5)
        ->and($task->status)->toBe('success')
        ->and($task->fresh()->started_at)->not->toBeNull()
        ->and($task->fresh()->finished_at)->not->toBeNull();

    $exported = Dispatcher::create(SyncCatalogJob::class)->export();
    expect($exported->mode)->toBe('export')
        ->and($exported->systemOfRecord)->toBe(Settings::IGNITER_SOR);

    Bus::fake();
    $queued = Dispatcher::create(SyncCatalogJob::class)->uuid('queued-task');
    $queued->dispatch();
    expect($queued->getSyncTask())->not->toBeNull();
    Bus::assertDispatched(SyncCatalogJob::class);

    $pushed = Dispatcher::create(SyncCatalogJob::class)->uuid('push-catalog')->export();
    $pushed->dispatch();
    expect($pushed->getSyncTask()->description)->toBe('Pushing catalog into Square');

    $orders = Dispatcher::create(SyncOrdersJob::class)->uuid('push-orders');
    $orders->dispatch();
    expect($orders->getSyncTask()->description)->toBe('Pushing orders into Square');

    $pulledInventory = Dispatcher::create(SyncInventoryJob::class)
        ->uuid('pull-inventory')
        ->systemOfRecord(Settings::SQUARE_SOR);
    $pulledInventory->dispatch();
    expect($pulledInventory->getSyncTask()->description)->toBe('Pulling inventory from Square');

    $pushedInventory = Dispatcher::create(SyncInventoryJob::class)
        ->uuid('push-inventory')
        ->systemOfRecord(Settings::IGNITER_SOR);
    $pushedInventory->dispatch();
    expect($pushedInventory->getSyncTask()->description)->toBe('Pushing inventory into Square');

    $client->shouldHaveReceived('searchCatalogObjects');
});

<?php

use Igniter\Admin\Models\Status;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Exceptions\ClientException;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\Tests\Support\Square;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Square\Types\Location;

it('stores connection settings and resolves mapped locations', function(): void {
    $settings = new Settings;

    expect($settings->getSourceOptions())->toHaveKeys([Settings::SQUARE_SOR, Settings::IGNITER_SOR])
        ->and($settings->getEnvironmentOptions())->not->toBeEmpty()
        ->and($settings->getSyncIntervalOptions())->toBe($settings->getInventoryIntervalOptions())
        ->and($settings->getOrderSyncIntervalOptions())->toHaveKey(15)
        ->and($settings->getHandleMissingOptions())->toHaveKeys(['disable', 'delete'])
        ->and($settings->getOrderCompletedStatusOptions())->toEqual($settings->getOrderCanceledStatusOptions());

    Square::configureSquare([
        'sync_interval' => 45,
        'inventory_interval' => 15,
        'order_sync_interval' => 99,
        'handle_missing' => 'delete',
        'sync_orders' => true,
        'sync_inventory' => true,
        'sync_catalog' => false,
        'source' => Settings::SQUARE_SOR,
        'location_mappings' => [
            ['client_id' => 4, 'remote_id' => 'REMOTE'],
        ],
    ]);

    expect(Settings::catalogInterval())->toBe(60)
        ->and(Settings::inventoryInterval())->toBe(15)
        ->and(Settings::orderInterval())->toBe(60)
        ->and(Settings::disablesMissingItems())->toBeFalse()
        ->and(Settings::isSyncEnabled())->toBeTrue()
        ->and(Settings::isCatalogSyncEnabled())->toBeFalse()
        ->and(Settings::syncOrders())->toBeTrue()
        ->and(Settings::getSystemOfRecord())->toBe(Settings::SQUARE_SOR)
        ->and(Settings::isConnected())->toBeTrue()
        ->and(Settings::remoteLocationId(4))->toBe('REMOTE')
        ->and(Settings::remoteLocationId(9))->toBeNull()
        ->and(Settings::clientLocationId('REMOTE'))->toBe(4)
        ->and(Settings::clientLocationId('missing'))->toBeNull()
        ->and(Settings::batchSize())->toBe(20)
        ->and(Settings::accessToken())->toBe('token')
        ->and(Settings::connectionData())->toHaveKey('access_token')
        ->and(Settings::getAuthorizeUrl(['state' => 'abc']))->toContain('authorize/square')
        ->and(Settings::getAuthorizeUrl([]))->toContain('ITEMS_WRITE');

    Settings::rememberSearchBeginTime('item', 'then');
    Settings::rememberSearchBeginTime('category', 'earlier');
    expect(Settings::searchBeginTime('item'))->toBe('then')
        ->and(Settings::searchBeginTime('category'))->toBe('earlier')
        ->and(Settings::searchBeginTime('modifier'))->toBeNull();

    Settings::storeConnectionData([
        'merchant_id' => 'M2',
        'token_type' => 'bearer',
        'access_token' => 'fresh',
        'refresh_token' => 'again',
        'expires_at' => '2030-01-01T00:00:00Z',
        'ignored' => 'nope',
    ]);
    expect(Settings::accessToken())->toBe('fresh')
        ->and(Settings::connectionData('ignored', 'fallback'))->toBe('fallback');

    Settings::clearConnectionData();
    expect(Settings::isConnected())->toBeFalse()
        ->and(Settings::locationMapping())->toBeEmpty()
        ->and(Settings::remoteLocationId(4))->toBeNull();
});

it('connects with a sandbox access token', function(): void {
    Square::configureSquare([
        'environment' => 'production',
        'connection_status' => false,
        'connection_data' => ['access_token' => 'oauth-token'],
    ]);

    Settings::set([
        'environment' => 'sandbox',
        'application_id' => 'sandbox-app',
        'access_token' => 'sandbox-token',
    ]);

    expect(Settings::isConnected())->toBeTrue()
        ->and(Settings::accessToken())->toBe('sandbox-token')
        ->and(Settings::get('application_id'))->toBe('sandbox-app');

    Settings::set(['environment' => 'production']);
    expect(Settings::accessToken())->toBe('sandbox-token');

    $connected = new Settings;
    $connected->connection_status = true;
    $fields = ['connect_button' => true, 'environment' => true];
    $connected->filterFields(null, $fields);
    expect($fields)->not->toHaveKey('connect_button')
        ->and($fields)->toHaveKey('environment');

    $open = new Settings;
    $open->connection_status = false;
    $fields = ['connect_button' => true];
    $open->filterFields(null, $fields);
    expect($fields)->toHaveKey('connect_button');
});

it('loads remote location options and keeps the current token when refresh fails', function(): void {
    Square::configureSquare(['connection_status' => false]);
    expect((new Settings)->getRemoteIdOptions())->toBe([]);

    Square::configureSquare();
    Square::bindSquare();

    $settings = new class extends Settings {};

    expect($settings->getRemoteIdOptions())->toBe([]);

    $location = new Location;
    $location->setId('L1');
    $location->setName('Counter');
    $client = Mockery::mock(SquareClient::class);
    $client->shouldReceive('listLocations')->andReturn(collect([$location]));
    app()->instance(SquareClient::class, $client);
    cache()->forget('squarepos.locations');
    expect((new Settings)->getRemoteIdOptions())->toBe(['L1' => 'Counter']);

    $unavailable = Mockery::mock(SquareClient::class);
    $unavailable->shouldReceive('listLocations')->andThrow(new ClientException('unavailable'));
    app()->instance(SquareClient::class, $unavailable);
    cache()->forget('squarepos.locations');
    expect((new Settings)->getRemoteIdOptions())->toBe([]);

    Square::configureSquare([
        'connection_data' => [
            'merchant_id' => 'M',
            'access_token' => 'old',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addMinutes(4)->toISOString(),
        ],
    ]);
    Http::fake(['*' => Http::response(['access_token' => 'new', 'refresh_token' => 'refresh'], 200)]);
    Settings::ensureFreshToken();
    expect(Settings::accessToken())->toBe('new');

    Http::fake(function(): void {
        throw new RuntimeException('offline');
    });
    Settings::ensureFreshToken();
    expect(Settings::accessToken())->toBe('new');

    Http::fake(['*' => Http::response(['error' => 'no'], 422)]);
    Settings::ensureFreshToken();
    expect(Settings::accessToken())->toBe('new');

    Square::configureSquare();
    Settings::ensureFreshToken();
    expect(Settings::accessToken())->toBe('token');
});

it('records sync task notes, exceptions, and failure mail', function(): void {
    Square::configureSquare(['sync_notify_email' => null]);
    $task = Square::makeSyncTask();
    $menu = Square::makeMenu();
    Square::linkOwner($menu, ['square_id' => 'ITEM', 'square_version' => 4]);

    expect($task->isSystemOfRecordSquare())->toBeFalse()
        ->and($task->status)->toBe(SyncTask::STATUS_QUEUED)
        ->and($task->mailGetData()['sync_status'])->toBe('Queued')
        ->and($task->getStatusNameAttribute(null))->toContain('Queued')
        ->and($task->getCreatedSinceAttribute(null))->toBeString()
        ->and($task->batchSize())->toBe(20)
        ->and($task->mappedLocationIds())->toBeArray()
        ->and(Settings::getJobRetentionDays())->toBe(30)
        ->and($task->prunable()->toSql())->toContain('created_at');

    $task->markAsRunning();
    expect($task->status)->toBe(SyncTask::STATUS_RUNNING)
        ->and($task->mailGetData()['sync_status'])->toBe('Running')
        ->and($task->getStatusNameAttribute(null))->toContain('Running');

    $task->markAsQueued();
    expect($task->fresh()->status)->toBe(SyncTask::STATUS_QUEUED);

    $unsaved = new SyncTask;
    expect($unsaved->getCreatedSinceAttribute(null))->toBeNull()
        ->and($unsaved->batchSize())->toBe(Settings::batchSize());

    $task->note('one')->noteUpdated($menu, 'AMALA')->noteDeleted(new class
    {
        public function getId(): string
        {
            return 'GONE';
        }
    }, 'Old')->noteDeleted(new stdClass, 'Unknown');

    expect($task->notes)->toHaveCount(4)
        ->and($task->getExceptionMessageAttribute())->toBeNull();

    $task->setConfigData('mode', 'import')->forgetConfigData('mode');
    expect($task->getConfigData('mode', 'sync'))->toBe('sync');

    $task->markAsFailure(new RuntimeException('broke', 9))->clearException();
    expect($task->fresh()->exception)->toBeNull();

    $task->markAsFailure(new RuntimeException('broke'));
    expect($task->getExceptionMessageAttribute())->toBe('broke')
        ->and($task->status)->toBe(SyncTask::STATUS_FAILURE)
        ->and($task->mailGetRecipients('notify'))->toBe([])
        ->and($task->mailGetData()['sync_status'])->toBe('Failure')
        ->and($task->getStatusNameAttribute(null))->toContain('Failure');

    $task->sendSummary();

    Square::configureSquare(['sync_notify_email' => 'ops@example.com']);
    Mail::fake();
    expect($task->mailGetRecipients('notify'))->toBe([['ops@example.com', 'ops@example.com']]);
    $task->sendSummary();
    $task->markAsComplete();
    $task->sendSummary();
    expect($task->fresh()->status)->toBe(SyncTask::STATUS_SUCCESS)
        ->and($task->fresh()->mailGetData()['sync_status'])->toBe('Success')
        ->and($task->fresh()->getStatusNameAttribute(null))->toContain('Success');

    Status::factory()->create(['status_for' => 'order', 'status_name' => 'Completed']);
    expect((new Settings)->getOrderCompletedStatusOptions())->not->toBeEmpty();
});

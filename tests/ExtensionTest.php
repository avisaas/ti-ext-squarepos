<?php

use Igniter\Admin\Classes\Widgets;
use Igniter\Cart\Models\Category;
use Igniter\Cart\Models\Menu;
use IgniterLabs\SquarePOS\BulkActionWidgets\SquareSync;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use IgniterLabs\SquarePOS\Classes\Dispatcher;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Extension;
use IgniterLabs\SquarePOS\Jobs\AbstractSyncJob;
use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Tests\Support\CatalogResult;
use IgniterLabs\SquarePOS\Tests\Support\Square;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('registers the extension services', function(): void {
    $extension = new Extension(app());

    expect($extension->registerPermissions())->toHaveKey('IgniterLabs.SquarePOS.Manage')
        ->and($extension->registerSettings()['settings']['model'])->toBe(Settings::class)
        ->and($extension->registerMailTemplates())->toHaveKey('igniterlabs.squarepos::mail.sync_completed');

    $extension->register();
    $extension->boot();
    expect(resolve(Widgets::class)->resolveBulkActionWidget('square_sync'))->toBe(SquareSync::class);

    $widget = new stdClass;
    $widget->model = new Menu;
    $query = Menu::query();
    Event::dispatch('admin.list.extendQueryBefore', [$widget, $query]);

    $other = new stdClass;
    $other->model = new Category;
    Event::dispatch('admin.list.extendQueryBefore', [$other, Category::query()]);

    expect($query->getEagerLoads())->toHaveKey('squareLink');
});

it('schedules catalog, inventory, and order sync when they are enabled', function(): void {
    Square::configureSquare([
        'sync_inventory' => true,
        'sync_orders' => true,
        'sync_catalog' => true,
    ]);
    $schedule = new Schedule;
    (new Extension(app()))->registerSchedule($schedule);
    $commands = collect($schedule->events())->map(fn($event) => $event->command);

    expect($commands->contains(fn($command): bool => str_contains((string)$command, 'square:sync --menus')))->toBeTrue()
        ->and($commands->contains(fn($command): bool => str_contains((string)$command, 'square:sync --inventory')))->toBeTrue()
        ->and($commands->contains(fn($command): bool => str_contains((string)$command, 'square:sync --orders')))->toBeTrue();

    foreach ($schedule->events() as $event) {
        foreach ((new ReflectionProperty($event, 'filters'))->getValue($event) as $filter) {
            expect($filter())->toBeTrue();
        }
    }

    Square::configureSquare([
        'connection_status' => false,
        'sync_inventory' => false,
        'sync_orders' => false,
        'sync_catalog' => false,
    ]);
    $schedule = new Schedule;
    (new Extension(app()))->registerSchedule($schedule);

    foreach ($schedule->events() as $event) {
        foreach ((new ReflectionProperty($event, 'filters'))->getValue($event) as $filter) {
            expect($filter())->toBeFalse();
        }
    }
});

it('falls back when the sync interval cannot be read', function(): void {
    $database = config('database.connections.mysql.database');
    Settings::clearInternalCache();
    config(['database.connections.mysql.database' => 'squarepos_missing_database']);
    DB::purge('mysql');

    expect(Settings::safeInterval('orders'))->toBe('*/15 * * * *')
        ->and(Settings::safeInterval('inventory'))->toBe('0 * * * *')
        ->and(Settings::safeInterval('catalog'))->toBe('0 * * * *');

    config(['database.connections.mysql.database' => $database]);
    DB::purge('mysql');
    DB::reconnect('mysql');
});

it('follows a catalog cursor until the page is finished', function(): void {
    $pipe = new class(Square::makeSyncTask()) extends AbstractSyncJob
    {
        public int $runs = 0;

        public function execute(): void
        {
            $this->runs++;
            if ($this->runs === 1) {
                $this->handleResponseCursor(new CatalogResponse(new CatalogResult([], [], 'next')), 'itemCursor');
            }
        }

        public function cursor(): mixed
        {
            return $this->syncTask->getConfigData('itemCursor');
        }
    };

    $pipe->execute();
    expect($pipe->runs)->toBe(2)
        ->and($pipe->cursor())->toBe('next');

    $done = new class(Square::makeSyncTask()) extends AbstractSyncJob
    {
        public function execute(): void {}

        public function finish(): void
        {
            $this->handleResponseCursor(new CatalogResponse(new CatalogResult), 'itemCursor');
        }
    };
    $done->finish();

    expect(Dispatcher::create(SyncCatalogJob::class)->getSyncTask())->toBeNull();
});

it('resolves the square sdk for sandbox and production', function(): void {
    (new Extension(app()))->register();
    Square::configureSquare(['environment' => 'sandbox']);
    app()->forgetInstance(\Square\SquareClient::class);
    app()->forgetInstance(SquareClient::class);

    expect(resolve(\Square\SquareClient::class))->toBeInstanceOf(\Square\SquareClient::class)
        ->and(resolve(SquareClient::class))->toBeInstanceOf(SquareClient::class);

    Settings::set('environment', 'production');
    app()->forgetInstance(\Square\SquareClient::class);

    expect(resolve(\Square\SquareClient::class))->toBeInstanceOf(\Square\SquareClient::class);
});

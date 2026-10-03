<?php

use Igniter\Cart\Models\Stock;
use Igniter\Local\Models\Location;
use IgniterLabs\SquarePOS\Jobs\PullStocksJob;
use IgniterLabs\SquarePOS\Jobs\PushStocksJob;
use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use IgniterLabs\SquarePOS\Jobs\SyncInventoryJob;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SquareStock;
use IgniterLabs\SquarePOS\Tests\Support\Square;

beforeEach(function(): void {
    Square::configureSquare(['sync_inventory' => true, 'source' => Settings::IGNITER_SOR]);
    $this->square = Square::bindSquare();
    $this->location = Square::mapLocation();
    $this->menu = Square::makeMenu();
    Square::linkOwner($this->menu, [
        'square_id' => 'ITEM',
        'square_variation_id' => 'VAR',
        'square_stock_mode' => 'push',
    ]);
    $this->stock = Stock::factory()->create([
        'stockable_type' => $this->menu->getMorphClass(),
        'stockable_id' => $this->menu->getKey(),
        'location_id' => $this->location->getKey(),
        'quantity' => 8,
        'is_tracked' => false,
        'updated_at' => now(),
    ]);
});

it('does nothing when inventory sync is disabled', function(): void {
    $task = Square::runPipe(PullStocksJob::class, ['syncInventory' => false]);
    Square::runPipe(PushStocksJob::class, ['syncInventory' => false]);

    expect($task->notes)->toBeNull()
        ->and($this->stock->fresh()->quantity)->toBe(8);
});

it('skips a pull when no locations are mapped', function(): void {
    Settings::set('location_mappings', []);

    $task = Square::runPipe(PullStocksJob::class, ['syncInventory' => true]);

    expect($task->notes[0])->toContain('no locations');
});

it('applies in-stock counts and ignores sold or unknown locations', function(): void {
    $this->menu->squareLink->square_stock_mode = null;
    $this->menu->squareLink->save();
    $this->square->counting([
        Square::inventoryCount('VAR', 'LOC1', '5'),
        Square::inventoryCount('VAR', 'LOC1', '1', 'SOLD'),
        Square::inventoryCount('MISSING', 'NOWHERE', '1'),
    ]);

    Square::runPipe(PullStocksJob::class, ['syncInventory' => true], Settings::SQUARE_SOR);

    expect((int)$this->stock->fresh()->quantity)->toBe(5);
});

it('does not write the same quantity twice', function(): void {
    $this->menu->squareLink->square_stock_mode = null;
    $this->menu->squareLink->save();
    $this->stock->quantity = 5;
    $this->stock->save();
    $this->square->counting([Square::inventoryCount('VAR', 'LOC1', '5')]);

    Square::runPipe(PullStocksJob::class, ['syncInventory' => true], Settings::SQUARE_SOR);

    expect((int)$this->stock->fresh()->quantity)->toBe(5);
});

it('pulls square stock over a local quantity', function(): void {
    $this->menu->squareLink->square_stock_mode = 'square';
    $this->menu->squareLink->save();
    $this->stock->quantity = 1;
    $this->stock->save();
    $this->square->counting([Square::inventoryCount('VAR', 'LOC1', '5')]);

    Square::runPipe(PullStocksJob::class, ['syncInventory' => true]);

    expect((int)$this->stock->fresh()->quantity)->toBe(5);
});

it('keeps a newer local count when tastyigniter is the authority', function(): void {
    $this->menu->squareLink->square_stock_mode = '';
    $this->menu->squareLink->save();
    SquareStock::forOwner($this->stock->fresh())->forceFill(['square_synced_at' => now()->subMinute()])->save();
    $this->stock->updated_at = now();
    $this->stock->save();
    $this->square->counting([Square::inventoryCount('VAR', 'LOC1', '5')]);

    Square::runPipe(PullStocksJob::class, ['syncInventory' => true], Settings::IGNITER_SOR);

    expect((int)$this->stock->fresh()->quantity)->toBe(8);
});

it('pushes a physical count for a tracked variation', function(): void {
    $this->menu->squareLink->square_stock_mode = 'push';
    $this->menu->squareLink->square_sync = true;
    $this->menu->squareLink->save();

    Square::runPipe(PushStocksJob::class, ['syncInventory' => true]);

    $this->square->shouldHaveReceived('batchChangeInventory');
});

it('skips a push when the menu has no variation id', function(): void {
    $this->menu->squareLink->square_variation_id = null;
    $this->menu->squareLink->square_sync = true;
    $this->menu->squareLink->save();

    Square::runPipe(PushStocksJob::class, ['syncInventory' => true]);

    $this->square->shouldNotHaveReceived('batchChangeInventory');
});

it('marks an inventory job complete when sync is disabled', function(): void {
    $task = Square::makeSyncTask(['syncInventory' => false]);

    (new SyncInventoryJob($task))->handle();

    expect($task->fresh()->status)->toBe('success')
        ->and($task->fresh()->finished_at)->not->toBeNull();
});

it('records a catalog search failure on the sync task', function(): void {
    $this->square->shouldReceive('searchCatalogObjects')->andThrow(new RuntimeException('search down'));
    $task = Square::makeSyncTask(['mode' => 'import', 'syncInventory' => true]);

    (new SyncCatalogJob($task))->handle();

    expect($task->fresh()->exception['message'])->toBe('search down');
});

it('rethrows an inventory pull failure and mails it', function(): void {
    Settings::set('sync_notify_email', 'ops@example.com');
    $this->menu->squareLink->square_variation_id = 'VAR';
    $this->menu->squareLink->square_sync = true;
    $this->menu->squareLink->save();
    $this->square->shouldReceive('batchRetrieveInventoryCounts')->andThrow(new RuntimeException('counts down'));

    expect(fn() => (new SyncInventoryJob(Square::makeSyncTask(['syncInventory' => true])))->handle())
        ->toThrow(RuntimeException::class);
});

it('does not push stock for a menu excluded from the location', function(): void {
    $other = Location::factory()->create();
    $this->menu->locations()->sync([$other->getKey()]);
    $this->menu->squareLink->square_sync = false;
    $this->menu->squareLink->save();
    $unmapped = Stock::factory()->create([
        'stockable_type' => $this->menu->getMorphClass(),
        'stockable_id' => $this->menu->getKey(),
        'location_id' => $other->getKey(),
        'quantity' => 1,
        'is_tracked' => true,
    ]);

    expect($this->stock->getSquareId())->toStartWith('#temp_')
        ->and($unmapped->asSquareInventoryChange())->toBeNull();

    Square::runPipe(PushStocksJob::class, ['syncInventory' => true]);
    $this->square->shouldNotHaveReceived('batchChangeInventory');
});

it('skips stock that square owns or that sits outside the menu locations', function(): void {
    $this->menu->squareLink->square_sync = true;
    $this->menu->squareLink->square_stock_mode = 'square';
    $this->menu->squareLink->save();
    Square::runPipe(PushStocksJob::class, ['syncInventory' => true], Settings::SQUARE_SOR);

    $this->menu->squareLink->square_stock_mode = '';
    $this->menu->squareLink->save();
    Square::runPipe(PushStocksJob::class, ['syncInventory' => true], Settings::SQUARE_SOR);

    $other = Location::factory()->create();
    $this->menu->locations()->sync([$other->getKey()]);
    $this->menu->squareLink->square_stock_mode = 'push';
    $this->menu->squareLink->save();
    Square::runPipe(PushStocksJob::class, ['syncInventory' => true]);

    SquareStock::forOwner($this->stock->fresh())->forceFill(['square_synced_at' => now()->subMinute()])->save();
    $this->stock->updated_at = now();
    $this->stock->save();
    $this->menu->locations()->sync([$this->location->getKey()]);

    Square::runPipe(PushStocksJob::class, ['syncInventory' => true]);

    $this->square->shouldHaveReceived('batchChangeInventory');
});

it('pushes once the menu is sold at the mapped location', function(): void {
    $this->menu->squareLink->square_sync = true;
    $this->menu->squareLink->square_stock_mode = 'push';
    $this->menu->squareLink->save();
    $this->menu->locations()->sync([$this->location->getKey()]);

    Square::runPipe(PushStocksJob::class, ['syncInventory' => true]);

    $this->square->shouldHaveReceived('batchChangeInventory');
});

it('creates local stock from a square count', function(): void {
    $menu = Square::makeMenu(['menu_name' => 'Unstocked']);
    Square::linkOwner($menu, ['square_variation_id' => 'VAR-NEW', 'square_sync' => true, 'square_stock_mode' => 'square']);
    $this->square->counting([Square::inventoryCount('VAR-NEW', 'LOC1', '2')]);

    Square::runPipe(PullStocksJob::class, ['syncInventory' => true], Settings::SQUARE_SOR);

    expect(Stock::query()->where('stockable_id', $menu->getKey())->value('quantity'))->toBe(2);
});

it('leaves a locally dirty push-mode stock unchanged', function(): void {
    $menu = Square::makeMenu(['menu_name' => 'Dirty stock']);
    Square::linkOwner($menu, ['square_variation_id' => 'VAR-DIRTY', 'square_sync' => true, 'square_stock_mode' => 'push']);
    Stock::factory()->create([
        'stockable_type' => $menu->getMorphClass(),
        'stockable_id' => $menu->getKey(),
        'location_id' => $this->location->getKey(),
        'quantity' => 3,
        'is_tracked' => true,
    ]);
    $this->square->counting([Square::inventoryCount('VAR-DIRTY', 'LOC1', '9')]);

    Square::runPipe(PullStocksJob::class, ['syncInventory' => true]);

    expect(Stock::query()->where('stockable_id', $menu->getKey())->value('quantity'))->toBe(3);
});

it('creates stock for a variation that has none yet', function(): void {
    $menu = Square::makeMenu(['menu_name' => 'No stock']);
    Square::linkOwner($menu, ['square_variation_id' => 'VAR-NONE', 'square_sync' => true, 'square_stock_mode' => 'push']);
    $this->square->counting([Square::inventoryCount('VAR-NONE', 'LOC1', '4')]);

    Square::runPipe(PullStocksJob::class, ['syncInventory' => true]);

    expect(Stock::query()->where('stockable_id', $menu->getKey())->value('quantity'))->toBe(4);
});

it('pulls nothing when no menu is linked', function(): void {
    $this->menu->squareLink()->delete();

    $task = Square::runPipe(PullStocksJob::class, ['syncInventory' => true]);

    expect((int)$this->stock->fresh()->quantity)->toBe(8);
});

it('completes an inventory job after a pull', function(): void {
    $this->square->counting([Square::inventoryCount('VAR', 'LOC1', '5')]);
    $task = Square::makeSyncTask(['syncInventory' => true]);

    (new SyncInventoryJob($task))->handle();

    expect($task->fresh()->status)->toBe('success')
        ->and($task->fresh()->finished_at)->not->toBeNull();
});

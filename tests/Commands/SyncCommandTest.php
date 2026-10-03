<?php

use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Tests\Support\Square;
use Illuminate\Support\Facades\Artisan;

it('runs catalog sync and order sync from the command', function(): void {
    Square::configureSquare(['sync_orders' => true, 'sync_notify_email' => null]);
    Square::bindSquare();

    expect(Artisan::call('square:sync'))->toBe(0);
    expect(Artisan::output())->toContain('Sync complete');
});

it('limits the command to the syncs named by flags', function(): void {
    Square::configureSquare(['sync_orders' => true, 'sync_inventory' => false, 'sync_notify_email' => null]);
    Square::bindSquare();

    Artisan::call('square:sync', ['--menus' => true]);
    $menus = Artisan::output();
    expect($menus)->toContain('Pushing catalog into Square')
        ->not->toContain('Pushing orders into Square');

    Artisan::call('square:sync', ['--orders' => true]);
    $orders = Artisan::output();
    expect($orders)->toContain('Pushing orders into Square')
        ->toContain('Pushing TastyIgniter orders to Square')
        ->not->toContain('Pushing catalog into Square');

    Artisan::call('square:sync', ['--inventory' => true]);
    $inventory = Artisan::output();
    expect($inventory)->toContain('Inventory sync is disabled')
        ->not->toContain('Pushing catalog into Square');
});

it('prints a sync failure from the command', function(): void {
    Square::configureSquare(['sync_orders' => false, 'source' => Settings::SQUARE_SOR]);
    $client = Square::bindSquare();
    $client->shouldReceive('searchCatalogObjects')->andThrow(new RuntimeException('search down'));

    Artisan::call('square:sync');

    expect(Artisan::output())->toContain('search down');
    expect(Settings::isConnected())->toBeTrue();
});

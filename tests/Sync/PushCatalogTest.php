<?php

use Igniter\Cart\Models\Category;
use Igniter\Cart\Models\MenuOption;
use Igniter\Cart\Models\MenuOptionValue;
use Igniter\Cart\Models\Stock;
use IgniterLabs\SquarePOS\Classes\CatalogImageUploader;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Jobs\PushCategoriesJob;
use IgniterLabs\SquarePOS\Jobs\PushItemOptionsJob;
use IgniterLabs\SquarePOS\Jobs\PushItemsJob;
use IgniterLabs\SquarePOS\Tests\Support\Square;
use Square\Types\CatalogCategory;
use Square\Types\CatalogIdMapping;
use Square\Types\CatalogItem;
use Square\Types\CatalogItemVariation;
use Square\Types\CatalogModifierList;

beforeEach(function(): void {
    Square::configureSquare();
    $this->square = Square::bindSquare();
    $this->location = Square::mapLocation();
});

it('pushes categories, modifier lists, and items', function(): void {
    $category = Category::factory()->create(['name' => 'Sides', 'status' => true]);
    $category->locations()->sync([$this->location->getKey()]);

    $option = MenuOption::factory()->create([
        'option_name' => 'Spice',
        'display_type' => 'checkbox',
        'priority' => 1,
    ]);
    $option->locations()->sync([$this->location->getKey()]);
    $value = MenuOptionValue::factory()->create([
        'option_id' => $option->getKey(),
        'name' => 'Hot',
        'price' => 1,
        'priority' => 2,
    ]);

    $menu = Square::makeMenu();
    $menu->categories()->sync([$category->getKey()]);
    $menu->locations()->sync([$this->location->getKey()]);
    Square::linkOwner($category, ['square_id' => 'CAT1']);
    $itemOption = $menu->menu_options()->create([
        'option_id' => $option->getKey(),
        'min_selected' => 0,
        'max_selected' => 2,
    ]);
    $itemOption->menu_option_values()->create([
        'option_value_id' => $value->getKey(),
        'priority' => 1,
    ]);
    $stock = Stock::factory()->create([
        'stockable_type' => $menu->getMorphClass(),
        'stockable_id' => $menu->getKey(),
        'location_id' => $this->location->getKey(),
        'quantity' => 3,
        'is_tracked' => true,
        'low_stock_alert' => true,
        'low_stock_threshold' => 1,
    ]);

    $task = Square::makeSyncTask(['mode' => 'export']);
    (new PushCategoriesJob($task))->handle();
    (new PushItemOptionsJob($task))->handle();
    (new PushItemsJob($task))->handle();

    expect($category->fresh()->squareLink->square_id)->not->toBeNull()
        ->and($option->fresh()->squareLink->square_id)->not->toBeNull()
        ->and($value->fresh()->squareLink->square_id)->not->toBeNull()
        ->and($menu->fresh()->squareLink->square_id)->not->toBeNull()
        ->and($menu->asSquareItem()->getType())->toBe('ITEM')
        ->and($option->asSquareModifierList()->getType())->toBe('MODIFIER_LIST')
        ->and($value->asSquareModifier()->getType())->toBe('MODIFIER')
        ->and($stock->asSquareInventoryChange())->not->toBeNull();
});

it('skips a category whose remote version is unchanged', function(): void {
    $category = Category::factory()->create(['name' => 'Sides', 'status' => true]);
    Square::linkOwner($category, ['square_id' => 'CAT1']);
    Square::runPipe(PushCategoriesJob::class, ['mode' => 'export']);

    $remoteId = $category->fresh()->squareLink->square_id;
    $this->square->retrieving([
        $remoteId => Square::squareObject('CATEGORY', $remoteId, ['version' => 11]),
    ]);

    Square::runPipe(PushCategoriesJob::class, ['mode' => 'export']);

    expect($category->fresh()->squareLink->square_version)->toEqual(11);
});

it('skips clean records and replaces a category square has forgotten', function(): void {
    $data = new CatalogCategory(['name' => 'Clean']);
    $clean = Category::factory()->create(['name' => 'Clean', 'status' => true]);
    Square::linkOwner($clean, ['square_id' => 'CAT-CLEAN', 'square_version' => 11, 'square_dirty' => false]);
    $dirty = Category::factory()->create(['name' => 'Dirty', 'status' => true]);
    Square::linkOwner($dirty, ['square_id' => 'CAT-DIRTY', 'square_version' => 11, 'square_dirty' => true]);
    $missing = Category::factory()->create(['name' => 'Missing', 'status' => true]);
    Square::linkOwner($missing, ['square_id' => 'CAT-GONE', 'square_version' => 4]);

    $this->square->retrieving([
        'CAT-CLEAN' => Square::squareObject('CATEGORY', 'CAT-CLEAN', ['version' => 11, 'categoryData' => $data]),
        'CAT-DIRTY' => Square::squareObject('CATEGORY', 'CAT-DIRTY', ['version' => 11, 'categoryData' => $data]),
    ]);

    Square::runPipe(PushCategoriesJob::class);

    expect($clean->fresh()->squareLink->square_version)->toEqual(11)
        ->and($missing->fresh()->squareLink->square_id)->not->toBe('CAT-GONE');
});

it('keeps a clean item and still pushes one whose variation changed', function(): void {
    $menu = Square::makeMenu();
    $menu->locations()->sync([$this->location->getKey()]);
    Square::linkOwner($menu, [
        'square_id' => 'ITEM-CLEAN',
        'square_version' => 11,
        'square_variation_id' => 'VAR-OLD',
        'square_variation_version' => 2,
        'square_sku' => 'SKU-KEEP',
        'square_image_id' => 'IMG-KEEP',
        'square_dirty' => false,
    ]);
    $plain = MenuOption::factory()->create(['option_name' => 'Plain', 'display_type' => 'radio']);
    $menu->menu_options()->create(['option_id' => $plain->getKey(), 'min_selected' => 0, 'max_selected' => 1]);
    $bare = Square::makeMenu(['menu_name' => 'Bare']);
    Square::linkOwner($bare, ['square_id' => 'ITEM-BARE', 'square_version' => 1, 'square_variation_id' => 'VAR-BARE']);

    expect($menu->getSquareVariationId())->toBe('VAR-OLD')
        ->and($menu->squareSku())->toBe('SKU-KEEP')
        ->and(Square::squareRecord($menu->asSquareItem())->getItemData()->getImageIds())->toBe(['IMG-KEEP'])
        ->and($menu->squareLocationRemoteIds())->not->toBeEmpty()
        ->and($bare->squareLocationRemoteId())->toBeNull();

    app()->instance(CatalogImageUploader::class, new class extends CatalogImageUploader
    {
        public function pushMenuImage($square, $menu): void
        {
            throw new RuntimeException('image down');
        }
    });
    $this->square->retrieving([
        'ITEM-CLEAN' => Square::squareObject('ITEM', 'ITEM-CLEAN', [
            'version' => 11,
            'itemData' => new CatalogItem([
                'name' => 'AMALA',
                'variations' => [
                    Square::squareObject('ITEM_VARIATION', 'VAR-NEW', [
                        'version' => 9,
                        'itemVariationData' => new CatalogItemVariation([
                            'name' => 'AMALA',
                            'pricingType' => 'FIXED_PRICING',
                            'priceMoney' => Square::squareMoney(),
                        ]),
                    ]),
                ],
            ]),
        ]),
        'ITEM-BARE' => Square::squareObject('ITEM', 'ITEM-BARE', [
            'version' => 1,
            'itemData' => new CatalogItem,
        ]),
    ]);

    Square::runPipe(PushItemsJob::class);

    expect($menu->fresh()->squareLink->square_id)->toStartWith('SQ');
});

it('skips a clean modifier list', function(): void {
    $option = MenuOption::factory()->create(['option_name' => 'Spice', 'display_type' => 'checkbox']);
    $option->locations()->sync([$this->location->getKey()]);
    Square::linkOwner($option, ['square_id' => 'LIST-CLEAN', 'square_version' => 11, 'square_dirty' => false]);
    MenuOptionValue::factory()->create(['option_id' => $option->getKey(), 'name' => 'Hot', 'price' => 1]);

    $this->square->retrieving([
        'LIST-CLEAN' => Square::squareObject('MODIFIER_LIST', 'LIST-CLEAN', [
            'version' => 11,
            'modifierListData' => new CatalogModifierList(['name' => 'Spice']),
        ]),
    ]);

    Square::runPipe(PushItemOptionsJob::class);

    expect($option->fresh()->squareLink->square_version)->toEqual(11);
});

it('stores the remote list id and skips a modifier the response omits', function(): void {
    $option = MenuOption::factory()->create(['option_name' => 'Spice', 'display_type' => 'checkbox']);
    $option->locations()->sync([$this->location->getKey()]);
    Square::linkOwner($option, ['square_id' => 'LIST-CLEAN', 'square_version' => 11, 'square_dirty' => false]);
    $value = MenuOptionValue::factory()->create(['option_id' => $option->getKey(), 'name' => 'Hot', 'price' => 1]);
    Square::linkOwner($value, ['square_dirty' => true]);
    $miss = MenuOptionValue::factory()->create(['option_id' => $option->getKey(), 'name' => 'Miss', 'price' => 1]);
    Square::linkOwner($miss, ['square_id' => 'MOD-MISSING']);

    $client = Mockery::mock(SquareClient::class);
    $client->shouldReceive('batchRetrieveCatalogObjects')->andReturn(Square::catalogResponse([
        Square::squareObject('MODIFIER_LIST', 'LIST-CLEAN', [
            'version' => 11,
            'modifierListData' => new CatalogModifierList,
        ]),
    ]));
    $client->shouldReceive('batchUpsertCatalogObjects')->andReturn(Square::catalogResponse([
        Square::squareObject('MODIFIER_LIST', 'LIST-REMOTE', [
            'version' => 4,
            'modifierListData' => new CatalogModifierList,
        ]),
    ], [
        new CatalogIdMapping(['clientObjectId' => 'LIST-CLEAN', 'objectId' => 'LIST-REMOTE']),
        new CatalogIdMapping(['clientObjectId' => $miss->getSquareId(), 'objectId' => 'MOD-REMOTE']),
    ]));
    app()->instance(SquareClient::class, $client);

    Square::runPipe(PushItemOptionsJob::class);

    expect($option->fresh()->squareLink->square_id)->toBe('LIST-REMOTE')
        ->and($miss->fresh()->squareLink->square_version)->toBeNull();
});

it('leaves local ids in place when the upsert response is empty', function(): void {
    $category = Category::factory()->create(['name' => 'Orphan']);
    Square::linkOwner($category, ['square_id' => 'CAT-ORPHAN']);
    $option = MenuOption::factory()->create(['option_name' => 'Spice', 'display_type' => 'checkbox']);
    Square::linkOwner($option, ['square_id' => 'LIST-ORPHAN']);
    $menu = Square::makeMenu();
    Square::linkOwner($menu, ['square_id' => 'ITEM-ORPHAN']);

    $client = Mockery::mock(SquareClient::class);
    $client->shouldReceive('batchUpsertCatalogObjects')->andReturn(Square::catalogResponse());
    $client->shouldReceive('batchRetrieveCatalogObjects')->andReturn(Square::catalogResponse());
    app()->instance(SquareClient::class, $client);

    Square::runPipe(PushCategoriesJob::class);
    Square::runPipe(PushItemOptionsJob::class);
    Square::runPipe(PushItemsJob::class);

    expect($category->fresh()->squareLink->square_id)->toBe('CAT-ORPHAN')
        ->and($option->fresh()->squareLink->square_id)->toBe('LIST-ORPHAN')
        ->and($menu->fresh()->squareLink->square_id)->toBe('ITEM-ORPHAN');
});

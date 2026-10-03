<?php

use Igniter\Cart\Models\Category;
use Igniter\Cart\Models\Menu;
use Igniter\Cart\Models\MenuOption;
use Igniter\Cart\Models\MenuOptionValue;
use IgniterLabs\SquarePOS\Jobs\PullCategoriesJob;
use IgniterLabs\SquarePOS\Jobs\PullItemOptionsJob;
use IgniterLabs\SquarePOS\Jobs\PullItemsJob;
use IgniterLabs\SquarePOS\Models\SquareLink;
use IgniterLabs\SquarePOS\Tests\Support\Square;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Square\Types\CatalogCategory;
use Square\Types\CatalogImage;
use Square\Types\CatalogItem;
use Square\Types\CatalogItemModifierListInfo;
use Square\Types\CatalogModifier;
use Square\Types\CatalogModifierList;
use Square\Types\CatalogObjectCategory;
use Square\Types\ItemVariationLocationOverrides;

beforeEach(function(): void {
    Square::configureSquare();
    $this->square = Square::bindSquare();
    $this->location = Square::mapLocation();
});

it('imports a new category and disables a deleted one', function(): void {
    $deleted = Square::squareObject('CATEGORY', 'CAT-DEL', [
        'isDeleted' => true,
        'categoryData' => new CatalogCategory(['name' => 'Gone']),
    ]);
    $local = Category::factory()->create(['name' => 'Gone', 'status' => true]);
    Square::linkOwner($local, ['square_id' => 'CAT-DEL']);
    $fresh = Square::squareObject('CATEGORY', 'CAT-NEW', [
        'version' => 1,
        'presentAtAllLocations' => false,
        'presentAtLocationIds' => ['LOC1'],
        'categoryData' => new CatalogCategory(['name' => 'Imported']),
    ]);

    $this->square->search(
        Square::catalogResponse([$deleted, $fresh], [], 'page-2'),
        Square::catalogResponse(),
    );

    Square::runPipe(PullCategoriesJob::class, ['mode' => 'import']);

    expect(SquareLink::findOwner(Category::class, 'CAT-NEW'))->not->toBeNull()
        ->and($local->fresh()->status)->toBeFalsy();
});

it('deletes a local category when square deleted it', function(): void {
    Square::configureSquare(['handle_missing' => 'delete']);
    $local = Category::factory()->create(['name' => 'Gone', 'status' => true]);
    Square::linkOwner($local, ['square_id' => 'CAT-DEL']);
    $this->square->search(Square::catalogResponse([
        Square::squareObject('CATEGORY', 'CAT-DEL', [
            'isDeleted' => true,
            'categoryData' => new CatalogCategory(['name' => 'Gone']),
        ]),
    ]));

    Square::runPipe(PullCategoriesJob::class, ['mode' => 'import']);

    expect($local->fresh())->toBeNull();
});

it('ignores a deleted category that was never imported', function(): void {
    $this->square->search(Square::catalogResponse([
        Square::squareObject('CATEGORY', 'CAT-UNKNOWN', ['isDeleted' => true]),
    ]));

    Square::runPipe(PullCategoriesJob::class, ['mode' => 'import']);

    expect(SquareLink::findOwner(Category::class, 'CAT-UNKNOWN'))->toBeNull();
});

it('imports modifier lists and disables a deleted one', function(): void {
    $modifier = Square::squareObject('MODIFIER', 'MOD1', [
        'version' => 1,
        'modifierData' => new CatalogModifier([
            'name' => 'Mild',
            'ordinal' => 1,
            'priceMoney' => Square::squareMoney(50),
        ]),
    ]);
    $list = Square::squareObject('MODIFIER_LIST', 'LIST1', [
        'version' => 4,
        'presentAtAllLocations' => true,
        'modifierListData' => new CatalogModifierList([
            'name' => 'Heat',
            'selectionType' => 'SINGLE',
            'ordinal' => 3,
            'modifiers' => [$modifier, Square::squareObject('MODIFIER', 'MOD-BLANK')],
        ]),
    ]);
    $existing = MenuOption::factory()->create(['option_name' => 'Removed', 'display_type' => 'radio']);
    Square::linkOwner($existing, ['square_id' => 'LIST-DEL']);

    $this->square->search(Square::catalogResponse([
        $list,
        Square::squareObject('MODIFIER_LIST', 'LIST-DEL', [
            'isDeleted' => true,
            'modifierListData' => new CatalogModifierList(['name' => 'Removed']),
        ]),
        Square::squareObject('ITEM', 'SKIP'),
    ]));

    Square::runPipe(PullItemOptionsJob::class, ['mode' => 'import']);

    expect(SquareLink::findOwner(MenuOption::class, 'LIST1'))->not->toBeNull()
        ->and($existing->fresh())->toBeNull();
});

it('imports a modifier list that has no modifiers', function(): void {
    $this->square->search(Square::catalogResponse([
        Square::squareObject('MODIFIER_LIST', 'LIST-NULL', [
            'presentAtAllLocations' => true,
            'modifierListData' => new CatalogModifierList(['name' => 'Plain']),
        ]),
    ]));

    Square::runPipe(PullItemOptionsJob::class, ['mode' => 'import']);

    expect(SquareLink::findOwner(MenuOption::class, 'LIST-NULL')->option_name)->toBe('Plain');
});

it('replaces modifier values that square no longer sends', function(): void {
    $option = MenuOption::factory()->create(['option_name' => 'Size', 'display_type' => 'checkbox']);
    Square::linkOwner($option, ['square_id' => 'LIST-SIZE']);
    $stale = MenuOptionValue::factory()->create(['option_id' => $option->getKey(), 'name' => 'Old', 'price' => 1]);
    Square::linkOwner($stale, ['square_id' => 'MOD-OLD']);

    $this->square->search(Square::catalogResponse([
        Square::squareObject('MODIFIER_LIST', 'LIST-SIZE', [
            'presentAtAllLocations' => true,
            'modifierListData' => new CatalogModifierList([
                'name' => 'Size',
                'selectionType' => 'MULTIPLE',
                'modifiers' => [
                    Square::squareObject('MODIFIER', 'MOD-LARGE', [
                        'modifierData' => new CatalogModifier(['name' => 'Large', 'ordinal' => 1]),
                    ]),
                ],
            ]),
        ]),
        Square::squareObject('MODIFIER_LIST', 'LIST-EMPTY'),
    ]));

    Square::runPipe(PullItemOptionsJob::class, ['mode' => 'import']);

    expect($stale->fresh())->toBeNull()
        ->and($option->fresh()->option_values()->where('name', 'Large')->exists())->toBeTrue();
});

it('imports an item with its category, modifiers, stock, and image', function(): void {
    $category = Category::factory()->create(['name' => 'Mains', 'status' => true]);
    Square::linkOwner($category, ['square_id' => 'CAT-MAIN']);
    $option = MenuOption::factory()->create(['option_name' => 'Size', 'display_type' => 'radio']);
    Square::linkOwner($option, ['square_id' => 'LIST-SIZE']);
    $optionValue = MenuOptionValue::factory()->create([
        'option_id' => $option->getKey(),
        'name' => 'Large',
        'price' => 2,
        'priority' => 1,
    ]);
    $checkbox = MenuOption::factory()->create(['option_name' => 'Extras', 'display_type' => 'checkbox']);
    Square::linkOwner($checkbox, ['square_id' => 'LIST-EXTRA']);
    MenuOptionValue::factory()->create(['option_id' => $checkbox->getKey(), 'name' => 'Cheese', 'price' => 1]);

    $menu = Square::makeMenu(['menu_name' => 'Existing', 'menu_price' => 1]);
    Square::linkOwner($menu, ['square_sku' => 'SKU-EXISTING', 'square_sync' => false]);
    $kept = $menu->menu_options()->create(['option_id' => $option->getKey(), 'min_selected' => 0, 'max_selected' => 1]);

    $item = Square::squareCatalogItem('ITEM-1', 'Imported', 'SKU-NEW');
    Square::squareRecord($item)->getItemData()->setCategoryId('CAT-LEGACY');
    Square::squareRecord($item)->getItemData()->setCategories([
        new CatalogObjectCategory(['id' => 'CAT-MAIN']),
        'not-an-object',
        new stdClass,
    ]);
    Square::squareRecord($item)->getItemData()->setModifierListInfo([
        new CatalogItemModifierListInfo([
            'modifierListId' => 'LIST-SIZE',
            'enabled' => true,
            'minSelectedModifiers' => 2,
            'maxSelectedModifiers' => 0,
        ]),
        new CatalogItemModifierListInfo([
            'modifierListId' => 'LIST-EXTRA',
            'enabled' => false,
            'minSelectedModifiers' => 0,
            'maxSelectedModifiers' => 3,
        ]),
        new CatalogItemModifierListInfo([
            'modifierListId' => 'LIST-MISSING',
            'enabled' => true,
        ]),
    ]);
    Square::squareRecord($item)->getItemData()->setImageIds(['IMG']);
    Square::squareRecord($item)->setPresentAtAllLocations(false);
    Square::squareRecord($item)->setPresentAtLocationIds(['LOC1']);
    Square::squareRecord(Square::squareRecord($item)->getItemData()->getVariations()[0])->getItemVariationData()->setLocationOverrides([
        new ItemVariationLocationOverrides([
            'locationId' => 'LOC1',
            'trackInventory' => true,
            'inventoryAlertType' => 'LOW_QUANTITY',
            'inventoryAlertThreshold' => 2,
        ]),
        new ItemVariationLocationOverrides(['locationId' => 'OTHER']),
    ]);
    Http::fake(['https://example.test/item.jpg' => Http::response('image-bytes', 200)]);

    $deleted = Square::squareCatalogItem('ITEM-DEL', 'Deleted', 'SKU-DEL');
    Square::squareRecord($deleted)->setIsDeleted(true);
    $linked = Square::makeMenu(['menu_name' => 'Delete me']);
    Square::linkOwner($linked, ['square_id' => 'ITEM-DEL']);

    $this->square->search(Square::catalogResponse(
        [$item, $deleted, Square::squareObject('CATEGORY', 'NOPE')],
        [],
        null,
        [Square::squareObject('IMAGE', 'IMG', ['imageData' => new CatalogImage(['url' => 'https://example.test/item.jpg'])])],
    ));

    Square::runPipe(PullItemsJob::class, ['mode' => 'import', 'createMissing' => true]);

    expect(SquareLink::findOwner(Menu::class, 'ITEM-1'))->not->toBeNull()
        ->and($linked->fresh()->menu_status)->toBeFalsy()
        ->and($optionValue->fresh())->not->toBeNull()
        ->and($kept->fresh())->not->toBeNull();
});

it('deletes a menu when the square item is deleted', function(): void {
    Square::configureSquare(['handle_missing' => 'delete']);
    $linked = Square::makeMenu(['menu_name' => 'Delete me']);
    Square::linkOwner($linked, ['square_id' => 'ITEM-DEL']);
    $deleted = Square::squareCatalogItem('ITEM-DEL', 'Deleted', 'SKU-DEL');
    Square::squareRecord($deleted)->setIsDeleted(true);
    $this->square->search(Square::catalogResponse([$deleted]));

    Square::runPipe(PullItemsJob::class, ['mode' => 'import', 'createMissing' => true]);

    expect($linked->fresh())->toBeNull();
});

it('imports items without a fixed price', function(): void {
    $this->square->search(Square::catalogResponse([
        Square::squareObject('ITEM', 'ITEM-NONE', ['itemData' => new CatalogItem(['name' => 'Bare'])]),
        Square::squareCatalogItem('ITEM-VAR', 'Variable', null, 'VARIABLE_PRICING'),
    ]));

    Square::runPipe(PullItemsJob::class, ['mode' => 'import', 'createMissing' => true]);

    expect(SquareLink::findOwner(Menu::class, 'ITEM-NONE'))->toBeNull()
        ->and(SquareLink::findOwner(Menu::class, 'ITEM-VAR'))->toBeNull();
});

it('adopts a menu that already uses the incoming sku', function(): void {
    $option = MenuOption::factory()->create(['option_name' => 'Size', 'display_type' => 'radio']);
    Square::linkOwner($option, ['square_id' => 'LIST-SIZE']);
    $menu = Square::makeMenu(['menu_name' => 'Existing']);
    Square::linkOwner($menu, ['square_sku' => 'SKU-EXISTING', 'square_sync' => false]);
    $kept = $menu->menu_options()->create(['option_id' => $option->getKey(), 'min_selected' => 0, 'max_selected' => 1]);
    $this->square->search(Square::catalogResponse([
        Square::squareCatalogItem('ITEM-1', 'Imported', 'SKU-EXISTING'),
    ]));

    Square::runPipe(PullItemsJob::class, ['mode' => 'import', 'createMissing' => true]);

    expect($menu->fresh()->squareLink->square_id)->toBe('ITEM-1')
        ->and($kept->fresh())->toBeNull();
});

it('skips an item whose sku already belongs to another square item', function(): void {
    $menu = Square::makeMenu();
    Square::linkOwner($menu, ['square_id' => 'ITEM-OWNED', 'square_sku' => 'SKU-OWNED', 'square_sync' => false]);
    $this->square->search(Square::catalogResponse([
        Square::squareCatalogItem('ITEM-OTHER', 'Other', 'SKU-OWNED'),
    ]));

    Square::runPipe(PullItemsJob::class, ['mode' => 'import', 'createMissing' => true]);

    expect(SquareLink::findOwner(Menu::class, 'ITEM-OTHER'))->toBeNull()
        ->and($menu->fresh()->squareLink->square_id)->toBe('ITEM-OWNED');
});

it('does not import an item whose sync is turned off', function(): void {
    $linked = Square::makeMenu(['menu_name' => 'Off']);
    Square::linkOwner($linked, ['square_id' => 'ITEM-OFF', 'square_sync' => false, 'square_sku' => 'SKU-OFF']);
    $this->square->search(Square::catalogResponse([
        Square::squareCatalogItem('ITEM-OFF', 'Off', 'SKU-OFF'),
    ]));

    Square::runPipe(PullItemsJob::class, ['mode' => 'import', 'createMissing' => false]);

    expect($linked->fresh()->menu_name)->toBe('Off');
});

it('imports descriptions and images, including empty image bodies', function(): void {
    config([
        'igniter-system.assets.attachment.disk' => 'square-media',
        'filesystems.disks.square-media' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/square-media')],
    ]);
    Storage::fake('square-media');

    $existing = Square::makeMenu(['menu_description' => 'keep me']);
    Square::linkOwner($existing, ['square_id' => 'ITEM-BARE']);
    $described = Square::squareCatalogItem('ITEM-TEXT', 'Described', 'SKU-TEXT');
    Square::squareRecord($described)->getItemData()->setDescriptionPlaintext('');
    Square::squareRecord($described)->getItemData()->setDescription('from html');

    $checkbox = MenuOption::factory()->create(['option_name' => 'Extras', 'display_type' => 'checkbox']);
    Square::linkOwner($checkbox, ['square_id' => 'LIST-BOX']);
    MenuOptionValue::factory()->create(['option_id' => $checkbox->getKey(), 'name' => 'Cheese', 'price' => 1]);
    Square::squareRecord($described)->getItemData()->setModifierListInfo([
        new CatalogItemModifierListInfo([
            'modifierListId' => 'LIST-BOX',
            'enabled' => true,
            'minSelectedModifiers' => 2,
            'maxSelectedModifiers' => 0,
        ]),
    ]);

    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    Http::fake([
        'https://example.test/photo.jpg' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        'https://example.test/empty.jpg' => Http::response('', 200),
    ]);
    $withImage = Square::squareCatalogItem('ITEM-IMG', 'Photo', 'SKU-IMG');
    Square::squareRecord($withImage)->getItemData()->setImageIds(['IMG-1']);
    $blankBody = Square::squareCatalogItem('ITEM-BLANK', 'Blank', 'SKU-BLANK');
    Square::squareRecord($blankBody)->getItemData()->setImageIds(['IMG-EMPTY']);
    $missingUrl = Square::squareCatalogItem('ITEM-NOURL', 'No url', 'SKU-NOURL');
    Square::squareRecord($missingUrl)->getItemData()->setImageIds(['IMG-NONE']);
    $unknownDeleted = Square::squareCatalogItem('ITEM-MISSING', 'Gone', 'SKU-GONE');
    Square::squareRecord($unknownDeleted)->setIsDeleted(true);

    $this->square->search(Square::catalogResponse(
        [
            Square::squareObject('ITEM', 'ITEM-BARE', ['version' => 1]),
            $described,
            $withImage,
            $blankBody,
            $missingUrl,
            $unknownDeleted,
        ],
        [],
        null,
        [
            Square::squareObject('IMAGE', 'IMG-1', ['imageData' => new CatalogImage(['url' => 'https://example.test/photo.jpg'])]),
            Square::squareObject('IMAGE', 'IMG-EMPTY', ['imageData' => new CatalogImage(['url' => 'https://example.test/empty.jpg'])]),
            Square::squareObject('IMAGE', 'IMG-NONE'),
        ],
    ));

    Square::runPipe(PullItemsJob::class, ['mode' => 'import', 'createMissing' => true]);

    expect($existing->fresh()->menu_description)->toBe('keep me')
        ->and(SquareLink::findOwner(Menu::class, 'ITEM-TEXT')->menu_description)->toBe('from html')
        ->and(SquareLink::findOwner(Menu::class, 'ITEM-IMG'))->not->toBeNull();
});

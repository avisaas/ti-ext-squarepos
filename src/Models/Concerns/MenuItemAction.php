<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Models\Concerns;

use Igniter\Cart\Models\Category;
use Igniter\Cart\Models\MenuItemOption;
use Igniter\Cart\Models\MenuOption;
use Igniter\Cart\Models\Stock;
use Igniter\Flame\Database\Attach\Media;
use Igniter\Flame\Database\Model;
use Igniter\Flame\Traits\ExtensionTrait;
use Igniter\System\Actions\ModelAction;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use IgniterLabs\SquarePOS\Classes\MoneyAmount;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SquareLink;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use IgniterLabs\SquarePOS\Traits\ManagesSquareAttributes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Square\Types\CatalogItem;
use Square\Types\CatalogItemModifierListInfo;
use Square\Types\CatalogItemVariation;
use Square\Types\CatalogObject;
use Square\Types\CatalogObjectCategory;
use Square\Types\CatalogObjectItem;
use Square\Types\CatalogObjectItemVariation;
use Square\Types\ItemVariationLocationOverrides;
use Throwable;

class MenuItemAction extends ModelAction
{
    use ExtensionTrait;
    use LocationHelpers;
    use ManagesSquareAttributes;

    public function __construct(?Model $model)
    {
        parent::__construct($model);

        $this->bootSquareLink();
    }

    public function squareExportItems(SyncTask $syncTask, callable $callback): void
    {
        $query = $this->model->newQuery()
            ->with([
                'squareLink',
                'stocks',
                'categories.squareLink',
                'locations',
                'menu_options.option.squareLink',
            ])
            ->isEnabled()
            ->whereHasOrDoesntHaveLocation(array_keys($syncTask->mappedLocationIds()));

        if ($syncTask->getConfigData('mode') !== 'export') {
            SquareLink::constrainEnabled($query);
        }

        $query->chunkById($syncTask->batchSize(), $callback);
    }

    public function squareImportItems(Collection $catalogObjects, callable $callback): void
    {
        $catalogObjects->filter(fn(CatalogObject $catalogObject): bool => strtoupper($catalogObject->getType()) === 'ITEM')->each(function(CatalogObject $catalogObject, $squareId) use ($callback): void {
            $callback($catalogObject, $this->model);
        });
    }

    public function getSquareId()
    {
        return $this->model->squareLink?->square_id ?: '#temp_menus_'.$this->model->getKey();
    }

    public function getSquareVariationId()
    {
        if (!$this->model->squareLink?->square_id) {
            return '#temp_menu_variations_'.$this->model->getKey();
        }

        return $this->model->squareLink?->square_variation_id ?: '#temp_menu_variations_'.$this->model->getKey();
    }

    public function asSquareItem(): CatalogObject
    {
        $variation = new CatalogObjectItemVariation([
            'id' => (string)$this->getSquareVariationId(),
            'itemVariationData' => new CatalogItemVariation([
                'itemId' => (string)$this->getSquareId(),
                'sku' => $this->squareSku(),
                'name' => $this->model->menu_name,
                'pricingType' => 'FIXED_PRICING',
                'priceMoney' => MoneyAmount::toMoney($this->model->menu_price),
                'locationOverrides' => $this->makeSquareLocationOverrideObjects(),
            ]),
        ]);
        CatalogRecord::version($variation, $this->model->squareLink?->square_variation_version);
        $this->squareApplyLocationIds($variation);

        $itemData = new CatalogItem([
            'name' => $this->model->menu_name,
            'description' => trim(strip_tags((string)$this->model->menu_description)),
            'variations' => [CatalogObject::itemVariation($variation)],
        ]);
        $this->applySquareCategories($itemData);
        $itemData->setModifierListInfo($this->makeSquareModifierListInfoObjects());
        if ($this->model->squareLink?->square_image_id) {
            $itemData->setImageIds([$this->model->squareLink->square_image_id]);
        }

        $item = new CatalogObjectItem([
            'id' => (string)$this->getSquareId(),
            'itemData' => $itemData,
        ]);
        CatalogRecord::version($item, $this->model->squareLink?->square_version);
        $this->squareApplyLocationIds($item);

        return CatalogObject::item($item);
    }

    public function squareSku(): string
    {
        $link = SquareLink::forOwner($this->model);
        if ($link->square_sku) {
            return $link->square_sku;
        }

        $link->square_sku = 'ti_menu_item_'.$this->model->getKey();

        return $link->square_sku;
    }

    public function createFromSquareItem(CatalogObject $catalogObject, bool $createMissing = false)
    {
        return SyncContext::applying(function() use ($catalogObject, $createMissing) {
            $record = CatalogRecord::unwrap($catalogObject);
            $itemData = $record->getItemData();
            $variationObject = $this->primaryVariation($catalogObject);
            $variation = $variationObject instanceof CatalogObject ? CatalogRecord::unwrap($variationObject) : null;
            $variationData = $variation?->getItemVariationData();
            $sku = $variationData instanceof CatalogItemVariation ? $variationData->getSku() : null;

            $menuItem = SquareLink::findOwner($this->model::class, $record->getId());
            if (!$menuItem && $sku) {
                $candidate = SquareLink::query()
                    ->where('linkable_type', $this->model->getMorphClass())
                    ->where('square_sku', $sku)
                    ->first()
                    ?->linkable;
                if ($candidate && (!$candidate->squareLink?->square_id || $candidate->squareLink->square_id === $record->getId())) {
                    $menuItem = $candidate;
                } elseif ($candidate && $sku) {
                    return null;
                }
            }

            if ($menuItem && !$createMissing && $menuItem->squareLink && !$menuItem->squareLink->isSyncEnabled()) {
                return null;
            }

            $enableSync = false;
            if (!$menuItem) {
                if (!$createMissing || !$sku) {
                    return null;
                }

                $menuItem = $this->model->newInstance();
                $menuItem->menu_status = true;
                $enableSync = true;
            } elseif ($createMissing) {
                $enableSync = true;
            }

            $menuItem->menu_name = $itemData instanceof CatalogItem ? $itemData->getName() : $menuItem->menu_name;
            $menuItem->menu_description = $itemData instanceof CatalogItem
                ? ($itemData->getDescriptionPlaintext() ?: $itemData->getDescription())
                : $menuItem->menu_description;
            $menuItem->menu_price = MoneyAmount::fromCents(optional(optional($variationData)->getPriceMoney())->getAmount());
            $menuItem->save();

            $link = SquareLink::forOwner($menuItem);
            if ($enableSync) {
                $link->square_sync = true;
            }

            $link->square_id = $record->getId();
            $link->square_version = $record->getVersion();
            $link->square_sku = $sku ?: $link->square_sku;
            $link->square_dirty = false;
            if ($variation instanceof CatalogObjectItemVariation) {
                $link->square_variation_id = $variation->getId();
                $link->square_variation_version = $variation->getVersion();
            }

            SquareLink::saveOwner($menuItem);

            return $menuItem;
        });
    }

    public function syncSquareRelationships(CatalogObject $catalogObject, ?CatalogResponse $response = null): void
    {
        SyncContext::applying(function() use ($catalogObject, $response): void {
            $this->model->squareAttachLocation($catalogObject);
            $this->attachSquareCategories($catalogObject);
            $this->attachSquareModifiers($catalogObject);
            $this->applySquareStockSettings($catalogObject);
            if ($response instanceof CatalogResponse) {
                $this->attachSquareImage($catalogObject, $response);
            }
        });
    }

    public function primaryVariation(CatalogObject $catalogObject): ?CatalogObject
    {
        $variations = CatalogRecord::unwrap($catalogObject)->getItemData()?->getVariations() ?? [];
        foreach ($variations as $variation) {
            $data = CatalogRecord::unwrap($variation)->getItemVariationData();
            if ($data && $data->getPricingType() !== 'VARIABLE_PRICING' && $data->getPriceMoney()) {
                return $variation;
            }
        }

        return $variations[0] ?? null;
    }

    protected function applySquareCategories(CatalogItem $itemData): void
    {
        $categoryIds = $this->model->categories
            ->pluck('squareLink.square_id')
            ->filter(fn($categoryId): bool => is_string($categoryId) && $categoryId !== '')
            ->unique()
            ->values();
        if ($categoryIds->isEmpty() || !method_exists($itemData, 'setCategories')) {
            return;
        }

        // Square matches each entry as {id, ordinal}. A missing ordinal fails the array type,
        // and the same ordinal on two items in one category is rejected as a duplicate.
        $ordinal = (int)$this->model->getKey();
        $itemData->setCategories($categoryIds->map(function(?string $categoryId) use ($ordinal): CatalogObjectCategory {
            $category = new CatalogObjectCategory;
            $category->setId($categoryId);
            $category->setOrdinal($ordinal);

            return $category;
        })->all());
    }

    protected function categorySquareIds(CatalogObject $catalogObject): array
    {
        $itemData = CatalogRecord::unwrap($catalogObject)->getItemData();
        if (!$itemData instanceof CatalogItem) {
            return [];
        }

        $ids = [];
        if ($itemData->getCategoryId()) {
            $ids[] = $itemData->getCategoryId();
        }

        if (method_exists($itemData, 'getCategories')) {
            foreach ($itemData->getCategories() ?? [] as $category) {
                $id = is_object($category) && method_exists($category, 'getId') ? $category->getId() : null;
                if ($id) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    protected function attachSquareCategories(CatalogObject $catalogObject): void
    {
        $ids = $this->categorySquareIds($catalogObject);
        $localIds = $ids !== []
            ? Category::query()->whereHas('squareLink', function($query) use ($ids): void {
                $query->whereIn('square_id', $ids);
            })->pluck('category_id')->all()
            : [];

        $this->model->categories()->sync($localIds);
    }

    protected function attachSquareModifiers(CatalogObject $catalogObject): void
    {
        $infos = CatalogRecord::unwrap($catalogObject)->getItemData()?->getModifierListInfo() ?? [];
        $keep = [];

        foreach ($infos as $info) {
            if ($info->getEnabled() === false) {
                continue;
            }

            $option = MenuOption::query()->whereHas('squareLink', function($query) use ($info): void {
                $query->where('square_id', $info->getModifierListId());
            })->first();
            if (!$option) {
                continue;
            }

            $menuItemOption = $this->model->menu_options()->firstOrNew([
                'option_id' => $option->getKey(),
            ]);
            $menuItemOption->menu_id = $this->model->getKey();
            $isSingle = $option->display_type !== 'checkbox';
            $minSelected = (int)$info->getMinSelectedModifiers();
            $maxSelected = (int)$info->getMaxSelectedModifiers();
            if ($isSingle) {
                $minSelected = min($minSelected, 1);
                $maxSelected = 1;
            } elseif ($maxSelected < 1) {
                $maxSelected = max(1, $option->option_values->count());
            }

            if ($maxSelected < $minSelected) {
                $maxSelected = $minSelected;
            }

            $menuItemOption->min_selected = $minSelected;
            $menuItemOption->max_selected = $maxSelected;
            $menuItemOption->is_required = $minSelected > 0;
            $menuItemOption->save();

            foreach ($option->option_values as $optionValue) {
                $menuItemOption->menu_option_values()->firstOrCreate([
                    'option_value_id' => $optionValue->getKey(),
                ], [
                    'priority' => (int)$optionValue->priority,
                ]);
            }

            $keep[] = $menuItemOption->getKey();
        }

        $stale = $this->model->menu_options()
            ->whereHas('option.squareLink', function($query): void {
                $query->whereNotNull('square_id');
            });

        if ($keep !== []) {
            $stale->whereNotIn('menu_option_id', $keep);
        }

        $stale->get()->each(function(MenuItemOption $menuItemOption): void {
            $menuItemOption->delete();
        });
    }

    protected function applySquareStockSettings(CatalogObject $catalogObject): void
    {
        $variation = $this->primaryVariation($catalogObject);
        $overrides = $variation instanceof CatalogObject
            ? (CatalogRecord::unwrap($variation)->getItemVariationData()?->getLocationOverrides() ?? [])
            : [];

        foreach ($overrides as $override) {
            $locationId = Settings::clientLocationId($override->getLocationId());
            if (!$locationId) {
                continue;
            }

            $stock = Stock::query()->firstOrNew([
                'stockable_type' => $this->model->getMorphClass(),
                'stockable_id' => $this->model->getKey(),
                'location_id' => $locationId,
            ]);
            $stock->is_tracked = (bool)$override->getTrackInventory();
            $stock->low_stock_alert = $override->getInventoryAlertType() === 'LOW_QUANTITY';
            $stock->low_stock_threshold = (int)$override->getInventoryAlertThreshold();
            $stock->save();
        }
    }

    protected function attachSquareImage(CatalogObject $catalogObject, CatalogResponse $response): void
    {
        $imageIds = CatalogRecord::unwrap($catalogObject)->getItemData()?->getImageIds() ?? [];
        $imageId = $imageIds[0] ?? null;
        if (!$imageId || $this->model->squareLink?->square_image_id === $imageId || !method_exists($this->model, 'getFirstMedia')) {
            return;
        }

        $imageObject = $response->getObject($imageId);
        $url = $imageObject ? CatalogRecord::unwrap($imageObject)->getImageData()?->getUrl() : null;
        if (!$url) {
            return;
        }

        try {
            $contents = Http::timeout(20)->get($url)->body();
            if ($contents === '') {
                return;
            }

            if (method_exists($this->model, 'clearMediaTag')) {
                $this->model->clearMediaTag('thumb');
            }

            $media = new Media;
            $media->setRelation('attachment', $this->model);
            $media->addFromRaw($contents, 'square-'.$imageId.'.jpg', 'thumb');
            $link = SquareLink::forOwner($this->model);
            $link->square_image_id = $imageId;
            SquareLink::saveOwner($this->model);
        } catch (Throwable) {
            // Image bytes are optional; catalog fields still persist.
        }
    }

    protected function makeSquareLocationOverrideObjects()
    {
        return $this->model->getAvailableStocks()
            ->filter(fn($stock): bool => (bool)$stock->squareLocationRemoteId())
            ->map(function($stock): ItemVariationLocationOverrides {
                $itemVariationOverrides = new ItemVariationLocationOverrides;
                $itemVariationOverrides->setTrackInventory((bool)$stock->is_tracked);
                $itemVariationOverrides->setInventoryAlertType($stock->low_stock_alert ? 'LOW_QUANTITY' : 'NONE');
                $itemVariationOverrides->setInventoryAlertThreshold($stock->low_stock_threshold);
                $itemVariationOverrides->setLocationId($stock->squareLocationRemoteId());

                return $itemVariationOverrides;
            })->all();
    }

    protected function makeSquareModifierListInfoObjects()
    {
        $result = [];
        $itemOptions = $this->model->menu_options ?? [];
        foreach ($itemOptions as $itemOption) {
            if (!$itemOption->option?->squareLink?->square_id) {
                continue;
            }

            $modifierListInfoObject = new CatalogItemModifierListInfo([
                'modifierListId' => $itemOption->option->squareLink->square_id,
                'enabled' => true,
                'minSelectedModifiers' => $itemOption->min_selected ?: 0,
                'maxSelectedModifiers' => $itemOption->max_selected ?: $itemOption->menu_option_values->count() ?: 1,
            ]);

            $result[] = $modifierListInfoObject;
        }

        return $result;
    }
}

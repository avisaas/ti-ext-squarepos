<?php

namespace IgniterLabs\SquarePOS\Classes;

use Admin\Models\Categories_model;
use Admin\Models\Menu_item_options_model;
use Admin\Models\Menus_model;
use Igniter\Flame\Traits\Singleton;
use IgniterLabs\SquarePOS\Models\Cache;
use IgniterLabs\SquarePOS\Models\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Square\Models\CatalogCategory;
use Square\Models\CatalogItem;
use Square\Models\CatalogItemModifierListInfo;
use Square\Models\CatalogItemVariation;
use Square\Models\CatalogModifier;
use Square\Models\CatalogModifierList;
use Square\Models\CatalogObject;
use Square\Models\CatalogObjectBatch;
use Square\Models\Money;

class CatalogFactory
{
    use Singleton;

    public function makeCategoryBatch(Categories_model $category, RemoteMapping $mapping)
    {
        $categoryData = new CatalogCategory();
        $categoryData->setName($category->name);

        $categoryObject = new CatalogObject('CATEGORY', $mapping->squareId);
        $categoryObject->setCategoryData($categoryData);
        $categoryObject->setVersion($mapping->squareVersion);

        return new CatalogObjectBatch([$categoryObject]);
    }

    public function makeItemBatch(Menus_model $menuItem, RemoteMapping $mapping, Collection $optionMappings)
    {
        $locationIds = $this->getLocationIds($menuItem);

        $itemPrice = new Money();
        $itemPrice->setAmount(number_format($menuItem->menu_price, 2, '.', '') * 100);
        $itemPrice->setCurrency(currency()->getUserCurrency());

        $itemVariationData = new CatalogItemVariation();
        $itemVariationData->setItemId($mapping->squareId);
        $itemVariationData->setSku('ti_menu_item_'.$menuItem->menu_id);
        $itemVariationData->setName($menuItem->menu_name);
        $itemVariationData->setPricingType('FIXED_PRICING');
        $itemVariationData->setPriceMoney($itemPrice);
        $itemVariationData->setTrackInventory((bool)$menuItem->subtract_stock);
        $itemVariationData->setInventoryAlertType('LOW_QUANTITY');
        $itemVariationData->setInventoryAlertThreshold(10);

        $itemVariationObject = new CatalogObject('ITEM_VARIATION', $mapping->squareVariationId);
        $itemVariationObject->setItemVariationData($itemVariationData);
        $itemVariationObject->setVersion($mapping->squareVariationVersion);
        $this->applyLocationIds($itemVariationObject, $locationIds);

        $itemData = new CatalogItem();
        $itemData->setName($menuItem->menu_name);
        $itemData->setDescription($menuItem->menu_description);
        $itemData->setVariations([$itemVariationObject]);
        $itemData->setCategoryId($this->getCategoryId($menuItem));
        $itemData->setModifierListInfo($this->makeModifierListInfoObjects($menuItem, $optionMappings));

        $itemObject = new CatalogObject('ITEM', $mapping->squareId);
        $itemObject->setItemData($itemData);
        $itemObject->setVersion($mapping->squareVersion);
        $this->applyLocationIds($itemObject, $locationIds);

        return new CatalogObjectBatch([$itemObject]);
    }

    public function makeModifierListBatch(Menu_item_options_model $itemOption, RemoteMapping $mapping)
    {
        $locationIds = $this->getLocationIds($itemOption->menu);

        $modifierListData = new CatalogModifierList();
        $modifierListData->setName($itemOption->option->option_name);
        $modifierListData->setOrdinal($itemOption->priority);
        $modifierListData->setSelectionType($itemOption->display_type == 'checkbox' ? 'MULTIPLE' : 'SINGLE');
        $modifierListData->setModifiers($this->makeModifierObjects(
            $itemOption, $mapping, $locationIds
        ));

        $modifierListObject = new CatalogObject('MODIFIER_LIST', $mapping->squareId);
        $modifierListObject->setModifierListData($modifierListData);
        $modifierListObject->setVersion($mapping->squareVersion);
        $this->applyLocationIds($modifierListObject, $locationIds);

        return new CatalogObjectBatch([$modifierListObject]);
    }

    protected function makeModifierObjects($itemOption, RemoteMapping $mapping, $locationIds)
    {
        $result = [];
        $valueMappings = $mapping->get('values', []);
        $itemOptionValues = $itemOption->menu_option_values ?? [];
        foreach ($itemOptionValues as $itemOptionValue) {
            if (!$valueMapping = array_get($valueMappings, $itemOptionValue->menu_option_value_id))
                continue;

            $modifierPrice = new Money();
            $modifierPrice->setAmount(number_format($itemOptionValue->new_price, 2, '.', '') * 100);
            $modifierPrice->setCurrency(currency()->getUserCurrency());

            $modifierData = new CatalogModifier();
            $modifierData->setName($itemOptionValue->option_value->value);
            $modifierData->setOrdinal($itemOptionValue->priority);
            $modifierData->setPriceMoney($modifierPrice);
            $modifierData->setModifierListId($mapping->squareId);

            $modifierObject = new CatalogObject('MODIFIER', $valueMapping['squareId']);
            $modifierObject->setModifierData($modifierData);
            $modifierObject->setVersion($valueMapping['squareVersion']);
            $this->applyLocationIds($modifierObject, $locationIds);

            $result[] = $modifierObject;
        }

        return $result;
    }

    protected function makeModifierListInfoObjects(Menus_model $menuItem, Collection $mappings)
    {
        $result = [];
        $itemOptions = $menuItem->menu_options ?? [];
        foreach ($itemOptions as $itemOption) {
            if (!$mapping = $mappings->get($itemOption->menu_option_id))
                continue;

            $modifierListInfoObject = new CatalogItemModifierListInfo($mapping['squareId']);
            $modifierListInfoObject->setEnabled(TRUE);
            $modifierListInfoObject->setMinSelectedModifiers($itemOption->min_selected ?: 0);
            $modifierListInfoObject->setMaxSelectedModifiers($itemOption->max_selected ?: count($mapping['values']) ?: 1);

            $result[] = $modifierListInfoObject;
        }

        return $result;
    }

    protected function getLocationIds(Model $model)
    {
        $locationIds = [];
        if (!$model->locations)
            return $locationIds;

        $locationMappings = Settings::locationMapping()->keyBy('client_id');
        foreach ($model->locations->pluck('location_id')->all() ?? [] as $locationId) {
            if ($mapping = $locationMappings->get($locationId)) {
                $locationIds[] = array_get($mapping, 'remote_id');
            }
        }

        return $locationIds;
    }

    protected function getCategoryId($menuItem)
    {
        $mapping = Cache::getMap('category_map')
            ->whereIn('id', $menuItem->categories->pluck('category_id')->all())
            ->first();

        return $mapping ? $mapping->squareId : null;
    }

    protected function applyLocationIds(CatalogObject $catalogObject, array $ids)
    {
        $catalogObject->setPresentAtAllLocations($ids ? FALSE : TRUE);
        $catalogObject->setPresentAtLocationIds($ids);
    }
}
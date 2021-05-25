<?php

namespace IgniterLabs\SquarePOS\Models;

use Admin\Models\Menus_model as BaseMenus_model;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use Square\Models\CatalogItem;
use Square\Models\CatalogItemModifierListInfo;
use Square\Models\CatalogItemVariation;
use Square\Models\CatalogObject;
use Square\Models\Money;

class Menus_model extends BaseMenus_model
{
    use LocationHelpers;

    public function getSquareIdAttribute($value)
    {
        return $value ?: '#temp_menus_'.$this->getKey();
    }

    public function getSquareVariationIdAttribute($value)
    {
        return $value ?: '#temp_menu_variations_'.$this->getKey();
    }

    public function asSquareMenuItem()
    {
        $itemPrice = new Money();
        $itemPrice->setAmount(number_format($this->menu_price, 2, '.', '') * 100);
        $itemPrice->setCurrency(currency()->getUserCurrency());

        $itemVariationData = new CatalogItemVariation();
        $itemVariationData->setItemId($this->square_id);
        $itemVariationData->setSku('ti_menu_item_'.$this->menu_id);
        $itemVariationData->setName($this->menu_name);
        $itemVariationData->setPricingType('FIXED_PRICING');
        $itemVariationData->setPriceMoney($itemPrice);
        $itemVariationData->setTrackInventory((bool)$this->subtract_stock);
        $itemVariationData->setInventoryAlertType('LOW_QUANTITY');
        $itemVariationData->setInventoryAlertThreshold(10);

        $itemVariationObject = new CatalogObject('ITEM_VARIATION', $this->square_variation_id);
        $itemVariationObject->setItemVariationData($itemVariationData);
        $this->applyLocationIds($itemVariationObject, $this->getLocationIdsFrom($this));

        $itemData = new CatalogItem();
        $itemData->setName($this->menu_name);
        $itemData->setDescription($this->menu_description);
        $itemData->setVariations([$itemVariationObject]);
        $itemData->setCategoryId($this->getCategoryId());
        $itemData->setModifierListInfo($this->makeModifierListInfoObjects());

        $itemObject = new CatalogObject('ITEM', $this->square_id);
        $itemObject->setItemData($itemData);

        $this->applyLocationIds($itemObject, $this->getLocationIdsFrom($this));

        return $itemObject;
    }

    protected function getCategoryId()
    {
        $mapping = $this->categories->pluck('square_id')->first();

        return $mapping ? $mapping->squareId : null;
    }

    protected function makeModifierListInfoObjects()
    {
        $result = [];
        $itemOptions = $this->menu_options ?? [];
        foreach ($itemOptions as $itemOption) {
            if (!$itemOption->square_id)
                continue;

            $modifierListInfoObject = new CatalogItemModifierListInfo($itemOption->square_id);
            $modifierListInfoObject->setEnabled(TRUE);
            $modifierListInfoObject->setMinSelectedModifiers($itemOption->min_selected ?: 0);
            $modifierListInfoObject->setMaxSelectedModifiers($itemOption->max_selected ?: $itemOption->menu_option_values->count() ?: 1);

            $result[] = $modifierListInfoObject;
        }

        return $result;
    }

    public function getMorphClass()
    {
        return 'menus';
    }
}

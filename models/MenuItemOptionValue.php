<?php

namespace IgniterLabs\SquarePOS\Models;

use Admin\Models\Menu_item_option_values_model;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use Square\Models\CatalogModifier;
use Square\Models\CatalogObject;
use Square\Models\Money;

class MenuItemOptionValue extends Menu_item_option_values_model
{
    use LocationHelpers;

    public function getSquareIdAttribute($value)
    {
        return $value ?: '#temp_'.$this->getMorphClass().'_'.$this->getKey();
    }

    public function asSquareMenuItemOptionValue()
    {
        $modifierPrice = new Money();
        $modifierPrice->setAmount(number_format($this->new_price, 2, '.', '') * 100);
        $modifierPrice->setCurrency(currency()->getUserCurrency());

        $modifierData = new CatalogModifier();
        $modifierData->setName($this->option_value->value);
        $modifierData->setOrdinal($this->priority);
        $modifierData->setPriceMoney($modifierPrice);
        $modifierData->setModifierListId($this->square_id);

        $modifierObject = new CatalogObject('MODIFIER', $this->square_id);
        $modifierObject->setModifierData($modifierData);
        $modifierObject->setVersion((int)$this->square_version);

        $this->applyLocationIds($modifierObject, $this->getLocationIdsFrom($this->menu_option->menu));

        return $modifierObject;
    }

    public function getMorphClass()
    {
        return 'menu_item_option_values';
    }
}

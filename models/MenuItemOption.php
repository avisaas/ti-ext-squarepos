<?php

namespace IgniterLabs\SquarePOS\Models;

use Admin\Models\Menu_item_options_model;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use Square\Models\CatalogModifierList;
use Square\Models\CatalogObject;

class MenuItemOption extends Menu_item_options_model
{
    use LocationHelpers;

    public function __construct()
    {
        parent::__construct();

        $this->relation['hasMany']['menu_option_values'] = [
            MenuItemOptionValue::class,
            'foreignKey' => 'menu_option_id',
        ];
    }

    public function getSquareIdAttribute($value)
    {
        return $value ?: '#temp_'.$this->getMorphClass().'_'.$this->getKey();
    }

    public function asSquareMenuItemOption()
    {
        $modifierListData = new CatalogModifierList();
        $modifierListData->setName($this->menu->menu_name.' - '.$this->option->option_name);
        $modifierListData->setOrdinal($this->priority);
        $modifierListData->setSelectionType(
            $this->display_type === 'checkbox' ? 'MULTIPLE' : 'SINGLE'
        );

        $modifierListObject = new CatalogObject('MODIFIER_LIST', $this->square_id);
        $modifierListObject->setModifierListData($modifierListData);
        $modifierListObject->setVersion((int)$this->square_version);

        $this->applyLocationIds($modifierListObject, $this->getLocationIdsFrom($this->menu));

        return $modifierListObject;
    }

    public function getMorphClass()
    {
        return 'menu_item_options';
    }
}

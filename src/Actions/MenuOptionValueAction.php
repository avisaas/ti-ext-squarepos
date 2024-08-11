<?php

namespace IgniterLabs\SquarePOS\Actions;

use Igniter\Flame\Traits\ExtensionTrait;
use Igniter\System\Actions\ModelAction;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use Square\Models\CatalogModifier;
use Square\Models\CatalogObject;
use Square\Models\Money;

class MenuOptionValueAction extends ModelAction
{
    use ExtensionTrait;
    use LocationHelpers;

    public function getSquareId()
    {
        return $this->model->square_id ?: '#temp_'.$this->model->getMorphClass().'_'.$this->model->getKey();
    }

    public function asSquareModifier()
    {
        $modifierPrice = new Money;
        $modifierPrice->setAmount(number_format($this->model->price, 2, '.', '') * 100);
        $modifierPrice->setCurrency(currency()->getUserCurrency());

        $modifierData = new CatalogModifier;
        $modifierData->setName($this->model->value);
        $modifierData->setOrdinal($this->model->priority);
        $modifierData->setPriceMoney($modifierPrice);
        $modifierData->setModifierListId($this->model->option->getSquareId());

        $modifierObject = new CatalogObject('MODIFIER', $this->getSquareId());
        $modifierObject->setModifierData($modifierData);
        $modifierObject->setVersion((int)$this->model->square_version);

        $this->model->option->squareApplyLocationIds($modifierObject);

        return $modifierObject;
    }
}

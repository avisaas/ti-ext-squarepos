<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Models\Concerns;

use Igniter\Flame\Database\Model;
use Igniter\Flame\Traits\ExtensionTrait;
use Igniter\System\Actions\ModelAction;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\MoneyAmount;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use IgniterLabs\SquarePOS\Traits\ManagesSquareAttributes;
use Square\Types\CatalogModifier;
use Square\Types\CatalogObject;
use Square\Types\CatalogObjectModifier;

class MenuOptionValueAction extends ModelAction
{
    use ExtensionTrait;
    use LocationHelpers;
    use ManagesSquareAttributes;

    public function __construct(?Model $model)
    {
        parent::__construct($model);

        $this->bootSquareLink();
    }

    public function getSquareId()
    {
        return $this->model->squareLink?->square_id ?: '#temp_'.$this->model->getMorphClass().'_'.$this->model->getKey();
    }

    public function asSquareModifier(): CatalogObject
    {
        $modifier = new CatalogObjectModifier([
            'id' => (string)$this->getSquareId(),
            'modifierData' => new CatalogModifier([
                'name' => $this->model->value,
                'ordinal' => $this->model->priority,
                'priceMoney' => MoneyAmount::toMoney($this->model->price),
                'modifierListId' => $this->model->option->getSquareId(),
            ]),
        ]);
        CatalogRecord::version($modifier, $this->model->squareLink?->square_version);
        $this->model->option->squareApplyLocationIds($modifier);

        return CatalogObject::modifier($modifier);
    }
}

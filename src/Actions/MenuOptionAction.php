<?php

namespace IgniterLabs\SquarePOS\Actions;

use Igniter\Flame\Traits\ExtensionTrait;
use Igniter\System\Actions\ModelAction;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use Illuminate\Support\Collection;
use Square\Models\CatalogModifierList;
use Square\Models\CatalogObject;

class MenuOptionAction extends ModelAction
{
    use ExtensionTrait;
    use LocationHelpers;

    public function squareExportItems(SyncTask $syncTask, callable $callback)
    {
        return $this->model->newQuery()
            ->with(['option_values', 'locations'])
            ->whereIn('display_type', ['radio', 'checkbox', 'select'])
            ->whereHasOrDoesntHaveLocation(array_keys($syncTask->mappedLocationIds()))
            ->chunkById($syncTask->batchSize(), $callback);
    }

    public function squareImportItems(Collection $catalogObjects, callable $callback)
    {
        $catalogObjects->filter(function (CatalogObject $catalogObject) {
            return strtoupper($catalogObject->getType()) === 'MODIFIER_LIST';
        })->each(function (CatalogObject $catalogObject, $squareId) use ($callback) {
            $callback($catalogObject, $this->model);
        });
    }

    public function getSquareId()
    {
        return $this->model->square_id ?: '#temp_'.$this->model->getMorphClass().'_'.$this->model->getKey();
    }

    public function asSquareModifierList()
    {
        $modifierListData = new CatalogModifierList();
        $modifierListData->setName($this->model->option_name);
        $modifierListData->setOrdinal($this->model->priority);
        $modifierListData->setSelectionType(
            $this->model->display_type === 'checkbox' ? 'MULTIPLE' : 'SINGLE'
        );

        $modifierListObject = new CatalogObject('MODIFIER_LIST', $this->getSquareId());
        $modifierListObject->setModifierListData($modifierListData);
        $modifierListObject->setVersion((int)$this->model->square_version);

        $this->squareApplyLocationIds($modifierListObject);

        return $modifierListObject;
    }

    public function createFromSquareModifierList(CatalogObject $catalogObject)
    {
        $optionValues = collect($catalogObject->getModifierListData()->getModifiers())
            ->map(function (CatalogObject $modifierObject) {
                return [
                    'value' => $modifierObject->getModifierData()->getName(),
                    'price' => $modifierObject->getModifierData()->getPriceMoney()->getAmount() / 100,
                    'priority' => $modifierObject->getModifierData()->getOrdinal(),
                    'square_id' => $modifierObject->getId(),
                    'square_version' => $modifierObject->getVersion(),
                ];
            })
            ->all();

        $attributes = [
            'name' => $catalogObject->getModifierListData()->getName(),
            'priority' => $catalogObject->getModifierListData()->getOrdinal(),
            'display_type' => $catalogObject->getModifierListData()->getSelectionType() === 'MULTIPLE' ? 'checkbox' : 'radio',
            'square_version' => $catalogObject->getVersion(),
            'values' => $optionValues,
        ];

        return $this->model->updateOrCreate([
            'square_id' => $catalogObject->getId(),
        ], $attributes);
    }
}

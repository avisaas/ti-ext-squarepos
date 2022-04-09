<?php

namespace IgniterLabs\SquarePOS\Actions;

use Igniter\Flame\Traits\ExtensionTrait;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use Illuminate\Support\Collection;
use Square\Models\CatalogCategory;
use Square\Models\CatalogObject;
use System\Actions\ModelAction;

class CategoryAction extends ModelAction
{
    use ExtensionTrait;
    use LocationHelpers;

    public function squareExportItems(SyncTask $syncTask, callable $callback)
    {
        $this->model->newQuery()
            ->with(['locations'])
            ->isEnabled()
            ->whereHasOrDoesntHaveLocation(array_keys($syncTask->mappedLocationIds()))
            ->chunkById($syncTask->batchSize(), $callback);
    }

    public function squareImportItems(Collection $catalogObjects, callable $callback)
    {
        $catalogObjects->filter(function (CatalogObject $catalogObject) {
            return strtoupper($catalogObject->getType()) === 'CATEGORY';
        })->each(function (CatalogObject $catalogObject, $squareId) use ($callback) {
            $callback($catalogObject, $this->model);
        });
    }

    public function getSquareId()
    {
        return $this->model->square_id ?: '#temp_'.$this->model->getMorphClass().'_'.$this->model->getKey();
    }

    public function asSquareCategory()
    {
        $categoryData = new CatalogCategory();
        $categoryData->setName($this->model->name);

        $categoryObject = new CatalogObject('CATEGORY', $this->getSquareId());
        $categoryObject->setCategoryData($categoryData);
        $categoryObject->setVersion($this->model->square_version);

        $this->squareApplyLocationIds($categoryObject);

        return $categoryObject;
    }

    protected function createFromSquareCategory(CatalogObject $catalogObject)
    {
        $attributes = [
            'name' => $catalogObject->getCategoryData()->getName(),
            'square_version' => $catalogObject->getVersion(),
        ];

        return $this->model->updateOrCreate([
            'square_id' => $catalogObject->getId(),
        ], $attributes);
    }
}

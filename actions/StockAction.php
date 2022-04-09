<?php

namespace IgniterLabs\SquarePOS\Actions;

use Igniter\Flame\Traits\ExtensionTrait;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use Square\Models\InventoryChange;
use Square\Models\InventoryPhysicalCount;
use System\Actions\ModelAction;

class StockAction extends ModelAction
{
    use ExtensionTrait;
    use LocationHelpers;

    public function squareExportItems(SyncTask $syncTask, callable $callback)
    {
        return $this->model->newQuery()
            ->with(['stockable', 'location'])
            ->whereNotNull('quantity')
            ->where('stockable_type', 'menus')
            ->whereHas('stockable', function ($query) {
                $query->whereNotNull('square_variation_id');
            })
            ->whereHasOrDoesntHaveLocation(array_keys($syncTask->mappedLocationIds()))
            ->chunkById($syncTask->batchSize(), $callback);
    }

    public function getSquareId()
    {
        return $this->model->square_id ?: '#temp_'.$this->model->getMorphClass().'_'.$this->model->getKey();
    }

    public function asSquareInventoryChange()
    {
        $physicalCount = new InventoryPhysicalCount();
        $physicalCount->setCatalogObjectId($this->model->stockable->square_variation_id);
        $physicalCount->setState('IN_STOCK');
        $physicalCount->setLocationId($this->squareLocationRemoteId());
        $physicalCount->setQuantity($this->model->quantity);
        $physicalCount->setOccurredAt(make_carbon($this->model->updated_at)->toISOString());

        $inventoryChange = new InventoryChange();
        $inventoryChange->setType('PHYSICAL_COUNT');
        $inventoryChange->setPhysicalCount($physicalCount);

        return $inventoryChange;
    }
}

<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Stocks_model;

class PushStocks extends AbstractSyncPipe
{
    public function execute()
    {
        Stocks_model::squareExportItems($this->syncTask, function ($stocks) {
            $this->syncTask->note('Exporting menu item stocks into square');

            $stocks = $this->removeCleanVersions($stocks);

            if ($stocks->isNotEmpty()) {
                $changes = $this->makeChangesBatch($stocks);

                $this->square->batchChangeInventory($changes);
            }
        });
    }

    protected function removeCleanVersions($stocks)
    {
        return $stocks->filter(function ($stock) {
            $locations = $stock->stockable->getStockableLocations();

            return $locations->isNotEmpty()
                ? $locations->pluck('location_id')->contains($stock->location_id)
                : true;
        });
    }

    protected function makeChangesBatch($stocks)
    {
        return $stocks->map(function ($stock) {
            return $stock->asSquareInventoryChange();
        })->values()->all();
    }
}

<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Menus_model;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use Illuminate\Support\Collection;
use Square\Models\CatalogObjectBatch;

class PushItems extends AbstractSyncPipe
{
    public function execute()
    {
        Menus_model::squareExportItems($this->syncTask, function ($menuItems) {
            $this->syncTask->note('Exporting menu items into square');

            $menuItems = $this->removeCleanVersions($menuItems);

            if ($menuItems->isNotEmpty()) {
                $batch = $this->makeCatalogBatch($menuItems);

                $response = $this->square->batchUpsertCatalogObjects([$batch]);

                $this->updateSquareAttributes($menuItems, $response);
            }
        });
    }

    protected function removeCleanVersions(Collection $menuItems)
    {
        if (!$mappedIds = $menuItems->whereNotNull('square_id')->pluck('square_id')->all()) {
            return $menuItems;
        }

        $response = $this->square->batchRetrieveCatalogObjects($mappedIds);

        return $menuItems->filter(function ($menuItem) use ($response) {
            if (is_null($menuItem->square_id) || is_null($menuItem->square_variation_id)) {
                return true;
            }

            $catalogObject = $response->getObject($menuItem->square_id);
            $squareVersion = $catalogObject ? $catalogObject->getVersion() : null;

            if ((int)$menuItem->square_version === $squareVersion) {
                return false;
            }

            $menuItem->square_id = $catalogObject ? $catalogObject->getId() : null;
            $menuItem->square_version = $squareVersion;

            $squareVariationVersion = ($catalogObject && is_array($variations = optional($catalogObject->getItemData())->getVariations()))
                ? $variations[0]->getVersion() : null;

            if ((int)$menuItem->square_variation_version === $squareVariationVersion) {
                return false;
            }

            $menuItem->square_variation_id = isset($variations[0]) ? $variations[0]->getId() : null;
            $menuItem->square_variation_version = $squareVariationVersion;

            return true;
        });
    }

    protected function makeCatalogBatch(Collection $menuItems)
    {
        $batch = $menuItems->map(function ($menuItem) {
            return $menuItem->asSquareItem();
        })->values()->all();

        return new CatalogObjectBatch($batch);
    }

    protected function updateSquareAttributes(Collection $menuItems, CatalogResponse $response)
    {
        $menuItems->each(function ($menuItem) use ($response) {
            if ($idMapping = $response->getMapping($menuItem->getSquareId())) {
                $menuItem->square_id = $idMapping->getObjectId();
            }

            $catalogObject = $response->getObject($menuItem->square_id);
            if ((int)$menuItem->square_version !== $catalogObject->getVersion()) {
                $menuItem->square_version = $catalogObject->getVersion();
            }

            if ($variationObject = optional($catalogObject->getItemData())->getVariations()[0]) {
                $menuItem->square_variation_id = $variationObject->getId();
                $menuItem->square_variation_version = $variationObject->getVersion();
            }

            if ($menuItem->save()) {
                $this->syncTask->noteUpdated($menuItem, $catalogObject->getItemData()->getName());
            }
        });
    }
}

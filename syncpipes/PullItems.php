<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use IgniterLabs\SquarePOS\Classes\Manager;
use IgniterLabs\SquarePOS\Models\Menus_model;
use IgniterLabs\SquarePOS\Models\SyncLog;

class PullItems extends AbstractSyncPipe
{
    public function run(SyncLog $syncLog)
    {
        $response = Manager::instance()->searchCatalogObjects([
            'object_types' => ['ITEM', 'ITEM_VARIATION'],
            'cursor' => $syncLog->getConfig('itemsCursor'),
            'limit' => $syncLog->batchSize(),
            'include_related_objects' => TRUE,
        ]);

        $response->getObjects()->each(function ($catalogObject, $squareId) {
            if (!$itemData = $catalogObject->getItemData())
                return TRUE;

            if (!$itemVariation = array_get($itemData->getVariations(), 0))
                return TRUE;

            $itemVariationData = $itemVariation->getItemVariationData();

            Menus_model::updateOrCreate([
                'square_id' => $squareId,
                'square_variation_id' => $itemVariation->getId(),
            ], [
                'menu_name' => $itemData->getName(),
                'menu_description' => $itemData->getDescription(),
                'menu_price' => $itemVariationData->getPriceMoney()->getAmount(),
                'subtract_stock' => $itemVariationData->getTrackInventory(),
                'square_version' => $catalogObject->getVersion(),
                'square_variation_version' => $itemVariation->getVersion(),
            ]);
        });
    }
}

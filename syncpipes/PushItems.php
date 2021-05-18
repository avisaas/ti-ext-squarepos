<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Menus_model;
use IgniterLabs\SquarePOS\Classes\CatalogFactory;
use IgniterLabs\SquarePOS\Classes\RemoteMapping;
use IgniterLabs\SquarePOS\Models\Cache;
use IgniterLabs\SquarePOS\Models\SyncLog;

class PushItems extends AbstractSyncPipe
{
    public function run(SyncLog $syncLog)
    {
        if (!$itemIds = $syncLog->getConfig('itemIds', []))
            return;

        $catalogFactory = CatalogFactory::instance();
        $idempotencyKey = md5($syncLog->uuid).'_upsert_items';

        $mappings = RemoteMapping::createAll(
            Cache::getItemMappings($itemIds)
        );

        $optionsMappings = Cache::getMap('option_map')
            ->whereIn('itemModelId', $itemIds)
            ->groupBy('itemModelId');

        $batches = Menus_model::query()
            ->with(['menu_options.menu_option_values', 'locations'])
            ->whereIn('menu_id', $itemIds)
            ->get()
            ->map(function ($menuItem) use ($mappings, $catalogFactory, $optionsMappings) {
                if (!$mapping = $mappings->get($menuItem->getKey()))
                    return FALSE;

                return $catalogFactory->makeItemBatch($menuItem, $mapping,
                    collect($optionsMappings->get($menuItem->getKey()))->keyBy('modelId')
                );
            })
            ->filter()
            ->all();

        $response = $this->apiManager->batchUpsertCatalogObjects($idempotencyKey, $batches);

        $idMappings = collect($response->getResult()->getIdMappings() ?? [])
            ->keyBy(function ($idMapping) {
                return $idMapping->getClientObjectId();
            });

        $catalogObjects = collect($response->getResult()->getObjects())
            ->keyBy(function ($catalogObject) {
                return $catalogObject->getId();
            });

        $mappings->each(function (RemoteMapping $mapping) use ($idMappings, $catalogObjects) {
            if ($idMapping = $idMappings->get($mapping->squareId)) {
                $mapping->set('squareId', $idMapping->getObjectId());
            }

            if ($catalogObject = $catalogObjects->get($mapping->squareId)) {
                $variationObject = $catalogObject->getItemData() ?
                    $catalogObject->getItemData()->getVariations()[0] : [];

                $mapping->set('squareVersion', $catalogObject->getVersion());
                $mapping->set('squareVariationVersion', $variationObject ? $variationObject->getVersion() : null);
            }

            $mapping->updateCache();
        });

        Cache::itemMap(RemoteMapping::$mappingsCache);
    }
}
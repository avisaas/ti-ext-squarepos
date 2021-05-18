<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Menus_model;
use IgniterLabs\SquarePOS\Classes\RemoteMapping;
use IgniterLabs\SquarePOS\Exceptions\SyncFailed;
use IgniterLabs\SquarePOS\Models\Cache;
use IgniterLabs\SquarePOS\Models\SyncLog;

class RefreshItemMappings extends AbstractSyncPipe
{
    public function run(SyncLog $syncLog)
    {
        if (!$itemIds = $syncLog->getConfig('itemIds', []))
            return;

        // Pull item local mapping
        $mappings = Cache::getItemMappings($itemIds);
        $mappedItems = RemoteMapping::createAll($mappings);
        $unmappedItemsIds = collect($itemIds)->diff($mappings->keys());

        if ($mappedItems->isNotEmpty()) {
            $response = $this->apiManager->batchRetrieveCatalogObjects(
                collect($mappedItems)->pluck('squareId')->all()
            );

            $catalogObjects = collect($response->getResult()->getObjects())
                ->keyBy(function ($catalogObject) {
                    return $catalogObject->getId();
                });

            $mappedItems
                ->filter(function (RemoteMapping $mapping) use ($catalogObjects) {
                    if ($catalogObject = $catalogObjects->get($mapping->squareId)) {
                        $variationObject = $catalogObject->getItemData() ?
                            $catalogObject->getItemData()->getVariations()[0] : [];

                        $mapping
                            ->set('squareVersion', $catalogObject->getVersion())
                            ->set('squareVariationVersion', $variationObject ? $variationObject->getVersion() : null)
                            ->updateCache();

                        return FALSE;
                    }

                    return TRUE;
                })->each(function (RemoteMapping $mapping) use ($unmappedItemsIds) {
                    $unmappedItemsIds->push($mapping->modelId);
                    $mapping->forgetCache();
                });
        }

        if ($unmappedItemsIds->isNotEmpty()) {
            $response = $this->apiManager->searchCatalogObjects([
                'object_types' => ['ITEM'],
                'cursor' => $syncLog->getConfig('unmappedItemsCursor'),
            ]);

            if (!$response->isSuccess())
                throw new SyncFailed($response->getErrors());

            $catalogObjects = collect($response->getResult()->getObjects())
                ->keyBy(function ($catalogObject) {
                    return strtolower($catalogObject->getItemData()->getName());
                });

            Menus_model::query()
                ->whereIn('menu_id', $unmappedItemsIds)
                ->get()
                ->each(function ($menuItem) use ($catalogObjects) {
                    $catalogObject = $catalogObjects->get(strtolower($menuItem->menu_name));

                    $variationObject = $catalogObject->getItemData() ?
                        $catalogObject->getItemData()->getVariations()[0] : null;

                    RemoteMapping::create([
                        'modelId' => $menuItem->getKey(),
                        'squareId' => $catalogObject ? $catalogObject->getId() : '#'.$menuItem->getMorphClass().'-'.$menuItem->getKey(),
                        'squareVersion' => $catalogObject ? $catalogObject->getVersion() : null,
                        'squareVariationId' => $variationObject ? $variationObject->getId() : '#'.$menuItem->getMorphClass().'_variation-'.$menuItem->getKey(),
                        'squareVariationVersion' => $variationObject ? $variationObject->getVersion() : null,
                    ]);
                });

            $syncLog->setConfig('unmappedItemsCursor', $response->getResult()->getCursor());
        }

        Cache::itemMap(RemoteMapping::$mappingsCache);
    }
}
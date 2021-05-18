<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Categories_model;
use IgniterLabs\SquarePOS\Classes\CatalogFactory;
use IgniterLabs\SquarePOS\Classes\RemoteMapping;
use IgniterLabs\SquarePOS\Models\Cache;
use IgniterLabs\SquarePOS\Models\SyncLog;

class PushCategories extends AbstractSyncPipe
{
    public function run(SyncLog $syncLog)
    {
        if (!$categoryIds = $syncLog->getConfig('categoryIds', []))
            return;

        $catalogFactory = CatalogFactory::instance();
        $idempotencyKey = md5($syncLog->uuid).'_upsert_categories';

        $mappings = RemoteMapping::createAll(
            Cache::getCategoryMappings($categoryIds)
        );

        $batches = Categories_model::query()
            ->whereIn('category_id', $categoryIds)
            ->get()
            ->map(function ($category) use ($mappings, $catalogFactory) {
                if (!$mapping = $mappings->get($category->getKey()))
                    return FALSE;

                return $catalogFactory->makeCategoryBatch($category, $mapping);
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
                $mapping->set('squareVersion', $catalogObject->getVersion());
            }

            $mapping->updateCache();
        });

        Cache::categoryMap(RemoteMapping::$mappingsCache);
    }
}
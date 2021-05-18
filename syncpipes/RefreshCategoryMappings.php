<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Categories_model;
use IgniterLabs\SquarePOS\Classes\RemoteMapping;
use IgniterLabs\SquarePOS\Exceptions\SyncFailed;
use IgniterLabs\SquarePOS\Models\Cache;
use IgniterLabs\SquarePOS\Models\SyncLog;

class RefreshCategoryMappings extends AbstractSyncPipe
{
    public function run(SyncLog $syncLog)
    {
        if (!$categoryIds = $syncLog->getConfig('categoryIds', []))
            return;

        // Pull categories local mapping
        $mappings = Cache::getCategoryMappings($categoryIds);
        $mappedCategories = RemoteMapping::createAll($mappings);
        $unmappedCategoriesIds = collect($categoryIds)->diff($mappings->keys());

        if ($mappedCategories->isNotEmpty()) {
            $response = $this->apiManager->batchRetrieveCatalogObjects(
                collect($mappedCategories)->pluck('squareId')->all()
            );

            $catalogObjects = collect($response->getResult()->getObjects())
                ->keyBy(function ($catalogObject) {
                    return $catalogObject->getId();
                });

            $mappedCategories
                ->filter(function (RemoteMapping $mapping) use ($catalogObjects) {
                    if ($catalogObject = $catalogObjects->get($mapping->squareId)) {
                        $mapping
                            ->set('squareVersion', $catalogObject->getVersion())
                            ->updateCache();

                        return FALSE;
                    }

                    return TRUE;
                })->each(function (RemoteMapping $mapping) use ($unmappedCategoriesIds) {
                    $unmappedCategoriesIds->push($mapping->modelId);
                    $mapping->forgetCache();
                });
        }

        if ($unmappedCategoriesIds->isNotEmpty()) {
            $response = $this->apiManager->searchCatalogObjects([
                'object_types' => ['CATEGORY'],
                'cursor' => $syncLog->getConfig('unmappedCategoriesCursor'),
                'limit' => $syncLog->batchSize(),
            ]);

            if (!$response->isSuccess())
                throw new SyncFailed($response->getErrors());

            $catalogObjects = collect($response->getResult()->getObjects())
                ->keyBy(function ($catalogObject) {
                    return strtolower($catalogObject->getCategoryData()->getName());
                });

            Categories_model::query()
                ->whereIn('category_id', $unmappedCategoriesIds)
                ->get()
                ->each(function ($category) use ($catalogObjects) {
                    $catalogObject = $catalogObjects->get(strtolower($category->name));

                    RemoteMapping::create([
                        'modelId' => $category->getKey(),
                        'squareId' => $catalogObject ? $catalogObject->getId() : '#'.$category->getMorphClass().'-'.$category->getKey(),
                        'squareVersion' => $catalogObject ? $catalogObject->getVersion() : null,
                    ]);
                });

            $syncLog->setConfig('unmappedCategoriesCursor', $response->getResult()->getCursor());
        }

        Cache::categoryMap(RemoteMapping::$mappingsCache);
    }
}
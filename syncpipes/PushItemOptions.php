<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Menu_item_options_model;
use IgniterLabs\SquarePOS\Classes\CatalogFactory;
use IgniterLabs\SquarePOS\Classes\RemoteMapping;
use IgniterLabs\SquarePOS\Models\Cache;
use IgniterLabs\SquarePOS\Models\SyncLog;

class PushItemOptions extends AbstractSyncPipe
{
    public function run(SyncLog $syncLog)
    {
        if (!$itemOptionsIds = $syncLog->getConfig('itemOptionsIds', []))
            return;

        $catalogFactory = CatalogFactory::instance();
        $idempotencyKey = md5($syncLog->uuid).'_upsert_options';

        $mappings = RemoteMapping::createAll(
            Cache::getOptionMappings($itemOptionsIds)
        );

        $batches = Menu_item_options_model::query()
            ->with(['menu_option_values.option_value', 'option', 'menu.locations'])
            ->whereIn('menu_option_id', $itemOptionsIds)
            ->get()
            ->map(function ($itemOption) use ($mappings, $catalogFactory) {
                if (!$mapping = $mappings->get($itemOption->getKey()))
                    return FALSE;

                return $catalogFactory->makeModifierListBatch($itemOption, $mapping);
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
                $modifierObjects = collect(optional($catalogObject->getModifierListData())->getModifiers())
                    ->keyBy(function ($catalogObject) {
                        return $catalogObject->getId();
                    });

                $values = [];
                foreach ($mapping->get('values', []) as $key => $value) {
                    if ($idMapping = $idMappings->get($value['squareId'])) {
                        $value['squareId'] = $idMapping->getObjectId();
                    }

                    if ($modifierObject = $modifierObjects->get($value['squareId'])) {
                        $value['squareVersion'] = $modifierObject->getVersion();
                    }

                    $values[$key] = $value;
                }

                $mapping->set('squareVersion', $catalogObject->getVersion());
                $mapping->set('values', $values);
            }

            $mapping->updateCache();
        });

        Cache::optionMap(RemoteMapping::$mappingsCache);
    }
}
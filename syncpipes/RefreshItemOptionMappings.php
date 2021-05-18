<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Menu_item_options_model;
use IgniterLabs\SquarePOS\Classes\RemoteMapping;
use IgniterLabs\SquarePOS\Exceptions\SyncFailed;
use IgniterLabs\SquarePOS\Models\Cache;
use IgniterLabs\SquarePOS\Models\SyncLog;

class RefreshItemOptionMappings extends AbstractSyncPipe
{
    public function run(SyncLog $syncLog)
    {
        if (!$itemOptionsIds = $syncLog->getConfig('itemOptionsIds', []))
            return;

        // Pull categories local mapping
        $mappings = Cache::getOptionMappings($itemOptionsIds);
        $mappedOptions = RemoteMapping::createAll($mappings);
        $unmappedOptionsIds = collect($itemOptionsIds)->diff($mappings->keys());

        if ($mappedOptions->isNotEmpty()) {
            $response = $this->apiManager->batchRetrieveCatalogObjects(
                collect($mappedOptions)->pluck('squareId')->all(), TRUE
            );

            $catalogObjects = collect($response->getResult()->getObjects())
                ->keyBy(function ($catalogObject) {
                    return $catalogObject->getId();
                });

            $mappedOptions
                ->filter(function ($mapping) use ($catalogObjects) {
                    if ($catalogObject = $catalogObjects->get($mapping->squareId)) {
                        $mapping->set('squareVersion', $catalogObject->getVersion());

                        $values = [];
                        $valueMappings = collect($mapping->values)->keyBy('squareId');
                        $catalogModifiers = $catalogObject->getModifierListData()->getModifiers() ?? [];
                        foreach ($catalogModifiers as $catalogModifier) {
                            if (!$valueMapping = $valueMappings->get($catalogModifier->getId()))
                                continue;

                            $values[$valueMapping['modelId']] = array_merge($valueMapping, [
                                'squareVersion' => $catalogModifier ? $catalogModifier->getVersion() : null,
                            ]);
                        }

                        $mapping->set('values', $values);
                        $mapping->updateCache();

                        return FALSE;
                    }

                    return TRUE;
                })->each(function ($mapping) use ($unmappedOptionsIds) {
                    $unmappedOptionsIds->push($mapping->modelId);
                    $mapping->forget();
                });
        }

        if ($unmappedOptionsIds->isNotEmpty()) {
            $response = $this->apiManager->searchCatalogObjects([
                'object_types' => ['MODIFIER_LIST'],
                'cursor' => $syncLog->getConfig('unmappedOptionsCursor'),
                'limit' => $syncLog->batchSize(),
            ], TRUE);

            if (!$response->isSuccess())
                throw new SyncFailed($response->getErrors());

            $catalogObjects = collect($response->getResult()->getObjects())
                ->keyBy(function ($catalogObject) {
                    return strtolower($catalogObject->getModifierListData()->getName());
                });

            Menu_item_options_model::query()
                ->with(['menu_option_values.option_value', 'option'])
                ->whereIn('menu_option_id', $unmappedOptionsIds)
                ->get()
                ->each(function ($itemOption) use ($catalogObjects) {
                    $catalogObject = $catalogObjects->get(strtolower($itemOption->option->name));

                    $modifierObjects = collect(optional(optional($catalogObject)->getModifierListData())->getModifiers())
                        ->keyBy(function ($catalogObject) {
                            return strtolower($catalogObject->getModifierData()->getName());
                        });

                    $values = [];
                    foreach ($itemOption->menu_option_values ?? [] as $itemOptionValue) {
                        $modifierObject = $modifierObjects->get(strtolower($itemOptionValue->option_value->value));
                        $values[$itemOptionValue->menu_option_value_id] = [
                            'modelId' => $itemOptionValue->menu_option_value_id,
                            'optionId' => $itemOption->getKey(),
                            'squareId' => $modifierObject ? $modifierObject->getId() : '#'.$itemOptionValue->getMorphClass().'-'.$itemOptionValue->getKey(),
                            'squareVersion' => $modifierObject ? $modifierObject->getVersion() : null,
                        ];
                    }

                    RemoteMapping::create([
                        'modelId' => $itemOption->getKey(),
                        'itemModelId' => $itemOption->menu_id,
                        'squareId' => $catalogObject ? $catalogObject->getId() : '#'.$itemOption->getMorphClass().'-'.$itemOption->getKey(),
                        'squareVersion' => $catalogObject ? $catalogObject->getVersion() : null,
                        'values' => $values,
                    ]);
                });

            $syncLog->setConfig('unmappedOptionsCursor', $response->getResult()->getCursor());
        }

        Cache::optionMap(RemoteMapping::$mappingsCache);
    }
}
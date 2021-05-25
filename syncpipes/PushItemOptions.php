<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use IgniterLabs\SquarePOS\Classes\CatalogFactory;
use IgniterLabs\SquarePOS\Classes\Manager;
use IgniterLabs\SquarePOS\Models\MenuItemOption;
use IgniterLabs\SquarePOS\Models\MenuItemOptionValue;
use IgniterLabs\SquarePOS\Models\SyncLog;
use Illuminate\Support\Collection;
use Square\Models\CatalogObjectBatch;

class PushItemOptions
{
    protected $mappings = [];

    protected $valueMappings = [];

    public function run(SyncLog $syncLog)
    {
        $idempotencyKey = md5($syncLog->uuid).'_upsert_options';

        $batches = MenuItemOption::query()
            ->with(['menu_option_values.option_value', 'option', 'menu.locations'])
            ->get()
            ->chunk($syncLog->getConfig('batchSize'))
            ->each(function ($itemOptions) {
                return $this->updateItemOptionVersions($itemOptions);
            })
            ->map(function ($itemOptions) {
                return $this->makeModifierListBatch($itemOptions);
            })
            ->filter()
            ->all();

        $manager = Manager::instance();
        $response = $manager->batchUpsertCatalogObjects($idempotencyKey, $batches);

        $modifierVersions = collect($this->mappings)
            ->mapWithKeys(function ($mapping) use ($response, $manager) {
                $catalogObject = $manager->updateMapping($mapping, $response);

                if ($mapping->model->isDirty(['square_id', 'square_version']))
                    $mapping->model->save();

                if (!$catalogObject)
                    return [];

                $modifierObjects = $catalogObject->getModifierListData()->getModifiers();

                return collect($modifierObjects ?? [])
                    ->mapWithKeys(function ($modifierObject) {
                        return [$modifierObject->getId() => $modifierObject->getVersion()];
                    })->all();
            });

        collect($this->valueMappings)
            ->each(function ($mapping) use ($response, $modifierVersions) {
                if ($idMapping = $response->getMapping($mapping->squareId))
                    $mapping->model->square_id = $idMapping->getObjectId();

                if ($modifierVersion = $modifierVersions->get($mapping->model->square_id))
                    $mapping->model->square_version = $modifierVersion;

                if ($mapping->model->isDirty(['square_id', 'square_version']))
                    $mapping->model->save();
            });
    }

    protected function updateItemOptionVersions(Collection $itemOptions)
    {
        $mappedItemOptionsIds = $itemOptions->whereNotNull('square_id');

        if ($mappedItemOptionsIds->isNotEmpty()) {
            $response = Manager::instance()->batchRetrieveCatalogObjects(
                $mappedItemOptionsIds->pluck('square_id')->all()
            );

            $mappedItemOptionsIds
                ->filter(function ($itemOption) use ($response) {
                    if (!$catalogObject = $response->getObject($itemOption->square_id))
                        return TRUE;

                    if ((int)$itemOption->square_version !== $catalogObject->getVersion()) {
                        $itemOption->square_version = $catalogObject->getVersion();
                        $itemOption->save();
                    }

                    return FALSE;
                });
        }

        return $itemOptions;
    }

    protected function makeModifierListBatch(Collection $itemOptions)
    {
        $batch = $itemOptions->map(function (MenuItemOption $itemOption) {
            $itemObject = $itemOption->asSquareMenuItemOption();

            $itemObject->getModifierListData()->setModifiers(
                $this->makeModifierObjects($itemOption)
            );

            $this->mappings[$itemOption->square_id] = (object)[
                'modelId' => $itemOption->getKey(),
                'squareId' => $itemOption->square_id,
                'squareVersion' => $itemOption->square_version,
                'model' => $itemOption,
            ];

            return $itemObject;
        })->all();

        return new CatalogObjectBatch($batch);
    }

    protected function makeModifierObjects($itemOption)
    {
        return collect($itemOption->menu_option_values ?? [])
            ->map(function (MenuItemOptionValue $itemOptionValue) {
                $modifierObject = $itemOptionValue->asSquareMenuItemOptionValue();

                $this->valueMappings[$itemOptionValue->square_id] = (object)[
                    'modelId' => $itemOptionValue->getKey(),
                    'squareId' => $itemOptionValue->square_id,
                    'squareVersion' => $itemOptionValue->square_version,
                    'model' => $itemOptionValue,
                ];

                return $modifierObject;
            })->all();
    }
}

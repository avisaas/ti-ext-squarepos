<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Igniter\Admin\Models\MenuOption;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use Illuminate\Support\Collection;
use Square\Models\CatalogObjectBatch;

class PushItemOptions extends AbstractSyncPipe
{
    public function execute()
    {
        MenuOption::squareExportItems($this->syncTask, function ($menuOptions) {
            $this->syncTask->note('Exporting menu options into square');

            $menuOptions = $this->removeCleanVersions($menuOptions);

            if ($menuOptions->isNotEmpty()) {
                $batch = $this->makeCatalogBatch($menuOptions);

                $response = $this->square->batchUpsertCatalogObjects([$batch]);

                $this->updateSquareAttributes($menuOptions, $response);
            }
        });
    }

    protected function removeCleanVersions(Collection $exportItems)
    {
        if (!$mappedIds = $exportItems->whereNotNull('square_id')->pluck('square_id')->all()) {
            return $exportItems;
        }

        $response = $this->square->batchRetrieveCatalogObjects($mappedIds);

        return $exportItems->filter(function ($exportItem) use ($response) {
            if (is_null($exportItem->square_id)) {
                return true;
            }

            $catalogObject = $response->getObject($exportItem->square_id);
            $squareVersion = $catalogObject ? $catalogObject->getVersion() : null;

            if ((int)$exportItem->square_version === $squareVersion) {
                return false;
            }

            $exportItem->square_id = $catalogObject ? $catalogObject->getId() : null;
            $exportItem->square_version = $squareVersion;

            if ($exportItem->hasRelation('option_values')) {
                $exportItem->option_values = $this->removeCleanVersions($exportItem->option_values);
            }

            return true;
        });
    }

    protected function makeCatalogBatch(Collection $menuOptions)
    {
        $batch = $menuOptions->map(function ($menuOption) {
            $itemObject = $menuOption->asSquareModifierList();

            $modifiers = collect($menuOption->option_values ?? [])
                ->map(function ($menuOptionValue) {
                    return $menuOptionValue->asSquareModifier();
                })->all();

            $itemObject->getModifierListData()->setModifiers($modifiers);

            return $itemObject;
        })->values()->all();

        return new CatalogObjectBatch($batch);
    }

    protected function updateSquareAttributes($menuOptions, CatalogResponse $response)
    {
        $menuOptions->each(function ($menuOption) use ($response) {
            if ($idMapping = $response->getMapping($menuOption->getSquareId())) {
                $menuOption->square_id = $idMapping->getObjectId();
            }

            $catalogObject = $response->getObject($menuOption->square_id);
            if ((int)$menuOption->square_version !== $catalogObject->getVersion()) {
                $menuOption->square_version = $catalogObject->getVersion();
            }

            if ($menuOption->save()) {
                $this->syncTask->noteUpdated($menuOption, $catalogObject->getModifierListData()->getName());
            }

            $this->updateValueSquareAttributes($menuOption->option_values, $response, $catalogObject);
        });
    }

    protected function updateValueSquareAttributes($menuOptionValues, $response, $catalogObject)
    {
        $modifiers = collect($catalogObject->getModifierListData()->getModifiers())->keyBy(function ($catalogObject) {
            return $catalogObject->getId();
        });

        $menuOptionValues->each(function ($menuOptionValue) use ($response, $modifiers) {
            if ($idMapping = $response->getMapping($menuOptionValue->getSquareId())) {
                $menuOptionValue->square_id = $idMapping->getObjectId();
            }

            $modifierObject = $modifiers->get($menuOptionValue->square_id);
            if ((int)$menuOptionValue->square_version !== $modifierObject->getVersion()) {
                $menuOptionValue->square_version = $modifierObject->getVersion();
            }

            if ($menuOptionValue->save()) {
                $this->syncTask->noteUpdated($menuOptionValue, $modifierObject->getModifierData()->getName());
            }
        });
    }
}

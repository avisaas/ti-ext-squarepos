<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use Igniter\Cart\Models\MenuOption;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Jobs\Concerns\FiltersCleanVersions;
use IgniterLabs\SquarePOS\Models\SquareLink;
use Illuminate\Support\Collection;
use Square\Types\CatalogObjectBatch;

class PushItemOptionsJob extends AbstractSyncJob
{
    use FiltersCleanVersions;

    public function execute(): void
    {
        MenuOption::squareExportItems($this->syncTask, function($menuOptions): void {
            $this->syncTask->note('Exporting menu options into square');

            $menuOptions = $this->removeCleanVersions($menuOptions);

            if ($menuOptions->isNotEmpty()) {
                $batch = $this->makeCatalogBatch($menuOptions);

                $response = $this->square->batchUpsertCatalogObjects([$batch]);

                $this->updateSquareAttributes($menuOptions, $response);
            }
        });
    }

    protected function removeCleanVersions(Collection $exportItems): Collection
    {
        $mappedIds = $exportItems->map(fn($exportItem) => $exportItem->squareLink?->square_id)->filter()->values()->all();
        $response = $mappedIds ? $this->square->batchRetrieveCatalogObjects($mappedIds, true) : null;

        return $this->keepChangedRecords($exportItems, $response, function($exportItem) {
            $values = $exportItem->relationLoaded('option_values') ? $exportItem->option_values : collect();

            return $values->contains(fn($value): bool => (bool)$value->squareLink?->square_dirty || !$value->squareLink?->square_id);
        });
    }

    protected function makeCatalogBatch(Collection $menuOptions): CatalogObjectBatch
    {
        $batch = $menuOptions->map(function($menuOption) {
            $itemObject = $menuOption->asSquareModifierList();

            $modifiers = collect($menuOption->option_values ?? [])
                ->map(fn($menuOptionValue) => $menuOptionValue->asSquareModifier())->all();

            CatalogRecord::unwrap($itemObject)->getModifierListData()->setModifiers($modifiers);

            return $itemObject;
        })->values()->all();

        return new CatalogObjectBatch(['objects' => $batch]);
    }

    protected function updateSquareAttributes($menuOptions, CatalogResponse $response)
    {
        $menuOptions->each(function($menuOption) use ($response): void {
            $link = SquareLink::forOwner($menuOption);
            if ($idMapping = $response->getMapping($menuOption->getSquareId())) {
                $link->square_id = $idMapping->getObjectId();
            }

            $catalogObject = $response->getObject($link->square_id);
            if (!$catalogObject) {
                return;
            }

            $record = CatalogRecord::unwrap($catalogObject);
            $link->square_version = $record->getVersion();
            $link->square_dirty = false;
            SyncContext::applying(function() use ($menuOption): void {
                SquareLink::saveOwner($menuOption);
            });

            $this->syncTask->noteUpdated($menuOption, optional($record->getModifierListData())->getName() ?: $menuOption->option_name);

            $this->updateValueSquareAttributes($menuOption->option_values, $response, $catalogObject);
        });
    }

    protected function updateValueSquareAttributes($menuOptionValues, $response, object $catalogObject)
    {
        $modifiers = collect(CatalogRecord::unwrap($catalogObject)->getModifierListData()->getModifiers())->keyBy(
            fn(object $modifier) => CatalogRecord::unwrap($modifier)->getId()
        );

        $menuOptionValues->each(function($menuOptionValue) use ($response, $modifiers): void {
            $link = SquareLink::forOwner($menuOptionValue);
            if ($idMapping = $response->getMapping($menuOptionValue->getSquareId())) {
                $link->square_id = $idMapping->getObjectId();
            }

            $modifierObject = $modifiers->get($link->square_id);
            if (!$modifierObject) {
                return;
            }

            $modifier = CatalogRecord::unwrap($modifierObject);
            $link->square_version = $modifier->getVersion();
            $link->square_dirty = false;
            SyncContext::applying(function() use ($menuOptionValue): void {
                SquareLink::saveOwner($menuOptionValue);
            });

            $this->syncTask->noteUpdated($menuOptionValue, optional($modifier->getModifierData())->getName() ?: $menuOptionValue->name);
        });
    }
}

<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use Igniter\Cart\Models\Menu;
use IgniterLabs\SquarePOS\Classes\CatalogImageUploader;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Jobs\Concerns\FiltersCleanVersions;
use IgniterLabs\SquarePOS\Models\SquareLink;
use Illuminate\Support\Collection;
use Square\Types\CatalogObjectBatch;
use Throwable;

class PushItemsJob extends AbstractSyncJob
{
    use FiltersCleanVersions;

    public function execute(): void
    {
        Menu::squareExportItems($this->syncTask, function($menuItems): void {
            $this->syncTask->note('Exporting menu items into square');

            $menuItems = $this->removeCleanVersions($menuItems);

            if ($menuItems->isNotEmpty()) {
                $batch = $this->makeCatalogBatch($menuItems);

                $response = $this->square->batchUpsertCatalogObjects([$batch]);

                $this->updateSquareAttributes($menuItems, $response);
            }
        });
    }

    protected function removeCleanVersions(Collection $menuItems): Collection
    {
        $mappedIds = $menuItems->map(fn($menuItem) => $menuItem->squareLink?->square_id)->filter()->values()->all();
        $response = $mappedIds ? $this->square->batchRetrieveCatalogObjects($mappedIds, true) : null;

        return $this->keepChangedRecords($menuItems, $response, function($menuItem, $catalogObject): bool {
            $link = SquareLink::forOwner($menuItem);
            $variation = $menuItem->primaryVariation($catalogObject);
            if (!$variation) {
                $link->square_variation_id = null;
                $link->square_variation_version = null;

                return false;
            }

            $variationRecord = CatalogRecord::unwrap($variation);
            $differs = (int)$link->square_variation_version !== (int)$variationRecord->getVersion();
            $link->square_variation_id = $variationRecord->getId();
            $link->square_variation_version = $variationRecord->getVersion();

            return $differs;
        });
    }

    protected function makeCatalogBatch(Collection $menuItems): CatalogObjectBatch
    {
        $batch = $menuItems->map(fn($menuItem) => $menuItem->asSquareItem())->values()->all();

        return new CatalogObjectBatch(['objects' => $batch]);
    }

    protected function updateSquareAttributes(Collection $menuItems, CatalogResponse $response)
    {
        $menuItems->each(function($menuItem) use ($response): void {
            $link = SquareLink::forOwner($menuItem);
            if ($idMapping = $response->getMapping($menuItem->getSquareId())) {
                $link->square_id = $idMapping->getObjectId();
            }

            $catalogObject = $response->getObject($link->square_id);
            if (!$catalogObject) {
                return;
            }

            $record = CatalogRecord::unwrap($catalogObject);
            $link->square_version = $record->getVersion();
            if ($variationObject = $menuItem->primaryVariation($catalogObject)) {
                $variationRecord = CatalogRecord::unwrap($variationObject);
                $link->square_variation_id = $variationRecord->getId();
                $link->square_variation_version = $variationRecord->getVersion();
            }

            $link->square_dirty = false;
            SyncContext::applying(function() use ($menuItem): void {
                SquareLink::saveOwner($menuItem);
            });

            try {
                resolve(CatalogImageUploader::class)->pushMenuImage($this->square, $menuItem);
            } catch (Throwable $throwable) {
                $this->syncTask->note(sprintf('Image for %s was not uploaded: %s', $menuItem->menu_name, $throwable->getMessage()));
            }

            $this->syncTask->noteUpdated($menuItem, optional($record->getItemData())->getName() ?: $menuItem->menu_name);
        });
    }
}

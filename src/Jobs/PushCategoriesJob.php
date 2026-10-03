<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use Igniter\Cart\Models\Category;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Jobs\Concerns\FiltersCleanVersions;
use IgniterLabs\SquarePOS\Models\SquareLink;
use Illuminate\Support\Collection;
use Square\Types\CatalogObjectBatch;

class PushCategoriesJob extends AbstractSyncJob
{
    use FiltersCleanVersions;

    public function execute(): void
    {
        Category::squareExportItems($this->syncTask, function($categories): void {
            $this->syncTask->note('Exporting categories into square');

            $categories = $this->removeCleanVersions($categories);

            if ($categories->isNotEmpty()) {
                $batch = $this->makeCatalogBatch($categories);

                $response = $this->square->batchUpsertCatalogObjects([$batch]);

                $this->updateSquareAttributes($categories, $response);
            }
        });
    }

    protected function removeCleanVersions(Collection $categories): Collection
    {
        $mappedIds = $categories->map(fn($category) => $category->squareLink?->square_id)->filter()->values()->all();
        $response = $mappedIds ? $this->square->batchRetrieveCatalogObjects($mappedIds) : null;

        return $this->keepChangedRecords($categories, $response);
    }

    protected function makeCatalogBatch(Collection $categories): CatalogObjectBatch
    {
        $batch = $categories->map(fn($category) => $category->asSquareCategory())->values()->all();

        return new CatalogObjectBatch(['objects' => $batch]);
    }

    protected function updateSquareAttributes(Collection $categories, CatalogResponse $response)
    {
        $categories->each(function($category) use ($response): void {
            $link = SquareLink::forOwner($category);
            if ($idMapping = $response->getMapping($category->getSquareId())) {
                $link->square_id = $idMapping->getObjectId();
            }

            $catalogObject = $response->getObject($link->square_id);
            if (!$catalogObject) {
                return;
            }

            $record = CatalogRecord::unwrap($catalogObject);
            $link->square_version = $record->getVersion();
            $link->square_dirty = false;
            SyncContext::applying(function() use ($category): void {
                SquareLink::saveOwner($category);
            });

            $this->syncTask->noteUpdated($category, optional($record->getCategoryData())->getName() ?: $category->name);
        });
    }
}

<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Igniter\Admin\Models\Category;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use Illuminate\Support\Collection;
use Square\Models\CatalogObjectBatch;

class PushCategories extends AbstractSyncPipe
{
    public function execute()
    {
        Category::squareExportItems($this->syncTask, function ($categories) {
            $this->syncTask->note('Exporting categories into square');

            $categories = $this->removeCleanVersions($categories);

            if ($categories->isNotEmpty()) {
                $batch = $this->makeCatalogBatch($categories);

                $response = $this->square->batchUpsertCatalogObjects([$batch]);

                $this->updateSquareAttributes($categories, $response);
            }
        });
    }

    protected function removeCleanVersions(Collection $categories)
    {
        if (!$mappedIds = $categories->whereNotNull('square_id')->pluck('square_id')->all()) {
            return $categories;
        }

        $response = $this->square->batchRetrieveCatalogObjects($mappedIds);

        return $categories->filter(function ($category) use ($response) {
            if (is_null($category->square_id)) {
                return true;
            }

            $catalogObject = $response->getObject($category->square_id);
            $squareVersion = $catalogObject ? $catalogObject->getVersion() : null;

            if ((int)$category->square_version === $squareVersion) {
                return false;
            }

            $category->square_id = $catalogObject ? $catalogObject->getId() : null;
            $category->square_version = $squareVersion;

            return true;
        });
    }

    protected function makeCatalogBatch(Collection $categories)
    {
        $batch = $categories->map(function ($category) {
            return $category->asSquareCategory();
        })->values()->all();

        return new CatalogObjectBatch($batch);
    }

    protected function updateSquareAttributes(Collection $categories, CatalogResponse $response)
    {
        $categories->each(function ($category) use ($response) {
            if ($idMapping = $response->getMapping($category->getSquareId())) {
                $category->square_id = $idMapping->getObjectId();
            }

            $catalogObject = $response->getObject($category->square_id);
            if ((int)$category->square_version !== $catalogObject->getVersion()) {
                $category->square_version = $catalogObject->getVersion();
            }

            if ($category->save()) {
                $this->syncTask->noteUpdated($category, $catalogObject->getCategoryData()->getName());
            }
        });
    }
}

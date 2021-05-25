<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use IgniterLabs\SquarePOS\Classes\CatalogFactory;
use IgniterLabs\SquarePOS\Classes\Manager;
use IgniterLabs\SquarePOS\Models\Categories_model;
use IgniterLabs\SquarePOS\Models\SyncLog;
use Illuminate\Support\Collection;
use Square\Models\CatalogObjectBatch;

class PushCategories
{
    protected $mappings = [];

    public function run(SyncLog $syncLog)
    {
        $idempotencyKey = md5($syncLog->uuid).'_upsert_categories';

        $batches = Categories_model::query()
            ->get()
            ->chunk($syncLog->getConfig('batchSize'))
            ->each(function ($categories) {
                return $this->updateCategoryVersions($categories);
            })
            ->map(function ($categories) {
                return $this->makeCategoryBatch($categories);
            })
            ->filter()
            ->all();

        $manager = Manager::instance();
        $response = $manager->batchUpsertCatalogObjects($idempotencyKey, $batches);

        collect($this->mappings)
            ->each(function ($mapping) use ($response, $manager) {
                $manager->updateMapping($mapping, $response);

                if ($mapping->model->isDirty(['square_id', 'square_version']))
                    $mapping->model->save();
            });
    }

    protected function updateCategoryVersions(Collection $categories)
    {
        $mappedCategoryIds = $categories->whereNotNull('square_id');

        if ($mappedCategoryIds->isNotEmpty()) {
            $response = Manager::instance()->batchRetrieveCatalogObjects(
                $mappedCategoryIds->pluck('square_id')->all()
            );

            $mappedCategoryIds
                ->each(function ($category) use ($response) {
                    $catalogObject = $response->getObject($category->square_id);

                    $category->square_version = optional($catalogObject)->getVersion();
                    $category->save();
                });
        }

        return $categories;
    }

    protected function makeCategoryBatch(Collection $categories)
    {
        $batch = $categories->map(function (Categories_model $category) {
            $categoryObject = $category->asSquareCategory();

            $this->mappings[$category->getKey()] = (object)[
                'modelId' => $category->getKey(),
                'squareId' => $category->square_id,
                'squareVersion' => $category->square_version,
                'model' => $category,
            ];

            return $categoryObject;
        })->all();

        return new CatalogObjectBatch($batch);
    }
}

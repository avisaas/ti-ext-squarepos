<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use IgniterLabs\SquarePOS\Classes\CatalogFactory;
use IgniterLabs\SquarePOS\Classes\Manager;
use IgniterLabs\SquarePOS\Models\Menus_model;
use IgniterLabs\SquarePOS\Models\SyncLog;
use Illuminate\Support\Collection;
use Square\Models\CatalogObjectBatch;

class PushItems
{
    protected $mappings = [];

    public function run(SyncLog $syncLog)
    {
        $idempotencyKey = md5($syncLog->uuid).'_upsert_items';

        $batches = Menus_model::query()
            ->with(['menu_options.menu_option_values', 'locations'])
            ->get()
            ->chunk($syncLog->getConfig('batchSize'))
            ->each(function ($menuItems) {
                return $this->updateItemVersions($menuItems);
            })
            ->map(function ($menuItems) {
                return $this->makeItemBatch($menuItems);
            })
            ->filter()
            ->all();

        $manager = Manager::instance();
        $response = $manager->batchUpsertCatalogObjects($idempotencyKey, $batches);

        collect($this->mappings)
            ->each(function ($mapping) use ($response, $manager) {
                $catalogObject = $manager->updateMapping($mapping, $response);

                $variationObject = optional($catalogObject->getItemData())->getVariations()[0];
                if ($variationObject) {
                    $mapping->model->square_variation_id = $variationObject->getId();
                    $mapping->model->square_variation_version = $variationObject->getVersion();
                }

                $mapping->model->save();
            });
    }

    public function updateItemVersions(Collection $menuItems)
    {
        $menuItems->each(function ($menuItem) {
        });

        return $menuItems;
    }

    public function makeItemBatch(Collection $menuItems)
    {
        $batch = $menuItems->map(function (Menus_model $menuItem) {
            $itemObject = $menuItem->asSquareMenuItem();

            $this->mappings[$menuItem->getKey()] = (object)[
                'modelId' => $menuItem->getKey(),
                'squareId' => $menuItem->square_id,
                'squareVersion' => $menuItem->square_version,
                'squareVariationId' => $menuItem->square_variation_id,
                'squareVariationVersion' => $menuItem->square_variation_version,
                'model' => $menuItem,
            ];

            return $itemObject;
        })->all();

        return new CatalogObjectBatch($batch);
    }
}

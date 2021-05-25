<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use IgniterLabs\SquarePOS\Classes\Manager;
use IgniterLabs\SquarePOS\Models\Categories_model;
use IgniterLabs\SquarePOS\Models\SyncLog;

class PullCategories extends AbstractSyncPipe
{
    public function run(SyncLog $syncLog)
    {
        $response = Manager::instance()->searchCatalogObjects([
            'object_types' => ['CATEGORY'],
            'cursor' => $syncLog->getConfig('categoriesCursor'),
            'limit' => $syncLog->batchSize(),
        ]);

        $response->getObjects()->each(function ($catalogObject, $squareId) {
            Categories_model::updateOrCreate([
                'square_id' => $squareId,
            ], [
                'name' => $catalogObject->getCategoryData()->getName(),
                'square_version' => $catalogObject->getVersion(),
            ]);
        });
    }
}

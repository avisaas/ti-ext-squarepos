<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Categories_model;

class PullCategories extends AbstractSyncPipe
{
    public function execute()
    {
        $options = [
            'object_types' => ['CATEGORY'],
            'limit' => $this->syncTask->batchSize(),
            'cursor' => $this->syncTask->getConfigData('categoryCursor'),
            'beginTime' => $this->syncTask->getConfigData('beginTimes.category'),
        ];

        $response = $this->square->searchCatalogObjects($options);

        $nextBeingTime = now()->toISOString();

        Categories_model::squareImportItems($response->getObjects(), function ($catalogObject, $model) {
            $this->syncTask->note('Importing categories from square');

            if ($catalogObject->getIsDeleted()) {
                if ($model->newQuery()->where('square_id', $catalogObject->getId())->delete()) {
                    $this->syncTask->noteDeleted($catalogObject, $catalogObject->getCategoryData()->getName());
                }
            } else {
                $category = $model->createFromSquareCategory($catalogObject);

                $category->squareAttachLocation($catalogObject);

                $this->syncTask->noteUpdated($category, $catalogObject->getCategoryData()->getName());
            }
        });

        $this->handleResponseCursor($response, 'categoryCursor');

        $this->syncTask->setConfigData('beginTimes.category', $nextBeingTime)->save();
    }
}

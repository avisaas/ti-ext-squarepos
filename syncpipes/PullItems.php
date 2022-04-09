<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Menus_model;
use Square\Models\CatalogObject;

class PullItems extends AbstractSyncPipe
{
    public function execute()
    {
        $options = [
            'object_types' => ['ITEM'],
            'cursor' => $this->syncTask->getConfigData('itemCursor'),
            'limit' => $this->syncTask->batchSize(),
            'beginTime' => $this->syncTask->getConfigData('beginTimes.item'),
        ];

        $nextBeingTime = now()->toISOString();

        $response = $this->square->searchCatalogObjects($options, true);

        $this->syncTask->setConfigData('beginTimes.item', now()->toISOString())->save();

        Menus_model::squareImportItems($response->getObjects(), function (CatalogObject $catalogObject, $model) {
            $this->syncTask->note('Importing menu items from square');

            if ($catalogObject->getIsDeleted()) {
                if ($model->newQuery()->where('square_id', $catalogObject->getId())->delete()) {
                    $this->syncTask->noteDeleted($catalogObject, $catalogObject->getCategoryData()->getName());
                }

                return true;
            }

            $menuItem = $model->createFromSquareItem($catalogObject);

            $menuItem->squareAttachLocation($catalogObject);

            // attach categories

            // attach stocks

            // attach menu item options
        });

        $this->handleResponseCursor($response, 'itemCursor');

        $this->syncTask->setConfigData('beginTimes.modifier', $nextBeingTime)->save();
    }
}

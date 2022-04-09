<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Menu_options_model;
use Square\Models\CatalogObject;

class PullItemOptions extends AbstractSyncPipe
{
    public function execute()
    {
        $options = [
            'object_types' => ['MODIFIER_LIST'],
            'cursor' => $this->syncTask->getConfigData('modifierCursor'),
            'limit' => $this->syncTask->batchSize(),
            'beginTime' => $this->syncTask->getConfigData('beginTimes.modifier'),
        ];

        $nextBeingTime = now()->toISOString();

        $response = $this->square->searchCatalogObjects($options, true);

        Menu_options_model::squareImportItems($response->getObjects(), function (CatalogObject $catalogObject, $model) {
            $this->syncTask->note('Importing menu options from square');

            if ($catalogObject->getIsDeleted()) {
                $model->newQuery()->where('square_id', $catalogObject->getId())->delete();
            } else {
                $menuOption = $model->createFromSquareModifierList($catalogObject);

                $menuOption->squareAttachLocation($catalogObject);
            }
        });

        $this->handleResponseCursor($response, 'modifierCursor');

        $this->syncTask->setConfigData('beginTimes.modifier', $nextBeingTime)->save();
    }
}

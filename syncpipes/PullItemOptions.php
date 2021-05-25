<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use IgniterLabs\SquarePOS\Classes\Manager;
use IgniterLabs\SquarePOS\Models\MenuItemOption;
use IgniterLabs\SquarePOS\Models\SyncLog;

class PullItemOptions extends AbstractSyncPipe
{
    public function run(SyncLog $syncLog)
    {
        $response = Manager::instance()->searchCatalogObjects([
            'object_types' => ['MODIFIER_LIST', 'MODIFIER'],
            'cursor' => $syncLog->getConfig('categoriesCursor'),
            'limit' => $syncLog->batchSize(),
            'include_related_objects' => TRUE,
        ]);

        $response->getObjects()->each(function ($catalogObject, $squareId) {
            $menuOption = MenuItemOption::updateOrCreate([
                'square_id' => $squareId,
            ], [
                'name' => $catalogObject->getModifierListData()->getName(),
                'priority' => $catalogObject->getModifierListData()->getOrdinal(),
                'display_type' => $catalogObject->getModifierListData()->getSelectionType() === 'MULTIPLE' ? 'checkbox' : 'radio',
                'square_version' => $catalogObject->getVersion(),
            ]);
        });
    }
}

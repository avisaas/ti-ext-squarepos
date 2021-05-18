<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Menus_model;
use IgniterLabs\SquarePOS\Models\SyncLog;

class ExtractItemOptionsIds extends AbstractSyncPipe
{

    public function run(SyncLog $syncLog)
    {
        if (!$itemIds = $syncLog->getConfig('itemIds', []))
            return;

        $itemOptionsIds = [];

        $items = Menus_model::query()
            ->with(['menu_options'])
            ->whereIn('menu_id', $itemIds)
            ->get();

        foreach ($items as $item) {
            foreach ($item->menu_options ?? [] as $itemOption) {
                $itemOptionsIds[] = $itemOption->menu_option_id;
            }
        }

        $syncLog->setConfig('itemOptionsIds', $itemOptionsIds);
    }
}
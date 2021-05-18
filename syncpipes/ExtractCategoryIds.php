<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Menus_model;
use IgniterLabs\SquarePOS\Models\SyncLog;

class ExtractCategoryIds extends AbstractSyncPipe
{
    public function run(SyncLog $syncLog)
    {
        $itemIds = $syncLog->getConfig('itemIds', []);

        $categoryIds = [];

        $items = Menus_model::query()
            ->with(['categories'])
            ->whereIn('menu_id', $itemIds)
            ->get();

        foreach ($items as $item) {
            $menuCategories = $item->categories->pluck('category_id')->all();
            foreach ($menuCategories as $key => $value) {
                $categoryIds[] = $value;
            }
        }

        $syncLog->setConfig('categoryIds', $categoryIds);
    }
}
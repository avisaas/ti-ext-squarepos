<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use Igniter\Cart\Models\Menu;
use Igniter\Cart\Models\Stock;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SquareStock;

class PullStocksJob extends AbstractSyncJob
{
    public function execute(): void
    {
        if (!$this->syncTask->getConfigData('syncInventory')) {
            return;
        }

        $locationIds = array_values(array_filter($this->syncTask->mappedLocationIds()));
        if ($locationIds === []) {
            $this->syncTask->note('Inventory pull skipped because no locations are mapped');

            return;
        }

        $menus = Menu::query()
            ->with('squareLink')
            ->whereHas('squareLink', function($query): void {
                $query->whereNotNull('square_variation_id')
                    ->where(function($query): void {
                        $query->where('square_sync', true)->orWhereNull('square_sync');
                    });
            })
            ->get()
            ->keyBy(fn($menu) => $menu->squareLink?->square_variation_id);

        if ($menus->isEmpty()) {
            return;
        }

        $this->syncTask->note('Importing inventory counts from square');

        foreach ($menus->keys()->chunk(100) as $variationIds) {
            $cursor = null;
            do {
                $result = $this->square->batchRetrieveInventoryCounts($variationIds->all(), $locationIds, $cursor);
                $this->applyCounts($result->getCounts() ?? [], $menus);
                $cursor = $result->getCursor();
            } while ($cursor);
        }
    }

    protected function applyCounts(array $counts, $menus): void
    {
        SyncContext::applying(function() use ($counts, $menus): void {
            foreach ($counts as $count) {
                if ($count->getState() !== 'IN_STOCK') {
                    continue;
                }

                $menu = $menus->get($count->getCatalogObjectId());
                $locationId = Settings::clientLocationId($count->getLocationId());
                if (!$menu || !$locationId) {
                    continue;
                }

                if ($this->stockAuthority($menu) === 'igniter' && $this->localStockIsDirty($menu, $locationId)) {
                    continue;
                }

                $quantity = (int)round((float)$count->getQuantity());
                $stock = Stock::query()->firstOrNew([
                    'stockable_type' => $menu->getMorphClass(),
                    'stockable_id' => $menu->getKey(),
                    'location_id' => $locationId,
                ]);

                if ($stock->exists && (int)$stock->quantity === $quantity) {
                    $synced = SquareStock::forOwner($stock);
                    $synced->square_synced_at = $stock->updated_at ?: now();
                    SquareStock::saveOwner($stock);

                    continue;
                }

                if (!$stock->exists) {
                    $stock->is_tracked = true;
                    $stock->quantity = 0;
                    $stock->save();
                } elseif (!$stock->is_tracked) {
                    $stock->is_tracked = true;
                    $stock->save();
                }

                $stock->updateStock($quantity, Stock::STATE_RECOUNT);
                $synced = SquareStock::forOwner($stock);
                $synced->square_synced_at = now();
                SquareStock::saveOwner($stock);
                $this->syncTask->note(sprintf(
                    'Stock for %s at location %s set to %s',
                    $menu->menu_name,
                    $locationId,
                    $quantity
                ));
            }
        });
    }

    protected function stockAuthority($menu): string
    {
        if ($menu->squareLink?->square_stock_mode === 'push') {
            return 'igniter';
        }

        if ($menu->squareLink?->square_stock_mode === 'square') {
            return 'square';
        }

        return $this->syncTask->isSystemOfRecordSquare() ? 'square' : 'igniter';
    }

    protected function localStockIsDirty($menu, $locationId): bool
    {
        $stock = Stock::query()
            ->where('stockable_type', $menu->getMorphClass())
            ->where('stockable_id', $menu->getKey())
            ->where('location_id', $locationId)
            ->first();

        if (!$stock) {
            return false;
        }

        if (!$stock->squareStock?->square_synced_at) {
            return true;
        }

        return $stock->updated_at && $stock->updated_at->gt($stock->squareStock->square_synced_at);
    }
}

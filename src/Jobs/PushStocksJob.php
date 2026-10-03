<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use Igniter\Cart\Models\Stock;
use IgniterLabs\SquarePOS\Models\SquareStock;

class PushStocksJob extends AbstractSyncJob
{
    public function execute(): void
    {
        if (!$this->syncTask->getConfigData('syncInventory')) {
            return;
        }

        Stock::squareExportItems($this->syncTask, function($stocks): void {
            $stocks = $this->stocksToPush($stocks);
            if ($stocks->isEmpty()) {
                return;
            }

            $this->syncTask->note('Exporting menu item stocks into square');

            $payloads = $stocks->map(fn($stock): array => [$stock, $stock->asSquareInventoryChange()])->filter(fn($payload): bool => $payload[1] !== null)->values();

            $payloads->map(fn($payload) => $payload[1])->chunk(100)->each(function($chunk): void {
                if ($chunk->isNotEmpty()) {
                    $this->square->batchChangeInventory($chunk->all());
                }
            });

            $payloads->each(function($payload): void {
                $synced = SquareStock::forOwner($payload[0]);
                $synced->square_synced_at = $payload[0]->updated_at ?: now();
                SquareStock::saveOwner($payload[0]);
            });
        });
    }

    protected function stocksToPush($stocks)
    {
        return $stocks->filter(function($stock) {
            $menu = $stock->stockable;
            if (!$menu?->squareLink?->square_variation_id || ($menu->squareLink && !$menu->squareLink->isSyncEnabled())) {
                return false;
            }

            if ($this->stockAuthority($menu) !== 'igniter') {
                return false;
            }

            $locations = $menu->getStockableLocations();
            if ($locations && $locations->isNotEmpty() && !$locations->pluck('location_id')->contains($stock->location_id)) {
                return false;
            }

            if (!$stock->squareStock?->square_synced_at || !$stock->updated_at) {
                return true;
            }

            return $stock->updated_at->gt($stock->squareStock->square_synced_at);
        })->values();
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
}

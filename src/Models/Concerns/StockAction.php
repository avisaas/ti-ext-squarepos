<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Models\Concerns;

use Igniter\Flame\Database\Model;
use Igniter\Flame\Traits\ExtensionTrait;
use Igniter\System\Actions\ModelAction;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use IgniterLabs\SquarePOS\Traits\ManagesSquareAttributes;
use Square\Types\InventoryChange;
use Square\Types\InventoryPhysicalCount;

class StockAction extends ModelAction
{
    use ExtensionTrait;
    use LocationHelpers;
    use ManagesSquareAttributes;

    public function __construct(?Model $model)
    {
        parent::__construct($model);

        $this->bootSquareStock();
    }

    public function squareExportItems(SyncTask $syncTask, callable $callback)
    {
        return $this->model->newQuery()
            ->with(['squareStock', 'stockable.squareLink', 'location'])
            ->whereNotNull('quantity')
            ->where('stockable_type', 'menus')
            ->whereExists(function($query): void {
                $query->selectRaw('1')
                    ->from('squarepos_links')
                    ->whereColumn('squarepos_links.linkable_id', 'stocks.stockable_id')
                    ->where('squarepos_links.linkable_type', 'menus')
                    ->whereNotNull('squarepos_links.square_variation_id');
            })
            ->whereHasOrDoesntHaveLocation(array_keys($syncTask->mappedLocationIds()))
            ->chunkById($syncTask->batchSize(), $callback);
    }

    public function getSquareId(): string
    {
        return '#temp_'.$this->model->getMorphClass().'_'.$this->model->getKey();
    }

    public function asSquareInventoryChange(): ?InventoryChange
    {
        $locationId = $this->squareLocationRemoteId();
        $variationId = optional(optional($this->model->stockable)->squareLink)->square_variation_id;
        if (!$locationId || !$variationId || $this->model->quantity === null) {
            return null;
        }

        return new InventoryChange([
            'type' => 'PHYSICAL_COUNT',
            'physicalCount' => new InventoryPhysicalCount([
                'catalogObjectId' => $variationId,
                'state' => 'IN_STOCK',
                'locationId' => $locationId,
                'quantity' => (string)(int)$this->model->quantity,
                'occurredAt' => now()->toISOString(),
            ]),
        ]);
    }
}

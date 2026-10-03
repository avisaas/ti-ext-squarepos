<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use Igniter\Cart\Models\Menu;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Jobs\Concerns\PullsCatalogPages;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SquareLink;
use Square\Types\CatalogObject;

class PullItemsJob extends AbstractSyncJob
{
    use PullsCatalogPages;

    public function execute(): void
    {
        $createMissing = (bool)$this->syncTask->getConfigData('createMissing');

        $this->pullCatalogPages('ITEM', 'itemCursor', 'item', true, function($response) use ($createMissing): void {
            SyncContext::applying(function() use ($response, $createMissing): void {
                Menu::squareImportItems($response->getObjects(), function(CatalogObject $catalogObject, $model) use ($response, $createMissing): true {
                    $record = CatalogRecord::unwrap($catalogObject);
                    $name = optional($record->getItemData())->getName() ?: $record->getId();
                    $this->syncTask->note('Importing menu items from square');

                    if ($record->getIsDeleted()) {
                        $menuItem = SquareLink::findOwner(Menu::class, $record->getId());
                        if (!$menuItem) {
                            return true;
                        }

                        if (Settings::disablesMissingItems()) {
                            $menuItem->menu_status = false;
                            $menuItem->save();
                            $link = SquareLink::forOwner($menuItem);
                            $link->square_sync = false;
                            SquareLink::saveOwner($menuItem);
                        } else {
                            $menuItem->delete();
                        }

                        $this->syncTask->noteDeleted($catalogObject, $name);

                        return true;
                    }

                    $menuItem = $model->createFromSquareItem($catalogObject, $createMissing);
                    if (!$menuItem) {
                        $variation = $model->primaryVariation($catalogObject);
                        $sku = $variation instanceof CatalogObject ? CatalogRecord::unwrap($variation)->getItemVariationData()?->getSku() : null;
                        if ($createMissing && $sku && SquareLink::query()->where('linkable_type', (new Menu)->getMorphClass())->where('square_sku', $sku)->where('square_id', '!=', $record->getId())->exists()) {
                            $this->syncTask->note(sprintf('%s skipped because SKU %s belongs to another Square item', $name, $sku));
                        } elseif ($createMissing && !$variation) {
                            $this->syncTask->note(sprintf('%s skipped because it has no variation', $name));
                        } elseif ($createMissing) {
                            $this->syncTask->note(sprintf('%s skipped because it has no SKU', $name));
                        }

                        return true;
                    }

                    $menuItem->syncSquareRelationships($catalogObject, $response);
                    $this->syncTask->noteUpdated($menuItem, $name);

                    return true;
                });
            });
        });
    }
}

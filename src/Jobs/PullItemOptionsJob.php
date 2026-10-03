<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use Igniter\Cart\Models\MenuOption;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Jobs\Concerns\PullsCatalogPages;
use IgniterLabs\SquarePOS\Models\SquareLink;
use Square\Types\CatalogObject;

class PullItemOptionsJob extends AbstractSyncJob
{
    use PullsCatalogPages;

    public function execute(): void
    {
        $this->pullCatalogPages('MODIFIER_LIST', 'modifierCursor', 'modifier', true, function($response): void {
            SyncContext::applying(function() use ($response): void {
                MenuOption::squareImportItems($response->getObjects(), function(CatalogObject $catalogObject, $model): void {
                    $record = CatalogRecord::unwrap($catalogObject);
                    $name = optional($record->getModifierListData())->getName() ?: $record->getId();
                    $this->syncTask->note('Importing menu options from square');

                    if ($record->getIsDeleted()) {
                        $menuOption = SquareLink::findOwner(MenuOption::class, $record->getId());
                        if ($menuOption && $menuOption->delete()) {
                            $this->syncTask->noteDeleted($catalogObject, $name);
                        }

                        return;
                    }

                    $menuOption = $model->createFromSquareModifierList($catalogObject);
                    $menuOption->squareAttachLocation($catalogObject);
                    $this->syncTask->noteUpdated($menuOption, $name);
                });
            });
        });
    }
}

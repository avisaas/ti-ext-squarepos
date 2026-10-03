<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use Igniter\Cart\Models\Category;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Jobs\Concerns\PullsCatalogPages;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SquareLink;

class PullCategoriesJob extends AbstractSyncJob
{
    use PullsCatalogPages;

    public function execute(): void
    {
        $this->pullCatalogPages('CATEGORY', 'categoryCursor', 'category', false, function($response): void {
            SyncContext::applying(function() use ($response): void {
                Category::squareImportItems($response->getObjects(), function(object $catalogObject, $model): void {
                    $record = CatalogRecord::unwrap($catalogObject);
                    $name = optional($record->getCategoryData())->getName() ?: $record->getId();
                    $this->syncTask->note('Importing categories from square');

                    if ($record->getIsDeleted()) {
                        $category = SquareLink::findOwner(Category::class, $record->getId());
                        if (!$category) {
                            return;
                        }

                        if (Settings::disablesMissingItems()) {
                            $category->status = false;
                            $category->save();
                        } else {
                            $category->delete();
                        }

                        $this->syncTask->noteDeleted($catalogObject, $name);

                        return;
                    }

                    $category = $model->createFromSquareCategory($catalogObject);
                    $category->squareAttachLocation($catalogObject);
                    $this->syncTask->noteUpdated($category, $name);
                });
            });
        });
    }
}

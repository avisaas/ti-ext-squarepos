<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Models\Concerns;

use Igniter\Flame\Database\Model;
use Igniter\Flame\Traits\ExtensionTrait;
use Igniter\System\Actions\ModelAction;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Models\SquareLink;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use IgniterLabs\SquarePOS\Traits\ManagesSquareAttributes;
use Illuminate\Support\Collection;
use Square\Types\CatalogCategory;
use Square\Types\CatalogObject;
use Square\Types\CatalogObjectCategory;

class CategoryAction extends ModelAction
{
    use ExtensionTrait;
    use LocationHelpers;
    use ManagesSquareAttributes;

    public function __construct(?Model $model)
    {
        parent::__construct($model);

        $this->bootSquareLink();
    }

    public function squareExportItems(SyncTask $syncTask, callable $callback): void
    {
        $this->model->newQuery()
            ->with(['squareLink', 'locations'])
            ->isEnabled()
            ->whereHasOrDoesntHaveLocation(array_keys($syncTask->mappedLocationIds()))
            ->chunkById($syncTask->batchSize(), $callback);
    }

    public function squareImportItems(Collection $catalogObjects, callable $callback): void
    {
        $catalogObjects->filter(fn(CatalogObject $catalogObject): bool => strtoupper($catalogObject->getType()) === 'CATEGORY')->each(function(CatalogObject $catalogObject, $squareId) use ($callback): void {
            $callback($catalogObject, $this->model);
        });
    }

    public function getSquareId()
    {
        return $this->model->squareLink?->square_id ?: '#temp_'.$this->model->getMorphClass().'_'.$this->model->getKey();
    }

    public function asSquareCategory(): CatalogObject
    {
        $category = new CatalogObjectCategory([
            'id' => (string)$this->getSquareId(),
            'categoryData' => new CatalogCategory([
                'name' => $this->model->name,
            ]),
        ]);
        CatalogRecord::version($category, $this->model->squareLink?->square_version);

        return CatalogObject::category($category);
    }

    public function createFromSquareCategory(CatalogObject $catalogObject)
    {
        return SyncContext::applying(function() use ($catalogObject) {
            $record = CatalogRecord::unwrap($catalogObject);
            $category = SquareLink::findOwner($this->model::class, $record->getId())
                ?: $this->model->newInstance();

            $category->name = optional($record->getCategoryData())->getName();
            if (!$category->exists) {
                $category->status = true;
            }

            $category->save();

            $link = SquareLink::forOwner($category);
            $link->square_id = $record->getId();
            $link->square_version = $record->getVersion();
            $link->square_dirty = false;
            SquareLink::saveOwner($category);

            return $category;
        });
    }
}

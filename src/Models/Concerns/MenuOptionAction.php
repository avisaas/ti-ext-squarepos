<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Models\Concerns;

use Igniter\Cart\Models\MenuOptionValue;
use Igniter\Flame\Database\Model;
use Igniter\Flame\Traits\ExtensionTrait;
use Igniter\System\Actions\ModelAction;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\MoneyAmount;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Models\SquareLink;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use IgniterLabs\SquarePOS\Traits\ManagesSquareAttributes;
use Illuminate\Support\Collection;
use Square\Types\CatalogModifierList;
use Square\Types\CatalogObject;
use Square\Types\CatalogObjectModifierList;

class MenuOptionAction extends ModelAction
{
    use ExtensionTrait;
    use LocationHelpers;
    use ManagesSquareAttributes;

    public function __construct(?Model $model)
    {
        parent::__construct($model);

        $this->bootSquareLink();
    }

    public function squareExportItems(SyncTask $syncTask, callable $callback)
    {
        return $this->model->newQuery()
            ->with(['squareLink', 'option_values.squareLink', 'locations'])
            ->whereIn('display_type', ['radio', 'checkbox', 'select'])
            ->whereHasOrDoesntHaveLocation(array_keys($syncTask->mappedLocationIds()))
            ->chunkById($syncTask->batchSize(), $callback);
    }

    public function squareImportItems(Collection $catalogObjects, callable $callback): void
    {
        $catalogObjects->filter(fn(CatalogObject $catalogObject): bool => strtoupper($catalogObject->getType()) === 'MODIFIER_LIST')->each(function(CatalogObject $catalogObject, $squareId) use ($callback): void {
            $callback($catalogObject, $this->model);
        });
    }

    public function getSquareId()
    {
        return $this->model->squareLink?->square_id ?: '#temp_'.$this->model->getMorphClass().'_'.$this->model->getKey();
    }

    public function asSquareModifierList(): CatalogObject
    {
        $modifierList = new CatalogObjectModifierList([
            'id' => (string)$this->getSquareId(),
            'modifierListData' => new CatalogModifierList([
                'name' => $this->model->option_name,
                'ordinal' => $this->model->priority,
                'selectionType' => $this->model->display_type === 'checkbox' ? 'MULTIPLE' : 'SINGLE',
            ]),
        ]);
        CatalogRecord::version($modifierList, $this->model->squareLink?->square_version);
        $this->squareApplyLocationIds($modifierList);

        return CatalogObject::modifierList($modifierList);
    }

    public function createFromSquareModifierList(CatalogObject $catalogObject)
    {
        return SyncContext::applying(function() use ($catalogObject) {
            $record = CatalogRecord::unwrap($catalogObject);
            $modifierList = $record->getModifierListData();
            $menuOption = SquareLink::findOwner($this->model::class, $record->getId())
                ?: $this->model->newInstance();

            $menuOption->option_name = $modifierList instanceof CatalogModifierList ? $modifierList->getName() : $record->getId();
            $menuOption->priority = (int)optional($modifierList)->getOrdinal();
            $menuOption->display_type = optional($modifierList)->getSelectionType() === 'MULTIPLE' ? 'checkbox' : 'radio';
            $menuOption->save();

            $link = SquareLink::forOwner($menuOption);
            $link->square_id = $record->getId();
            $link->square_version = $record->getVersion();
            $link->square_dirty = false;
            SquareLink::saveOwner($menuOption);

            $this->syncModifierValues($menuOption, $modifierList instanceof CatalogModifierList ? ($modifierList->getModifiers() ?? []) : null);

            return $menuOption;
        });
    }

    protected function syncModifierValues($menuOption, $modifiers): void
    {
        if ($modifiers === null) {
            return;
        }

        $keep = [];

        foreach ($modifiers ?? [] as $modifierObject) {
            $modifier = CatalogRecord::unwrap($modifierObject);
            $data = $modifier->getModifierData();
            $name = $data ? $data->getName() : null;
            if (!$name) {
                continue;
            }

            $optionValue = $menuOption->option_values()->whereHas('squareLink', function($query) use ($modifier): void {
                $query->where('square_id', $modifier->getId());
            })->first() ?: new MenuOptionValue;

            $optionValue->option_id = $menuOption->getKey();
            $optionValue->name = $name;
            $optionValue->price = MoneyAmount::fromCents(optional(optional($data)->getPriceMoney())->getAmount());
            $optionValue->priority = (int)optional($data)->getOrdinal();
            $optionValue->save();

            $link = SquareLink::forOwner($optionValue);
            $link->square_id = $modifier->getId();
            $link->square_version = $modifier->getVersion();
            $link->square_dirty = false;
            SquareLink::saveOwner($optionValue);

            $keep[] = $optionValue->getKey();
        }

        $staleValues = $menuOption->option_values();
        if ($keep !== []) {
            $staleValues->whereNotIn('option_value_id', $keep);
        }

        $staleIds = (clone $staleValues)->pluck('option_value_id');
        if ($staleIds->isNotEmpty()) {
            SquareLink::query()
                ->where('linkable_type', (new MenuOptionValue)->getMorphClass())
                ->whereIn('linkable_id', $staleIds)
                ->delete();
        }

        $staleValues->delete();
    }
}

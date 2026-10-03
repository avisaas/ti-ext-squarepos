<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Traits;

use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Models\Settings;

trait LocationHelpers
{
    public function squareLocationRemoteId()
    {
        if (!$this->model->location) {
            return null;
        }

        $mapping = Settings::locationMapping()
            ->keyBy('client_id')
            ->get($this->model->location->getKey(), []);

        return array_get($mapping, 'remote_id');
    }

    public function squareLocationRemoteIds(): array
    {
        $locationIds = [];
        if (!$this->model->locations || $this->model->locations->isEmpty()) {
            return $locationIds;
        }

        $locationMappings = Settings::locationMapping()->keyBy('client_id');
        foreach ($this->model->locations->pluck('location_id')->all() ?? [] as $locationId) {
            if ($mapping = $locationMappings->get($locationId)) {
                $locationIds[] = array_get($mapping, 'remote_id');
            }
        }

        return $locationIds;
    }

    public function squareApplyLocationIds(object $catalogObject): void
    {
        $locationIds = $this->model->squareLocationRemoteIds();
        $record = CatalogRecord::unwrap($catalogObject);
        $record->setPresentAtAllLocations($locationIds === []);
        $record->setPresentAtLocationIds($locationIds);
    }

    public function squareAttachLocation(object $catalogObject): ?bool
    {
        $record = CatalogRecord::unwrap($catalogObject);
        if ($record->getPresentAtAllLocations()) {
            return true;
        }

        $attachLocationIds = Settings::locationMapping()
            ->whereIn('remote_id', (array)$record->getPresentAtLocationIds())
            ->pluck('client_id')
            ->all();

        $this->model->locations()->sync($attachLocationIds);

        return null;
    }
}

<?php

namespace IgniterLabs\SquarePOS\Traits;

use IgniterLabs\SquarePOS\Models\Settings;
use Square\Models\CatalogObject;

trait LocationHelpers
{
    public function squareLocationRemoteId()
    {
        if (!$this->model->location)
            return null;

        $mapping = Settings::locationMapping()
            ->keyBy('client_id')
            ->get($this->model->location->getKey(), []);

        return array_get($mapping, 'remote_id');
    }

    public function squareLocationRemoteIds()
    {
        $locationIds = [];
        if (!$this->model->locations || $this->model->locations->isEmpty())
            return $locationIds;

        $locationMappings = Settings::locationMapping()->keyBy('client_id');
        foreach ($this->model->locations->pluck('location_id')->all() ?? [] as $locationId) {
            if ($mapping = $locationMappings->get($locationId)) {
                $locationIds[] = array_get($mapping, 'remote_id');
            }
        }

        return $locationIds;
    }

    public function squareApplyLocationIds(CatalogObject $catalogObject)
    {
        $locationIds = $this->model->squareLocationRemoteIds();

        $catalogObject->setPresentAtAllLocations(!$locationIds);
        $catalogObject->setPresentAtLocationIds($locationIds);
    }

    public function squareAttachLocation(CatalogObject $catalogObject)
    {
        if ($catalogObject->getPresentAtAllLocations())
            return TRUE;

        $attachLocationIds = Settings::locationMapping()
            ->whereIn('remote_id', (array)$catalogObject->getPresentAtLocationIds())
            ->pluck('client_id')
            ->all();

        $this->model->locations()->sync($attachLocationIds);
    }
}

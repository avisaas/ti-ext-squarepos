<?php

namespace IgniterLabs\SquarePOS\Traits;

use IgniterLabs\SquarePOS\Models\Settings;
use Square\Models\CatalogObject;

trait LocationHelpers
{
    protected function getLocationIdsFrom($model)
    {
        $locationIds = [];
        if (!$model->locations)
            return $locationIds;

        $locationMappings = Settings::locationMapping()->keyBy('client_id');
        foreach ($model->locations->pluck('location_id')->all() ?? [] as $locationId) {
            if ($mapping = $locationMappings->get($locationId)) {
                $locationIds[] = array_get($mapping, 'remote_id');
            }
        }

        return $locationIds;
    }

    protected function applyLocationIds(CatalogObject $catalogObject, $locationIds)
    {
        $catalogObject->setPresentAtAllLocations($locationIds ? FALSE : TRUE);
        $catalogObject->setPresentAtLocationIds($locationIds);
    }
}

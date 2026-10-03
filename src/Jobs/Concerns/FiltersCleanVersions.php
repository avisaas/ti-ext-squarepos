<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs\Concerns;

use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use IgniterLabs\SquarePOS\Classes\VersionGate;
use IgniterLabs\SquarePOS\Models\SquareLink;
use Illuminate\Support\Collection;

trait FiltersCleanVersions
{
    protected function keepChangedRecords(Collection $records, ?CatalogResponse $response, ?callable $adopt = null): Collection
    {
        return $records->filter(function($record) use ($response, $adopt): bool {
            $link = SquareLink::forOwner($record);
            if (!$link->square_id) {
                return true;
            }

            $catalogObject = $response instanceof CatalogResponse ? $response->getObject($link->square_id) : null;
            if (!$catalogObject) {
                $link->square_id = null;
                $link->square_version = null;

                return true;
            }

            $dirty = (bool)$link->square_dirty;
            if ($adopt) {
                $dirty = $adopt($record, $catalogObject) || $dirty;
            }

            $version = CatalogRecord::unwrap($catalogObject)->getVersion();
            $push = VersionGate::shouldPush(
                $link->square_version,
                $version,
                $dirty,
                true
            );

            $link->square_version = $version;

            return $push;
        })->values();
    }
}

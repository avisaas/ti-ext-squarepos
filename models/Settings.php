<?php

namespace IgniterLabs\SquarePOS\Models;

use IgniterLabs\SquarePOS\Classes\Manager;
use IgniterLabs\SquarePOS\Classes\SyncDefinition;
use Model;
use Square\Models\Location;

class Settings extends Model
{
    public const TASTYIGNITER_SOR = 0;

    public const SQUARE_SOR = 1;

    protected static $locationCache;

    public $implement = ['System\Actions\SettingsModel'];

    public $settingsCode = 'igniterlabs_squarepos_settings';

    public $settingsFieldsConfig = 'settings';

    public function getRemoteIdOptions()
    {
        if (self::$locationCache)
            return self::$locationCache;

        $locations = Manager::instance()
            ->listLocations()
            ->mapWithKeys(function (Location $location) {
                return [$location->getId() => $location->getName()];
            })->all();

        return self::$locationCache = $locations;
    }

    public function getSourceOptions()
    {
        return [
            SyncDefinition::TASTYIGNITER_SOR => 'igniterlabs.squarepos::default.text_tastyigniter',
            SyncDefinition::SQUARE_SOR => 'igniterlabs.squarepos::default.text_square',
        ];
    }

    public static function getSystemOfRecord()
    {
        return self::get('source', SyncDefinition::TASTYIGNITER_SOR);
    }

    public static function locationMapping()
    {
        return collect(self::get('location_mappings', []));
    }

    public static function isSyncEnabled()
    {
        return (bool)self::get('sync_inventory');
    }

    public static function batchSize()
    {
        return (int)self::get('sync_batch_size', 300);
    }
}
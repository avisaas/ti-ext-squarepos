<?php

namespace IgniterLabs\SquarePOS\Models;

use Model;

class Cache extends Model
{
    public $implement = ['System\Actions\SettingsModel'];

    public $settingsCode = 'igniterlabs_squarepos_cache';

    public $settingsFieldsConfig = 'settings';

    protected static $cacheMap = [];

    public static function getCategoryMappings(array $ids)
    {
        return self::getMap('category_map')->whereIn('modelId', $ids);
    }

    public static function getItemMappings(array $ids)
    {
        return self::getMap('item_map')->whereIn('modelId', $ids);
    }

    public static function getOptionMappings(array $ids)
    {
        return self::getMap('option_map')->whereIn('modelId', $ids);
    }

    public static function categoryMap(array $mappings)
    {
        self::setMap('category_map', $mappings);
    }

    public static function itemMap(array $mappings)
    {
        self::setMap('item_map', $mappings);
    }

    public static function optionMap(array $mappings)
    {
        self::setMap('option_map', $mappings);
    }

    public static function setMap($mapKey, array $mappings = null)
    {
        $cacheMap = self::get($mapKey, []);

        foreach ($mappings as $modelId => $mapping) {
            $cacheMap[$modelId] = $mapping;
        }

        self::set($mapKey, $cacheMap);
    }

    public static function mergeMap($mapKey, $key, array $mapping = null)
    {
        $cacheMap = self::getMap($mapKey, $key);

        if (is_null($mapping)) {
            unset(self::$cacheMap[$mapKey][$key]);
        }
        else {
            self::$cacheMap[$mapKey][$key] = array_merge($cacheMap, $mapping);
        }
    }

    public static function getMap($mapKey)
    {
        return collect(self::get($mapKey, []));
    }

    public static function storeMap()
    {
        self::set(self::$cacheMap);
    }
}
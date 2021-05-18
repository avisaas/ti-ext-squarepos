<?php

namespace IgniterLabs\SquarePOS\Classes;

use Admin\Models\Categories_model;
use Igniter\Flame\Exception\ApplicationException;
use IgniterLabs\SquarePOS\Exceptions\SyncFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;

class RemoteMapping extends Fluent
{
    public static $mappingsCache = [];

    protected static $mappings;

    public function __construct($attributes = [])
    {
        if (!isset($attributes['modelId']) OR !isset($attributes['squareId']))
            throw new ApplicationException('Missing modelId and squareId');

        parent::__construct($attributes);
    }

    public static function create(array $mapping)
    {
        $instance = new static($mapping);

        self::$mappings[$instance->modelId] = $instance;
        self::$mappingsCache[$instance->modelId] = $mapping;

        return $instance;
    }

    public static function createAll(Collection $mappings)
    {
        self::$mappings = [];
        self::$mappingsCache = [];

        $mappings->each(function ($mapping) {
            return self::create($mapping);
        });

        return collect(self::$mappings);
    }

    public static function all()
    {
        return self::$mappings;
    }

    public function set($key, $value = null)
    {
        $this->offsetSet($key, $value);

        return $this;
    }

    public function forgetCache()
    {
        unset(self::$mappings[$this->modelId], self::$mappingsCache[$this->modelId]);
    }

    public function updateCache()
    {
        self::$mappingsCache[$this->modelId] = $this->toArray();
    }

    public function createCategoryBatch(Categories_model $category)
    {
        if (!$category->exists)
            throw new SyncFailed('Category must exists');

        return CatalogFactory::instance()->makeCategoryBatch($category, $this);
    }
}
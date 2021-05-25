<?php

namespace IgniterLabs\SquarePOS\Models;

use Admin\Models\Categories_model as BaseCategories_model;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use Square\Models\CatalogCategory;
use Square\Models\CatalogObject;

class Categories_model extends BaseCategories_model
{
    use LocationHelpers;

    public function getSquareIdAttribute($value)
    {
        return $value ?: '#temp_'.$this->getMorphClass().'_'.$this->getKey();
    }

    public function asSquareCategory()
    {
        $categoryData = new CatalogCategory();
        $categoryData->setName($this->name);

        $categoryObject = new CatalogObject('CATEGORY', $this->square_id);
        $categoryObject->setCategoryData($categoryData);
        $categoryObject->setVersion($this->square_version);

        return $categoryObject;
    }

    public function getMorphClass()
    {
        return 'categories';
    }
}

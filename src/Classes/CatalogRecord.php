<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Classes;

use Square\Types\CatalogObject;

class CatalogRecord
{
    public static function unwrap(object $catalogObject): object
    {
        return $catalogObject instanceof CatalogObject ? $catalogObject->getValue() : $catalogObject;
    }

    public static function version(object $record, mixed $version): void
    {
        if ($version !== null && $version !== '') {
            $record->setVersion((int)$version);
        }
    }
}

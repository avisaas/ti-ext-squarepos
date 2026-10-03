<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Classes;

class SyncContext
{
    protected static int $depth = 0;

    public static function isApplyingRemote(): bool
    {
        return self::$depth > 0;
    }

    public static function applying(callable $callback)
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}

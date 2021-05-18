<?php

namespace IgniterLabs\SquarePOS\Classes;

class SyncDefinition
{
    public const TASTYIGNITER_SOR = 'igniter';

    public const SQUARE_SOR = 'square';

    /**
     * @var int
     */
    public $systemOfRecord;

    /**
     * @var string
     */
    public $cursor;

    /**
     * @var int
     */
    public $completedPercentage = 0;

    /**
     * @var array
     */
    public $itemIds = [];

    public $itemCache;

    /**
     * @var array
     */
    public $processedItemCache;

    /**
     * @var array
     */
    public $skippedItemCache;

    public $catalogLastSyncedAt;

    public $inventoryLastSyncedAt;
}
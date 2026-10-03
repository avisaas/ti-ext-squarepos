<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Models;

use Igniter\Flame\Database\Model;
use IgniterLabs\SquarePOS\Traits\ResolvesSquareOwner;

class SquareOrder extends Model
{
    use ResolvesSquareOwner;

    public $table = 'squarepos_orders';

    protected $guarded = [];

    public $timestamps = false;

    protected static function ownerRelationName(): string
    {
        return 'squareOrder';
    }
}

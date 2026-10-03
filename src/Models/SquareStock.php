<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Models;

use Igniter\Flame\Database\Model;
use IgniterLabs\SquarePOS\Traits\ResolvesSquareOwner;

class SquareStock extends Model
{
    use ResolvesSquareOwner;

    public $table = 'squarepos_stocks';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'square_synced_at' => 'datetime',
    ];

    protected static function ownerRelationName(): string
    {
        return 'squareStock';
    }
}

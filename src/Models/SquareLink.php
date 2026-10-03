<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Models;

use Igniter\Flame\Database\Model;
use IgniterLabs\SquarePOS\Traits\ResolvesSquareOwner;

class SquareLink extends Model
{
    use ResolvesSquareOwner;

    public $table = 'squarepos_links';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'square_dirty' => 'boolean',
    ];

    public $relation = [
        'morphTo' => [
            'linkable' => [],
        ],
    ];

    public static function findOwner(string $modelClass, ?string $remoteId)
    {
        if (!$remoteId) {
            return null;
        }

        $link = static::query()
            ->where('linkable_type', (new $modelClass)->getMorphClass())
            ->where('square_id', $remoteId)
            ->first();

        $owner = $link?->linkable;
        if ($owner) {
            $owner->setRelation('squareLink', $link);
        }

        return $owner;
    }

    public static function markDirty(string $type, $id): void
    {
        if (!$id) {
            return;
        }

        $link = static::query()->firstOrNew([
            'linkable_type' => $type,
            'linkable_id' => $id,
        ]);
        $link->square_dirty = true;
        $link->save();
    }

    public static function constrainEnabled($query)
    {
        return $query->where(function($query): void {
            $query->whereDoesntHave('squareLink')
                ->orWhereHas('squareLink', function($link): void {
                    $link->where('square_sync', true)->orWhereNull('square_sync');
                });
        });
    }

    public function getSquareSyncAttribute($value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool)$value;
    }

    public function isSyncEnabled(): bool
    {
        return $this->square_sync === null || $this->square_sync === true;
    }

    protected static function ownerRelationName(): string
    {
        return 'squareLink';
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function($link): void {
            $owner = $link->relationLoaded('linkable') ? $link->getRelation('linkable') : null;
            if (!$owner || !$owner->getKey()) {
                return;
            }

            $link->linkable_type = $owner->getMorphClass();
            $link->linkable_id = $owner->getKey();
        });
    }
}

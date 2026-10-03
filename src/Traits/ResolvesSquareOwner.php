<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Traits;

trait ResolvesSquareOwner
{
    public static function forOwner($owner): static
    {
        $relation = static::ownerRelationName();
        $related = $owner->{$relation};
        if ($related) {
            return $related;
        }

        $related = $owner->{$relation}()->make();
        $owner->setRelation($relation, $related);

        return $related;
    }

    public static function saveOwner($owner): static
    {
        $relation = static::ownerRelationName();
        $related = static::forOwner($owner);
        if ($owner->getKey()) {
            $owner->{$relation}()->save($related);
            $owner->setRelation($relation, $related);
        }

        return $related;
    }

    abstract protected static function ownerRelationName(): string;
}

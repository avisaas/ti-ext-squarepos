<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Tests\Support;

class CatalogResult
{
    public function __construct(
        public array $objects = [],
        public array $mappings = [],
        public ?string $cursor = null,
        public array $related = [],
    ) {}

    public function getObjects(): array
    {
        return $this->objects;
    }

    public function getIdMappings(): array
    {
        return $this->mappings;
    }

    public function getRelatedObjects(): array
    {
        return $this->related;
    }

    public function getCursor(): ?string
    {
        return $this->cursor;
    }
}

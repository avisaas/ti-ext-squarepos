<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Tests\Support;

use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use Mockery\MockInterface;

class SquareDouble
{
    public function __construct(public MockInterface $mock) {}

    public function search(CatalogResponse ...$pages): self
    {
        $this->mock->shouldReceive('searchCatalogObjects')->andReturn(...$pages);

        return $this;
    }

    public function retrieving(array $objects): self
    {
        $byId = [];
        foreach ($objects as $id => $object) {
            $byId[is_string($id) ? $id : CatalogRecord::unwrap($object)->getId()] = $object;
        }

        $this->mock->shouldReceive('batchRetrieveCatalogObjects')->andReturnUsing(
            function(array $ids) use ($byId): CatalogResponse {
                $found = [];
                foreach ($ids as $id) {
                    if (isset($byId[$id])) {
                        $found[] = $byId[$id];
                    }
                }

                return Square::catalogResponse($found);
            },
        );

        return $this;
    }

    public function counting(array $counts, ?string $cursor = null): self
    {
        $this->mock->shouldReceive('batchRetrieveInventoryCounts')->andReturn(Square::inventoryCounts($counts, $cursor));

        return $this;
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->mock->{$method}(...$arguments);
    }
}

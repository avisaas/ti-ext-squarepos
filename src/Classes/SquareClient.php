<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Classes;

use IgniterLabs\SquarePOS\Exceptions\ClientException;
use Illuminate\Support\Str;
use Square\Catalog\Images\Requests\CreateImagesRequest;
use Square\Catalog\Requests\BatchDeleteCatalogObjectsRequest;
use Square\Catalog\Requests\BatchGetCatalogObjectsRequest;
use Square\Catalog\Requests\BatchUpsertCatalogObjectsRequest;
use Square\Catalog\Requests\ListCatalogRequest;
use Square\Catalog\Requests\SearchCatalogObjectsRequest;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\OAuth\Requests\ObtainTokenRequest;
use Square\Orders\Requests\BatchGetOrdersRequest;
use Square\Orders\Requests\GetOrdersRequest;
use Square\Orders\Requests\UpdateOrderRequest;
use Square\Types\BatchChangeInventoryRequest;
use Square\Types\BatchChangeInventoryResponse;
use Square\Types\BatchDeleteCatalogObjectsResponse;
use Square\Types\BatchGetCatalogObjectsResponse;
use Square\Types\BatchGetInventoryCountsRequest;
use Square\Types\BatchGetInventoryCountsResponse;
use Square\Types\BatchUpsertCatalogObjectsResponse;
use Square\Types\CatalogObject;
use Square\Types\CreateCatalogImageRequest;
use Square\Types\CreateOrderRequest;
use Square\Types\ListCatalogResponse;
use Square\Types\ListLocationsResponse;
use Square\Types\Location;
use Square\Types\ObtainTokenResponse;
use Square\Types\Order;
use Square\Types\SearchCatalogObjectsResponse;
use Square\Utils\File;

class SquareClient
{
    protected $sdk;

    public function listLocations()
    {
        $response = $this->call(fn(): ListLocationsResponse => $this->sdk()->locations->list());

        return collect($response->getLocations() ?? [])
            ->filter(fn(Location $location): bool => $location->getStatus() === 'ACTIVE')
            ->values();
    }

    public static function resolveObjectTypes(array $options): ?array
    {
        $types = $options['object_types'] ?? $options['types'] ?? null;
        if ($types === null || $types === []) {
            return null;
        }

        return array_values((array)$types);
    }

    public function searchCatalogObjects(array $options, bool $related = false, bool $deleted = false): CatalogResponse
    {
        $request = new SearchCatalogObjectsRequest([
            'includeRelatedObjects' => $related,
            'includeDeletedObjects' => $deleted,
            'objectTypes' => self::resolveObjectTypes($options),
            'cursor' => $options['cursor'] ?? null,
            'limit' => $options['limit'] ?? null,
            'beginTime' => $options['beginTime'] ?? null,
        ]);

        return new CatalogResponse($this->call(fn(): SearchCatalogObjectsResponse => $this->sdk()->catalog->search($request)));
    }

    public function listCatalogObjects(string $types, ?string $cursor = null): CatalogResponse
    {
        $request = new ListCatalogRequest([
            'cursor' => $cursor,
            'types' => $types,
        ]);

        $objects = $this->call(function() use ($request): array {
            $objects = [];
            foreach ($this->sdk()->catalog->list($request)->getPages() as $page) {
                foreach ($page->getItems() as $object) {
                    $objects[] = $object;
                }

                break;
            }

            return $objects;
        });

        return new CatalogResponse(new ListCatalogResponse([
            'objects' => $objects,
        ]));
    }

    public function batchRetrieveCatalogObjects(array $ids, bool $include = false): CatalogResponse
    {
        $request = new BatchGetCatalogObjectsRequest([
            'objectIds' => array_values($ids),
            'includeRelatedObjects' => $include,
        ]);

        return new CatalogResponse($this->call(fn(): BatchGetCatalogObjectsResponse => $this->sdk()->catalog->batchGet($request)));
    }

    public function batchUpsertCatalogObjects(array $batches): CatalogResponse
    {
        $request = new BatchUpsertCatalogObjectsRequest([
            'idempotencyKey' => (string)Str::uuid(),
            'batches' => $batches,
        ]);

        return new CatalogResponse($this->call(fn(): BatchUpsertCatalogObjectsResponse => $this->sdk()->catalog->batchUpsert($request)));
    }

    public function batchDeleteCatalogObjects(array $ids): CatalogResponse
    {
        $request = new BatchDeleteCatalogObjectsRequest([
            'objectIds' => array_values($ids),
        ]);

        return new CatalogResponse($this->call(fn(): BatchDeleteCatalogObjectsResponse => $this->sdk()->catalog->batchDelete($request)));
    }

    public function batchChangeInventory(array $changes, bool $ignoreUnchanged = true)
    {
        $request = new BatchChangeInventoryRequest([
            'idempotencyKey' => (string)Str::uuid(),
            'changes' => $changes,
            'ignoreUnchangedCounts' => $ignoreUnchanged,
        ]);

        return $this->call(fn(): BatchChangeInventoryResponse => $this->sdk()->inventory->batchCreateChanges($request));
    }

    public function batchRetrieveInventoryCounts(array $catalogObjectIds, array $locationIds, ?string $cursor = null)
    {
        $request = new BatchGetInventoryCountsRequest([
            'catalogObjectIds' => array_values($catalogObjectIds),
            'locationIds' => array_values($locationIds),
            'cursor' => $cursor,
        ]);

        return $this->call(function() use ($request): BatchGetInventoryCountsResponse {
            $counts = [];
            foreach ($this->sdk()->inventory->batchGetCounts($request)->getPages() as $page) {
                foreach ($page->getItems() as $count) {
                    $counts[] = $count;
                }
            }

            return new BatchGetInventoryCountsResponse([
                'counts' => $counts,
            ]);
        });
    }

    public function createCatalogImage(string $idempotencyKey, $imageObject, string $filePath, ?string $attachToId = null, ?string $mimeType = null)
    {
        $request = new CreateCatalogImageRequest([
            'idempotencyKey' => $idempotencyKey,
            'image' => $imageObject,
            'objectId' => $attachToId,
        ]);

        return $this->call(fn(): ?CatalogObject => $this->sdk()->catalog->images->create(new CreateImagesRequest([
            'request' => $request,
            'imageFile' => File::createFromFilepath($filePath, basename($filePath), $mimeType ?: 'image/jpeg'),
        ]))->getImage());
    }

    public function createOrder(CreateOrderRequest $request)
    {
        return $this->call(fn(): ?Order => $this->sdk()->orders->create($request)->getOrder());
    }

    public function updateOrder(UpdateOrderRequest $request)
    {
        return $this->call(fn(): ?Order => $this->sdk()->orders->update($request)->getOrder());
    }

    public function retrieveOrder(string $orderId)
    {
        return $this->call(fn(): ?Order => $this->sdk()->orders->get(new GetOrdersRequest([
            'orderId' => $orderId,
        ]))->getOrder());
    }

    public function batchRetrieveOrders(array $orderIds)
    {
        return $this->call(fn(): array => $this->sdk()->orders->batchGet(new BatchGetOrdersRequest([
            'orderIds' => array_values($orderIds),
        ]))->getOrders() ?? []);
    }

    public function refreshAccessToken(string $clientId, string $clientSecret, string $refreshToken)
    {
        return $this->call(fn(): ObtainTokenResponse => $this->sdk()->oAuth->obtainToken(new ObtainTokenRequest([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'grantType' => 'refresh_token',
            'refreshToken' => $refreshToken,
        ])));
    }

    protected function sdk(): \Square\SquareClient
    {
        return $this->sdk ??= resolve(\Square\SquareClient::class);
    }

    protected function call(callable $callback)
    {
        try {
            return $callback();
        } catch (SquareApiException $exception) {
            throw new ClientException($exception, $exception->getCode(), $exception);
        } catch (SquareException $exception) {
            throw new ClientException($exception->getMessage(), (int)$exception->getCode(), $exception);
        }
    }
}

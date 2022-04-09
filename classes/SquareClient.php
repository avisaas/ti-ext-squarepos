<?php

namespace IgniterLabs\SquarePOS\Classes;

use Igniter\Flame\Support\Facades\Str;
use IgniterLabs\SquarePOS\Exceptions\ClientException;
use Square\Environment;
use Square\Models\BatchChangeInventoryRequest;
use Square\Models\BatchDeleteCatalogObjectsRequest;
use Square\Models\BatchRetrieveCatalogObjectsRequest;
use Square\Models\BatchUpsertCatalogObjectsRequest;
use Square\Models\Location;
use Square\Models\SearchCatalogObjectsRequest;

class SquareClient
{
    protected $config;

    protected $client;

    public function __construct($config)
    {
        $this->config = $config;

        $this->client = new \Square\SquareClient([
            'accessToken' => array_get($this->config, 'access_token'),
            'environment' => array_get($this->config, 'environment', Environment::PRODUCTION),
        ]);
    }

    public function listLocations()
    {
        $response = $this->client->getLocationsApi()->listLocations();

        if (!$response->isSuccess())
            throw new ClientException($response);

        $result = $response->getResult()->getLocations();

        return collect($result)
            ->filter(function (Location $location) {
                return ($location->getStatus() === 'ACTIVE'
                    && in_array('CREDIT_CARD_PROCESSING', (array)$location->getCapabilities(), TRUE));
            })->values();
    }

    //
    //
    //

    public function searchCatalogObjects(array $options, bool $related = FALSE, bool $deleted = FALSE)
    {
        $body = new SearchCatalogObjectsRequest();
        $body->setIncludeRelatedObjects($related);
        $body->setIncludeDeletedObjects($deleted);
        $body->setObjectTypes($options['types'] ?? null);
        $body->setCursor($options['cursor'] ?? null);
        $body->setLimit($options['limit'] ?? null);
        $body->setBeginTime($options['beginTime'] ?? null);

        $response = $this->client->getCatalogApi()->searchCatalogObjects($body);

        if (!$response->isSuccess())
            throw new ClientException($response);

        return new CatalogResponse($response->getResult());
    }

    public function listCatalogObjects(string $types, ?string $cursor = null)
    {
        $response = $this->client->getCatalogApi()->listCatalog($cursor, $types);

        if (!$response->isSuccess())
            throw new ClientException($response);

        return new CatalogResponse($response->getResult());
    }

    public function batchRetrieveCatalogObjects(array $ids, bool $include = FALSE)
    {
        $body = new BatchRetrieveCatalogObjectsRequest($ids);
        $body->setIncludeRelatedObjects($include);

        $response = $this->client->getCatalogApi()->batchRetrieveCatalogObjects($body);

        if (!$response->isSuccess())
            throw new ClientException($response);

        return new CatalogResponse($response->getResult());
    }

    public function batchUpsertCatalogObjects(array $batches)
    {
        $body = new BatchUpsertCatalogObjectsRequest(Str::uuid(), $batches);

        $response = $this->client->getCatalogApi()->batchUpsertCatalogObjects($body);

        if (!$response->isSuccess())
            throw new ClientException($response);

        return new CatalogResponse($response->getResult());
    }

    public function batchDeleteCatalogObjects(array $ids)
    {
        $body = new BatchDeleteCatalogObjectsRequest();
        $body->setObjectIds($ids);

        $response = $this->client->getCatalogApi()->batchDeleteCatalogObjects($body);

        if (!$response->isSuccess())
            throw new ClientException($response);

        return new CatalogResponse($response->getResult());
    }

    //
    //
    //

    public function batchChangeInventory(array $changes, bool $ignoreUnchanged = TRUE)
    {
        $body = new BatchChangeInventoryRequest(Str::uuid());
        $body->setChanges($changes);
        $body->setIgnoreUnchangedCounts(TRUE);

        $response = $this->client->getInventoryApi()->batchChangeInventory($body);

        if (!$response->isSuccess())
            throw new ClientException($response);

        return $response->getResult();
    }
}

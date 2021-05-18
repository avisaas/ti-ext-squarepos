<?php

namespace IgniterLabs\SquarePOS\Classes;

use Igniter\Flame\Traits\Singleton;
use IgniterLabs\SquarePOS\Exceptions\SyncFailed;
use Square\Environment;
use Square\Models\BatchRetrieveCatalogObjectsRequest;
use Square\Models\BatchUpsertCatalogObjectsRequest;
use Square\Models\Location;
use Square\Models\SearchCatalogObjectsRequest;
use Square\SquareClient;

class Manager
{
    use Singleton;

    public function listLocations()
    {
        $apiResponse = $this->createApiClient()
            ->getLocationsApi()
            ->listLocations();

        $result = [];
        if ($apiResponse->isSuccess()) {
            $result = $apiResponse->getResult()->getLocations();
        }

        return collect($result)
            ->filter(function (Location $location) {
                return ($location->getStatus() === 'ACTIVE'
                    AND in_array('CREDIT_CARD_PROCESSING', (array)$location->getCapabilities(), TRUE));
            })->values();
    }

    public function batchRetrieveCatalogObjects(array $id, bool $include = FALSE)
    {
        $body = new BatchRetrieveCatalogObjectsRequest($id);
        $body->setIncludeRelatedObjects($include);

        $response = $this->createApiClient()
            ->getCatalogApi()
            ->batchRetrieveCatalogObjects($body);

        if (!$response->isSuccess())
            throw new SyncFailed(implode(PHP_EOL, array_map(function ($error) {
                return sprintf('%s: %s (%s)', $error->getCode(), $error->getDetail(), $error->getField());
            }, $response->getErrors())));

        return $response;
    }

    public function batchUpsertCatalogObjects(string $idempotencyKey, array $batches)
    {
        $body = new BatchUpsertCatalogObjectsRequest($idempotencyKey, $batches);

        $response = $this->createApiClient()
            ->getCatalogApi()
            ->batchUpsertCatalogObjects($body);

        if (!$response->isSuccess())
            throw new SyncFailed(implode(PHP_EOL, array_map(function ($error) {
                return sprintf('%s: %s (%s)', $error->getCode(), $error->getDetail(), $error->getField());
            }, $response->getErrors())));

        return $response;
    }

    public function searchCatalogObjects(array $options, bool $include = FALSE)
    {
        $body = new SearchCatalogObjectsRequest();
        $body->setIncludeRelatedObjects($include);
        $body->setCursor($options['cursor']);
        $body->setObjectTypes($options['object_types']);

        return $this->createApiClient()
            ->getCatalogApi()
            ->searchCatalogObjects($body);
    }

    protected function createApiClient()
    {
        $accessToken = Connection::instance()->getActiveAccessToken();

        return new SquareClient([
            'accessToken' => $accessToken,
            'environment' => Environment::SANDBOX,
        ]);
    }
}
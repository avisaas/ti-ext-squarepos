<?php

namespace IgniterLabs\SquarePOS\Classes;

use Igniter\Flame\Traits\Singleton;
use IgniterLabs\SquarePOS\Exceptions\SyncFailed;
use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SyncLog;
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
                    && in_array('CREDIT_CARD_PROCESSING', (array)$location->getCapabilities(), TRUE));
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

        return new CatalogResponse($response);
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

        return new CatalogResponse($response);
    }

    public function searchCatalogObjects(array $options, bool $include = FALSE)
    {
        $body = new SearchCatalogObjectsRequest();
        $body->setIncludeRelatedObjects($include);
        $body->setCursor($options['cursor']);
        $body->setObjectTypes($options['object_types']);

        $response = $this->createApiClient()
            ->getCatalogApi()
            ->searchCatalogObjects($body);

        if (!$response->isSuccess())
            throw new SyncFailed($response->getErrors());

        return new CatalogResponse($response);
    }

    public function updateMapping($mapping, $response)
    {
        if ($idMapping = $response->getMapping($mapping->squareId))
            $mapping->model->square_id = $idMapping->getObjectId();

        if ($catalogObject = $response->getObject($mapping->model->square_id))
            $mapping->model->square_version = $catalogObject->getVersion();

        return $catalogObject;
    }

    protected function createApiClient()
    {
        $accessToken = Connection::instance()->getActiveAccessToken();

        return new SquareClient([
            'accessToken' => $accessToken,
            'environment' => Environment::SANDBOX,
        ]);
    }

    //
    //
    //

    /**
     * Pull new/updated items from square and update inventory count
     */
    public function processSchedule()
    {
    }

    /**
     * Import only NEW items from square
     */
    public function processImport()
    {
    }

    /**
     * Sync items between TI and square
     */
    public function processSyncCatalog()
    {
        $this->dispatchJob(SyncCatalogJob::class, [
            'systemOfRecord' => Settings::getSystemOfRecord(),
            'batchSize' => Settings::batchSize(),
        ]);
    }

    protected function dispatchJob($jobClass, array $config = [])
    {
        $syncLog = SyncLog::storeLog($jobClass, $config);

        $job = new $jobClass($syncLog);

        $syncLog->clearException();

        dispatch($job);
    }
}

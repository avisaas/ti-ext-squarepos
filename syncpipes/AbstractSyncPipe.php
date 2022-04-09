<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Models\SyncTask;

abstract class AbstractSyncPipe
{
    /**
     * @var \IgniterLabs\SquarePOS\Classes\SquareClient
     */
    protected $square;

    /**
     * @var \IgniterLabs\SquarePOS\Models\SyncTask
     */
    protected SyncTask $syncTask;

    public function __construct(SyncTask $syncTask)
    {
        $this->syncTask = $syncTask;

        $this->square = resolve(SquareClient::class);
    }

    abstract public function execute();

    protected function handleResponseCursor(CatalogResponse $response, $key = null): void
    {
        if ($cursor = $response->getResponse()->getCursor()) {
            $this->syncTask->setConfigData($key, $cursor)->save();

            $this->execute();
        }
    }
}

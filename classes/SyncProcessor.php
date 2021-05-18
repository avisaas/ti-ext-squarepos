<?php

namespace IgniterLabs\SquarePOS\Classes;

use Admin\Models\Menus_model;
use Igniter\Flame\Traits\Singleton;
use IgniterLabs\SquarePOS\Jobs\ProcessImportJob;
use IgniterLabs\SquarePOS\Jobs\ProcessPollJob;
use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SyncLog;

class SyncProcessor
{
    use Singleton;

    /**
     * Pull new/updated items from square and update inventory count
     */
    public function processSchedule()
    {
        // bail if there is already a sync in progress
        if (!Settings::isSyncEnabled() or !Settings::hasLastSyncedAt()) {
            return;
        }

        $config = [
            'systemOfRecord' => Settings::getSystemOfRecord(),
        ];

        $this->dispatchJob(ProcessPollJob::class, $config);
    }

    /**
     * Import only NEW items from square
     */
    public function processImport()
    {
        $this->dispatchJob(ProcessImportJob::class);
    }

    /**
     * Sync items between TI and square
     */
    public function processSyncCatalog()
    {
        Menus_model::isEnabled()
            ->pluck('menu_id')
            ->chunk(Settings::batchSize())
            ->each(function ($chunk) {
                $config = [
                    'systemOfRecord' => Settings::getSystemOfRecord(),
                    'itemIds' => $chunk->all(),
                ];

                $this->dispatchJob(SyncCatalogJob::class, $config);
            });
    }

    protected function storeSyncLog(string $jobClass, array $attributes)
    {
        return SyncLog::storeLog($jobClass, $attributes);
    }

    protected function dispatchJob($jobClass, array $config = [])
    {
        $syncLog = $this->storeSyncLog($jobClass, $config);

        $job = new $jobClass($syncLog);

        $syncLog->clearException();

        dispatch($job);
    }
}

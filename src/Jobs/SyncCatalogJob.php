<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use IgniterLabs\SquarePOS\Models\SyncTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

class SyncCatalogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(protected SyncTask $syncTask) {}

    public function handle(): void
    {
        $this->syncTask->markAsRunning();

        try {
            Bus::chain($this->syncJobs())->dispatch();
        } catch (Throwable) {
            // The failed step already stored the exception and sent the summary.
        }
    }

    protected function syncJobs(): array
    {
        $jobs = [];

        $push = $this->syncTask->getConfigData('mode') === 'export'
            || ($this->syncTask->getConfigData('mode') !== 'import'
                && !$this->syncTask->isSystemOfRecordSquare());

        if ($push) {
            $jobs[] = new PushCategoriesJob($this->syncTask);
            $jobs[] = new PushItemOptionsJob($this->syncTask);
            $jobs[] = new PushItemsJob($this->syncTask);
        } else {
            $jobs[] = new PullCategoriesJob($this->syncTask);
            $jobs[] = new PullItemOptionsJob($this->syncTask);
            $jobs[] = new PullItemsJob($this->syncTask);
        }

        if ($this->syncTask->getConfigData('syncInventory')) {
            $jobs[] = new PullStocksJob($this->syncTask);
            $jobs[] = new PushStocksJob($this->syncTask);
        }

        $jobs[] = new CompleteSyncJob($this->syncTask->getKey());

        return $jobs;
    }
}

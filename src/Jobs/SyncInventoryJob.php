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

class SyncInventoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(protected SyncTask $syncTask) {}

    public function handle(): void
    {
        $this->syncTask->markAsRunning();

        if (!$this->syncTask->getConfigData('syncInventory')) {
            $this->syncTask->note('Inventory sync is disabled');
            $this->syncTask->markAsComplete();

            return;
        }

        Bus::chain([
            new PullStocksJob($this->syncTask),
            new PushStocksJob($this->syncTask),
            new CompleteSyncJob($this->syncTask->getKey()),
        ])->dispatch();
    }
}

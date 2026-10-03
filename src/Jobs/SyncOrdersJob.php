<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SyncTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class SyncOrdersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(protected SyncTask $syncTask) {}

    public function handle(): void
    {
        if (!Settings::syncOrders()) {
            $this->syncTask->note('Order fulfillment sync is disabled');
            $this->syncTask->markAsComplete();

            return;
        }

        $this->syncTask->markAsRunning();

        Bus::chain([
            new PushOrdersJob($this->syncTask),
            new PullOrdersJob($this->syncTask),
            new CompleteSyncJob($this->syncTask->getKey()),
        ])->dispatch();
    }
}

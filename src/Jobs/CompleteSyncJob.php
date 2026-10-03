<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use IgniterLabs\SquarePOS\Models\SyncTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CompleteSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(protected int $taskId) {}

    public function handle(): void
    {
        $task = SyncTask::query()->findOrFail($this->taskId);
        $task->finished_at = now();
        $task->markAsComplete();
    }
}

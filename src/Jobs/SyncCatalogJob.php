<?php

namespace IgniterLabs\SquarePOS\Jobs;

use Exception;
use IgniterLabs\SquarePOS\Exceptions\SyncException;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\SyncPipes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncCatalogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $uuid = '';

    /**
     * The output interface implementation.
     *
     * @var \Illuminate\Console\OutputStyle
     */
    public $logsOutput;

    public function handle()
    {
        if (!$this->uuid) {
            throw new SyncException('Missing sync task identifier');
        }

        $syncTask = SyncTask::findBy($this->uuid);

        $syncTask->setSyncJob($this);

        try {
            $this->getSyncPipes($syncTask)->each(function (string $syncPipe) use ($syncTask) {
                (new $syncPipe($syncTask))->execute();

                $syncTask->save();
            });

            $syncTask->markAsComplete();
        }
        catch (Throwable | Exception $ex) {
            $syncTask->saveException($ex);

            throw $ex;
        }
    }

    protected function getSyncPipes($syncTask)
    {
        $pipes[] = SyncPipes\StartSync::class;

        if ($syncTask->isSystemOfRecordSquare()) {
            $pipes[] = SyncPipes\PullCategories::class;
            $pipes[] = SyncPipes\PullItemOptions::class;
            $pipes[] = SyncPipes\PullItems::class;
        }
        else {
            $pipes[] = SyncPipes\PushCategories::class;
            $pipes[] = SyncPipes\PushItemOptions::class;
            $pipes[] = SyncPipes\PushItems::class;
        }

        $pipes[] = SyncPipes\CompleteSync::class;

        return collect($pipes);
    }
}

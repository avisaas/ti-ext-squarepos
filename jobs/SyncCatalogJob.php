<?php

namespace IgniterLabs\SquarePOS\Jobs;

use Exception;
use IgniterLabs\SquarePOS\Exceptions\SyncFailed;
use IgniterLabs\SquarePOS\Models\SyncLog;
use IgniterLabs\SquarePOS\SyncPipes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var \IgniterLabs\SquarePOS\Models\SyncLog
     */
    protected $syncLog;

    public function __construct($syncLog)
    {
        $this->syncLog = $syncLog;
    }

    public function handle()
    {
        if (!$this->syncLog->getConfig('systemOfRecord'))
            throw new SyncFailed('Missing system of record');

        $syncPipes = $this->getSyncPipes();

        $this->syncLog->setConfig('pipes', $syncPipes);

        try {
            app(Pipeline::class)
                ->send($this->syncLog)
                ->through($syncPipes)
                ->then(function (SyncLog $syncLog) {
                    $syncLog->markAsSuccessful();
                });
        }
        catch (Exception $ex) {
            $this->syncLog->saveException($ex);

            throw $ex;
        }
    }

    protected function getSyncPipes()
    {
//        $pipes[] = SyncPipes\StartSync::class;

        if ($this->syncLog->isSystemOfRecordSquare()) {
            $pipes[] = SyncPipes\PullCategories::class;
//            $pipes[] = SyncPipes\PullItemOptions::class;
            $pipes[] = SyncPipes\PullItems::class;
        }
        else {
            $pipes[] = SyncPipes\PushCategories::class;
            $pipes[] = SyncPipes\PushItemOptions::class;
            $pipes[] = SyncPipes\PushItems::class;
        }

        $pipes[] = SyncPipes\CompleteSync::class;

        return $pipes;
    }
}

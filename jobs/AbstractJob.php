<?php

namespace IgniterLabs\SquarePOS\Jobs;

use Exception;
use IgniterLabs\SquarePOS\Classes\SyncDefinition;
use IgniterLabs\SquarePOS\Exceptions\SyncFailed;
use IgniterLabs\SquarePOS\Models\SyncLog;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class AbstractJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var \IgniterLabs\SquarePOS\Models\SyncLog
     */
    protected $syncLog;

    /**
     * @var int
     */
    public $systemOfRecord;

    public $tries;

    public $syncTimeout;

    public $syncInventory;

    public function __construct($syncLog)
    {
        $this->syncLog = $syncLog;
    }

    abstract protected function getSyncPipes();

    public function handle()
    {
        if (!$this->syncLog->getConfig('systemOfRecord'))
            throw new SyncFailed('Missing system of record');

        $syncPipes = $this->getSyncPipes();

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

    public function isSystemOfRecordSquare()
    {
        return $this->syncLog->systemOfRecord() === SyncDefinition::SQUARE_SOR;
    }
}
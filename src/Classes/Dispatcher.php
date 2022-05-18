<?php

namespace IgniterLabs\SquarePOS\Classes;

use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SyncTask;
use Illuminate\Support\Str;

class Dispatcher
{
    public $uuid;

    public $job;

    public $systemOfRecord;

    public $batchSize;

    public $beginTimes;

    public $syncInventory;

    /**
     * The output interface implementation.
     *
     * @var \Illuminate\Console\OutputStyle
     */
    protected $logsOutput;

    /**
     * @var \IgniterLabs\SquarePOS\Models\SyncTask
     */
    protected $syncTask;

    public function __construct($job)
    {
        $this->job = app($job);
    }

    public static function create($job)
    {
        return (new static($job))
            ->uuid(Str::uuid())
            ->systemOfRecord(Settings::getSystemOfRecord())
            ->batchSize(Settings::batchSize())
            ->beginTimes(Settings::getSearchBeginTimes())
            ->syncInventory(Settings::isSyncEnabled());
    }

    public function uuid(string $uuid): self
    {
        $this->uuid = $uuid;

        $this->job->uuid = $uuid;

        return $this;
    }

    public function systemOfRecord(string $systemOfRecord): self
    {
        $this->systemOfRecord = $systemOfRecord;

        return $this;
    }

    public function batchSize(int $batchSize): self
    {
        $this->batchSize = $batchSize;

        return $this;
    }

    public function beginTimes(array $beginTimes): self
    {
        $this->beginTimes = $beginTimes;

        return $this;
    }

    public function setLogsOutput($output): self
    {
        $this->job->logsOutput = $output;

        return $this;
    }

    public function syncInventory(bool $syncInventory)
    {
        $this->syncInventory = $syncInventory;

        return $this;
    }

    public function dispatch()
    {
        $this->prepareSyncTask();

        dispatch($this->job);
    }

    public function dispatchSync()
    {
        $this->prepareSyncTask();

        dispatch_sync($this->job);
    }

    protected function prepareSyncTask()
    {
        $this->syncTask = SyncTask::firstOrCreate([
            'uuid' => $this->uuid,
        ]);

        $this->syncTask->type = $this->systemOfRecord;

        $this->syncTask->config_data = [
            'beginTimes' => $this->beginTimes,
            'batchSize' => $this->batchSize,
            'syncInventory' => $this->syncInventory,
        ];

        $this->syncTask->save();
    }
}

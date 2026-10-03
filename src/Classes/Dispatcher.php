<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Classes;

use IgniterLabs\SquarePOS\Jobs\SyncInventoryJob;
use IgniterLabs\SquarePOS\Jobs\SyncOrdersJob;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SyncTask;
use Illuminate\Support\Str;

class Dispatcher
{
    public $uuid;

    public $systemOfRecord;

    public $batchSize;

    public $syncInventory;

    public $mode = 'sync';

    /**
     * @var SyncTask|null
     */
    protected $syncTask;

    public function __construct(protected $jobClass) {}

    public static function create($job): static
    {
        return (new static($job))
            ->uuid((string)Str::uuid())
            ->systemOfRecord(Settings::getSystemOfRecord())
            ->batchSize(Settings::batchSize())
            ->syncInventory(Settings::isSyncEnabled());
    }

    public function uuid(string $uuid): self
    {
        $this->uuid = $uuid;

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

    public function syncInventory(bool $syncInventory): static
    {
        $this->syncInventory = $syncInventory;

        return $this;
    }

    public function mode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function import(): self
    {
        return $this->mode('import')->systemOfRecord(Settings::SQUARE_SOR);
    }

    public function export(): self
    {
        return $this->mode('export')->systemOfRecord(Settings::IGNITER_SOR);
    }

    public function dispatch(): void
    {
        $this->prepareSyncTask();

        dispatch(new $this->jobClass($this->syncTask));
    }

    public function dispatchSync(): SyncTask
    {
        $this->prepareSyncTask();

        try {
            dispatch_sync(new $this->jobClass($this->syncTask));
        } finally {
            $this->syncTask->refresh();
        }

        return $this->syncTask;
    }

    public function getSyncTask(): ?SyncTask
    {
        return $this->syncTask;
    }

    protected function prepareSyncTask()
    {
        $this->syncTask = SyncTask::firstOrCreate([
            'uuid' => $this->uuid,
        ]);

        $this->syncTask->status = SyncTask::STATUS_QUEUED;
        $this->syncTask->description = $this->description();

        $this->syncTask->config_data = [
            'batchSize' => $this->batchSize,
            'syncInventory' => $this->syncInventory,
            'mode' => $this->mode,
            'createMissing' => $this->mode === 'import',
            'systemOfRecord' => $this->systemOfRecord,
        ];

        $this->syncTask->save();
    }

    protected function description(): string
    {
        $key = match ($this->jobClass) {
            SyncOrdersJob::class => 'igniterlabs.squarepos::default.text_pushing_orders',
            SyncInventoryJob::class => $this->systemOfRecord === Settings::SQUARE_SOR
                ? 'igniterlabs.squarepos::default.text_pulling_inventory'
                : 'igniterlabs.squarepos::default.text_pushing_inventory',
            default => $this->pushesCatalog()
                ? 'igniterlabs.squarepos::default.text_pushing_catalog'
                : 'igniterlabs.squarepos::default.text_pulling_catalog',
        };

        return lang($key);
    }

    protected function pushesCatalog(): bool
    {
        return $this->mode === 'export'
            || ($this->mode !== 'import' && $this->systemOfRecord !== Settings::SQUARE_SOR);
    }
}

<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Models\SyncTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

abstract class AbstractSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var SquareClient
     */
    protected $square;

    public function __construct(protected SyncTask $syncTask) {}

    public function handle(): void
    {
        $this->square = resolve(SquareClient::class);

        try {
            $this->execute();

            $this->syncTask->save();
        } catch (Throwable $throwable) {
            $this->syncTask->markAsFailure($throwable);
            $this->syncTask->sendSummary();

            throw $throwable;
        }
    }

    abstract public function execute();

    protected function handleResponseCursor(CatalogResponse $response, $key = null): void
    {
        if ($cursor = $response->getResponse()->getCursor()) {
            $this->syncTask->setConfigData($key, $cursor)->save();

            $this->execute();
        }
    }
}

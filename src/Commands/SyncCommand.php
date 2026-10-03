<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Commands;

use IgniterLabs\SquarePOS\Classes\Dispatcher;
use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use IgniterLabs\SquarePOS\Jobs\SyncInventoryJob;
use IgniterLabs\SquarePOS\Jobs\SyncOrdersJob;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

class SyncCommand extends Command
{
    /**
     * The console command name.
     * @var string
     */
    protected $name = 'square:sync';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Sync menus, orders, and inventory with Square.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        match (true) {
            $this->option('menus') => $this->runDispatcher(Dispatcher::create(SyncCatalogJob::class)),
            $this->option('orders') => $this->runDispatcher(Dispatcher::create(SyncOrdersJob::class)),
            $this->option('inventory') => $this->runDispatcher(Dispatcher::create(SyncInventoryJob::class)),
            default => $this->runDispatcher(Dispatcher::create(SyncCatalogJob::class))
                && $this->runDispatcher(Dispatcher::create(SyncOrdersJob::class)),
        };

        $this->line('Sync complete');
    }

    protected function runDispatcher(Dispatcher $dispatcher): bool
    {
        try {
            $dispatcher->dispatchSync();
        } finally {
            $syncTask = $dispatcher->getSyncTask();

            if ($syncTask?->description) {
                $this->line($syncTask->description);
            }

            if ($errorMessage = array_get($syncTask?->exception, 'message')) {
                $this->error($errorMessage);
            }

            foreach ($syncTask?->notes ?? [] as $note) {
                $this->line($note);
            }
        }

        return true;
    }

    protected function getOptions(): array
    {
        return [
            ['menus', null, InputOption::VALUE_NONE, 'Sync menus'],
            ['orders', null, InputOption::VALUE_NONE, 'Sync orders'],
            ['inventory', null, InputOption::VALUE_NONE, 'Sync inventory'],
        ];
    }
}

<?php

namespace IgniterLabs\SquarePOS\Commands;

use IgniterLabs\SquarePOS\Classes\Dispatcher;
use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use Illuminate\Console\Command;

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
    protected $description = 'Sync items between TI and square.';

    /**
     * Execute the console command.
     * @return mixed
     */
    public function handle()
    {
        Dispatcher::create(SyncCatalogJob::class)
            ->setLogsOutput($this->output)
            ->dispatchSync();

        $this->output->writeln('Sync complete');
    }
}

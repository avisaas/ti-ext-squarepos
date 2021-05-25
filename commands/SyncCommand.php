<?php

namespace IgniterLabs\SquarePOS\Commands;

use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SyncLog;
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
        $syncLog = SyncLog::storeLog(SyncCatalogJob::class, [
            'systemOfRecord' => Settings::getSystemOfRecord(),
            'batchSize' => Settings::batchSize(),
        ]);

        $syncLog->clearException();

        SyncCatalogJob::dispatchNow($syncLog);

        $this->output->writeln('Sync complete');
    }

}

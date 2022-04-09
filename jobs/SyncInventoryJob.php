<?php

namespace IgniterLabs\SquarePOS\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncInventoryJob implements ShouldQueue
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
    }
}

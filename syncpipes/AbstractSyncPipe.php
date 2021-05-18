<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Closure;
use IgniterLabs\SquarePOS\Classes\ItemMapping;
use IgniterLabs\SquarePOS\Classes\Manager;
use IgniterLabs\SquarePOS\Models\SyncLog;

abstract class AbstractSyncPipe
{
    /**
     * @var \IgniterLabs\SquarePOS\Classes\Manager
     */
    protected $apiManager;

    public function __construct()
    {
        $this->apiManager = Manager::instance();
    }

    abstract public function run(SyncLog $syncLog);

    public function handle($syncLog, Closure $next)
    {
        try {
            $this->run($syncLog);
        }
        finally {
            $this->complete($syncLog);
        }

        return $next($syncLog);
    }

    protected function complete(SyncLog $syncLog)
    {
        $pipes = $syncLog->getConfig('pipes', []);
        $pipes[] = get_called_class();
        $syncLog->setConfig('pipes', $pipes);
    }
}
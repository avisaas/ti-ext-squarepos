<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use IgniterLabs\SquarePOS\Models\Settings;

class CompleteSync extends AbstractSyncPipe
{
    public function execute()
    {
        Settings::setSearchBeginTimes($this->syncTask->getConfigData('beginTimes'));
    }
}

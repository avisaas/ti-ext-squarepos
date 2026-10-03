<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs\Concerns;

use IgniterLabs\SquarePOS\Models\Settings;

trait PullsCatalogPages
{
    protected function pullCatalogPages(string $objectType, string $cursorKey, string $beginKey, bool $related, callable $import): void
    {
        $pendingKey = 'beginTimesPending.'.$beginKey;

        if (!$this->syncTask->getConfigData($pendingKey)) {
            $this->syncTask->setConfigData($pendingKey, now()->toISOString())->save();
        }

        $response = $this->square->searchCatalogObjects([
            'object_types' => [$objectType],
            'cursor' => $this->syncTask->getConfigData($cursorKey),
            'limit' => $this->syncTask->batchSize(),
            'beginTime' => Settings::searchBeginTime($beginKey),
        ], $related, true);

        $import($response);

        if ($cursor = $response->getResponse()->getCursor()) {
            $this->syncTask->setConfigData($cursorKey, $cursor)->save();
            $this->pullCatalogPages($objectType, $cursorKey, $beginKey, $related, $import);

            return;
        }

        $beginTime = $this->syncTask->getConfigData($pendingKey);
        if (is_string($beginTime) && $beginTime !== '') {
            Settings::rememberSearchBeginTime($beginKey, $beginTime);
        }

        $this->syncTask->forgetConfigData($cursorKey);
        $this->syncTask->forgetConfigData($pendingKey);
        $this->syncTask->save();
    }
}

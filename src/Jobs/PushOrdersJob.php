<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use Igniter\Cart\Models\Order;
use IgniterLabs\SquarePOS\Exceptions\ClientException;
use IgniterLabs\SquarePOS\Models\Settings;

class PushOrdersJob extends AbstractSyncJob
{
    public function execute(): void
    {
        if (!Settings::syncOrders()) {
            return;
        }

        $this->syncTask->note('Pushing TastyIgniter orders to Square');

        Order::squareExportItems($this->syncTask, function($orders): void {
            $orders->each(function($order): void {
                try {
                    if ($order->pushSquareOrder()) {
                        $this->syncTask->note(sprintf('Pushed order %s to Square', $order->getKey()));
                    }
                } catch (ClientException $clientException) {
                    $this->syncTask->note(sprintf('Order %s was not pushed: %s', $order->getKey(), $clientException->getMessage()));
                }
            });
        });
    }
}

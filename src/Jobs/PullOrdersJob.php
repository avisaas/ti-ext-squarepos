<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Jobs;

use Igniter\Cart\Models\Order;
use IgniterLabs\SquarePOS\Exceptions\ClientException;
use IgniterLabs\SquarePOS\Models\Settings;

class PullOrdersJob extends AbstractSyncJob
{
    public function execute(): void
    {
        if (!Settings::syncOrders()) {
            return;
        }

        $this->syncTask->note('Pulling Square fulfillment updates');

        Order::squarePullItems($this->syncTask, function($orders): void {
            $orders = $orders->keyBy(fn($order) => $order->squareOrder?->square_order_id)->filter(fn($order, $squareOrderId): bool => (bool)$squareOrderId);

            if ($orders->isEmpty()) {
                return;
            }

            try {
                $remoteOrders = $this->square->batchRetrieveOrders($orders->keys()->all());
            } catch (ClientException $clientException) {
                $this->syncTask->note($clientException->getMessage());

                return;
            }

            foreach ($remoteOrders as $remoteOrder) {
                $order = $orders->get($remoteOrder->getId());
                if (!$order) {
                    continue;
                }

                $order->applySquareFulfillment($remoteOrder, $this->syncTask);
            }
        });
    }
}

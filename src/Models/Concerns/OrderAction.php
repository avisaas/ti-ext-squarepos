<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Models\Concerns;

use Igniter\Admin\Models\Status;
use Igniter\Flame\Database\Model;
use Igniter\Flame\Traits\ExtensionTrait;
use Igniter\System\Actions\ModelAction;
use IgniterLabs\SquarePOS\Classes\MoneyAmount;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SquareOrder;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\Traits\ManagesSquareAttributes;
use Square\Orders\Requests\UpdateOrderRequest;
use Square\Types\Address;
use Square\Types\CreateOrderRequest;
use Square\Types\Fulfillment;
use Square\Types\FulfillmentDeliveryDetails;
use Square\Types\FulfillmentPickupDetails;
use Square\Types\FulfillmentRecipient;
use Square\Types\Order as RemoteOrder;
use Square\Types\OrderLineItem;
use Square\Types\OrderLineItemModifier;

class OrderAction extends ModelAction
{
    use ExtensionTrait;
    use ManagesSquareAttributes;

    public function __construct(?Model $model)
    {
        parent::__construct($model);

        $this->bootSquareOrder();
    }

    public function squareExportItems(SyncTask $syncTask, callable $callback): void
    {
        $this->model->newQuery()
            ->with([
                'squareOrder',
                'menus.menu.squareLink',
                'menus.menu_options.menu_option_value.option_value.squareLink',
                'address.country',
            ])
            ->whereDoesntHave('squareOrder', function($query): void {
                $query->whereNotNull('square_order_id');
            })
            ->where('created_at', '>=', now()->subDays(7))
            ->chunkById($syncTask->batchSize(), $callback);
    }

    public function squarePullItems(SyncTask $syncTask, callable $callback): void
    {
        $terminalIds = array_filter([
            Settings::completedStatusId(),
            Settings::canceledStatusId(),
        ]);

        $this->model->newQuery()
            ->with('squareOrder')
            ->whereHas('squareOrder', function($query): void {
                $query->whereNotNull('square_order_id');
            })
            ->when($terminalIds, function($query) use ($terminalIds): void {
                $query->where(function($inner) use ($terminalIds): void {
                    $inner->whereNull('status_id')->orWhereNotIn('status_id', $terminalIds);
                });
            })
            ->chunkById(min(100, max(1, (int)$syncTask->batchSize())), $callback);
    }

    public function pushSquareOrder(): ?RemoteOrder
    {
        $request = $this->asSquareOrder();
        if (!$request instanceof CreateOrderRequest) {
            return null;
        }

        $created = resolve(SquareClient::class)->createOrder($request);
        $this->rememberSquareOrder($created);

        return $created;
    }

    public function pushSquareStatus(): void
    {
        if (SyncContext::isApplyingRemote() || !$this->model->squareOrder?->square_order_id || !Settings::syncOrders()) {
            return;
        }

        $target = $this->squareStateForLocalStatus();
        if (!$target) {
            return;
        }

        $client = resolve(SquareClient::class);
        $remote = $client->retrieveOrder($this->model->squareOrder->square_order_id);
        if (!$remote) {
            return;
        }

        $request = $this->asSquareStatusUpdate($remote, $target);
        if (!$request instanceof UpdateOrderRequest) {
            $this->rememberSquareOrder($remote);

            return;
        }

        $this->rememberSquareOrder($client->updateOrder($request));
    }

    public function applySquareFulfillment(RemoteOrder $remoteOrder, ?SyncTask $syncTask = null): void
    {
        if ($remoteOrder->getReferenceId() && $remoteOrder->getReferenceId() !== (string)$this->model->getKey()) {
            return;
        }

        $state = $this->terminalFulfillmentState($remoteOrder);
        $this->rememberSquareOrder($remoteOrder);
        if (!$state) {
            return;
        }

        $this->applyLocalStatus($state, $syncTask);
    }

    public function asSquareOrder(): ?CreateOrderRequest
    {
        if (!Settings::isConnected() || !Settings::syncOrders() || $this->model->squareOrder?->square_order_id) {
            return null;
        }

        $locationId = Settings::remoteLocationId($this->model->location_id);
        $lineItems = $this->lineItems();
        if (!$locationId || !$lineItems) {
            return null;
        }

        return new CreateOrderRequest([
            'idempotencyKey' => 'ti-order-'.$this->model->getKey(),
            'order' => new RemoteOrder([
                'locationId' => $locationId,
                'referenceId' => (string)$this->model->getKey(),
                'lineItems' => $lineItems,
                'fulfillments' => [$this->fulfillment('PROPOSED')],
            ]),
        ]);
    }

    public function rememberSquareOrder(?RemoteOrder $remote): void
    {
        if (!$remote instanceof RemoteOrder) {
            return;
        }

        $fulfillment = ($remote->getFulfillments() ?? [])[0] ?? null;
        SyncContext::applying(function() use ($remote, $fulfillment): void {
            $record = SquareOrder::forOwner($this->model);
            $record->square_order_id = $remote->getId();
            $record->square_order_version = $remote->getVersion();
            $record->square_fulfillment_uid = $fulfillment ? $fulfillment->getUid() : $record->square_fulfillment_uid;
            SquareOrder::saveOwner($this->model);
        });
    }

    protected function asSquareStatusUpdate(RemoteOrder $remote, string $target): ?UpdateOrderRequest
    {
        $fulfillment = ($remote->getFulfillments() ?? [])[0] ?? null;
        if ($fulfillment && $fulfillment->getState() === $target) {
            return null;
        }

        if ($fulfillment) {
            $fulfillment->setState($target);
        } else {
            $fulfillment = $this->fulfillment($target);
        }

        $values = [
            'locationId' => $remote->getLocationId(),
            'version' => $remote->getVersion(),
            'fulfillments' => [$fulfillment],
        ];
        if (in_array($target, ['COMPLETED', 'CANCELED'], true)) {
            $values['state'] = $target;
        }

        return new UpdateOrderRequest([
            'orderId' => $this->model->squareOrder->square_order_id,
            'idempotencyKey' => 'ti-order-status-'.$this->model->getKey().'-'.$target,
            'order' => new RemoteOrder($values),
        ]);
    }

    protected function lineItems(): array
    {
        $this->model->loadMissing([
            'menus.menu.squareLink',
            'menus.menu_options.menu_option_value.option_value.squareLink',
        ]);

        return $this->model->menus->map(function($orderMenu): OrderLineItem {
            $values = [
                'quantity' => (string)max(1, (int)$orderMenu->quantity),
                'name' => $orderMenu->name,
                'basePriceMoney' => MoneyAmount::toSquareMoney($orderMenu->price),
            ];
            if ($variationId = optional(optional($orderMenu->menu)->squareLink)->square_variation_id) {
                $values['catalogObjectId'] = $variationId;
            }

            if ($orderMenu->comment) {
                $values['note'] = $orderMenu->comment;
            }

            $modifiers = collect($orderMenu->menu_options)->map(function($orderOption): OrderLineItemModifier {
                $modifier = [
                    'name' => $orderOption->order_option_name,
                    'basePriceMoney' => MoneyAmount::toSquareMoney($orderOption->order_option_price),
                ];
                $catalogId = optional(optional(optional($orderOption->menu_option_value)->option_value)->squareLink)->square_id;
                if ($catalogId) {
                    $modifier['catalogObjectId'] = $catalogId;
                }

                return new OrderLineItemModifier($modifier);
            })->all();

            if ($modifiers) {
                $values['modifiers'] = $modifiers;
            }

            return new OrderLineItem($values);
        })->all();
    }

    protected function fulfillment(string $state): Fulfillment
    {
        $recipient = [
            'displayName' => $this->model->customer_name,
        ];
        if ($this->model->email) {
            $recipient['emailAddress'] = $this->model->email;
        }

        if ($this->model->telephone) {
            $recipient['phoneNumber'] = $this->model->telephone;
        }

        $at = optional($this->model->order_datetime)->toRfc3339String();
        $values = ['state' => $state];

        if ($this->model->isDeliveryType()) {
            if ($this->model->address) {
                $address = array_filter([
                    'addressLine1' => $this->model->address->address_1,
                    'addressLine2' => $this->model->address->address_2,
                    'locality' => $this->model->address->city,
                    'administrativeDistrictLevel1' => $this->model->address->state,
                    'postalCode' => $this->model->address->postcode,
                    'country' => ($country = optional($this->model->address->country)->iso_code_2)
                        ? strtoupper($country)
                        : null,
                ], fn($value): bool => $value !== null && $value !== '');
                if ($address !== []) {
                    $recipient['address'] = new Address($address);
                }
            }

            $details = [
                'recipient' => new FulfillmentRecipient($recipient),
            ];
            if ($at) {
                $details['deliverAt'] = $at;
            }

            if ($this->model->comment) {
                $details['note'] = $this->model->comment;
            }

            $values['type'] = 'DELIVERY';
            $values['deliveryDetails'] = new FulfillmentDeliveryDetails($details);
        } else {
            $details = [
                'recipient' => new FulfillmentRecipient($recipient),
                'scheduleType' => $this->model->order_time_is_asap ? 'ASAP' : 'SCHEDULED',
            ];
            if ($at) {
                $details['pickupAt'] = $at;
            }

            if ($this->model->comment) {
                $details['note'] = $this->model->comment;
            }

            $values['type'] = 'PICKUP';
            $values['pickupDetails'] = new FulfillmentPickupDetails($details);
        }

        return new Fulfillment($values);
    }

    protected function terminalFulfillmentState(RemoteOrder $remoteOrder): ?string
    {
        foreach ($remoteOrder->getFulfillments() ?? [] as $fulfillment) {
            if (in_array($fulfillment->getState(), ['COMPLETED', 'CANCELED'], true)) {
                return $fulfillment->getState();
            }
        }

        if (in_array($remoteOrder->getState(), ['COMPLETED', 'CANCELED'], true)) {
            return $remoteOrder->getState();
        }

        return null;
    }

    protected function applyLocalStatus(string $state, ?SyncTask $syncTask = null): void
    {
        $statusId = $state === 'CANCELED' ? Settings::canceledStatusId() : Settings::completedStatusId();
        if (!$statusId) {
            $statusId = Status::isForOrder()
                ->where('status_name', 'like', $state === 'CANCELED' ? '%cancel%' : '%complete%')
                ->value('status_id');
        }

        if (!$statusId || (int)$this->model->status_id === (int)$statusId) {
            return;
        }

        SyncContext::applying(function() use ($statusId): void {
            $this->model->addStatusHistory($statusId);
        });

        optional($syncTask)->note(sprintf('Order %s marked %s from Square', $this->model->getKey(), strtolower($state)));
    }

    protected function squareStateForLocalStatus(): ?string
    {
        if (Settings::completedStatusId() && (int)$this->model->status_id === (int)Settings::completedStatusId()) {
            return 'COMPLETED';
        }

        if (Settings::canceledStatusId() && (int)$this->model->status_id === (int)Settings::canceledStatusId()) {
            return 'CANCELED';
        }

        return null;
    }
}

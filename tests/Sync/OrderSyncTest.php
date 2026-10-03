<?php

use Igniter\Admin\Models\Status;
use Igniter\Cart\Models\MenuOption;
use Igniter\Cart\Models\MenuOptionValue;
use Igniter\Cart\Models\Order;
use Igniter\Cart\Models\OrderMenu;
use Igniter\Cart\Models\OrderMenuOptionValue;
use Igniter\Local\Models\Location;
use Igniter\User\Models\Address;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Exceptions\ClientException;
use IgniterLabs\SquarePOS\Jobs\PullOrdersJob;
use IgniterLabs\SquarePOS\Jobs\PushOrdersJob;
use IgniterLabs\SquarePOS\Jobs\SyncOrdersJob;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SquareOrder;
use IgniterLabs\SquarePOS\Tests\Support\Square;
use Square\Types\Order as RemoteOrder;

beforeEach(function(): void {
    $this->completed = Status::factory()->create(['status_for' => 'order', 'status_name' => 'Completed']);
    $this->canceled = Status::factory()->create(['status_for' => 'order', 'status_name' => 'Canceled']);
    Square::configureSquare([
        'sync_orders' => true,
        'order_completed_status' => $this->completed->getKey(),
        'order_canceled_status' => $this->canceled->getKey(),
    ]);
    $this->location = Square::mapLocation();
    $this->square = Square::bindSquare();
    $this->menu = Square::makeMenu();
    Square::linkOwner($this->menu, ['square_variation_id' => 'VAR1']);
});

it('builds a delivery order and stores the square id', function(): void {
    $order = deliveryOrder($this->location, $this->menu, $this->completed);
    $this->square->shouldReceive('createOrder')->once()->andReturn(Square::remoteOrder('ORDER-1', (string)$order->getKey()));

    expect($order->asSquareOrder())->not->toBeNull()
        ->and($order->pushSquareOrder()->getId())->toBe('ORDER-1')
        ->and($order->fresh()->squareOrder->square_order_id)->toBe('ORDER-1')
        ->and($order->pushSquareOrder())->toBeNull();
});

it('builds a pickup fulfillment for a collection order', function(): void {
    $option = MenuOption::factory()->create(['display_type' => 'radio', 'option_name' => 'Spice']);
    $optionValue = MenuOptionValue::factory()->create([
        'option_id' => $option->getKey(),
        'name' => 'Hot',
        'price' => 1,
    ]);
    Square::linkOwner($optionValue, ['square_id' => 'MOD1']);
    $itemOption = $this->menu->menu_options()->create(['option_id' => $option->getKey(), 'min_selected' => 0, 'max_selected' => 1]);
    $itemValue = $itemOption->menu_option_values()->create(['option_value_id' => $optionValue->getKey(), 'priority' => 1]);

    $pickup = Order::factory()->create([
        'location_id' => $this->location->getKey(),
        'order_type' => 'collection',
        'order_time_is_asap' => false,
        'order_date' => now()->addDay()->toDateString(),
        'order_time' => '18:00:00',
        'comment' => 'Gate',
        'email' => 'pickup@example.com',
        'telephone' => '07000000000',
        'created_at' => now()->subDays(8),
    ]);
    $line = OrderMenu::create([
        'order_id' => $pickup->getKey(),
        'menu_id' => $this->menu->getKey(),
        'name' => 'Pickup',
        'quantity' => 1,
        'price' => 4,
        'comment' => 'extra',
    ]);
    OrderMenuOptionValue::create([
        'order_id' => $pickup->getKey(),
        'order_menu_id' => $line->getKey(),
        'order_option_name' => 'Hot',
        'order_option_price' => 1,
        'menu_option_value_id' => $itemValue->getKey(),
    ]);

    $asap = Order::factory()->create([
        'location_id' => $this->location->getKey(),
        'order_type' => 'collection',
        'order_time_is_asap' => true,
        'email' => 'asap@example.com',
        'telephone' => '07000000000',
        'created_at' => now()->subDays(8),
    ]);
    OrderMenu::create([
        'order_id' => $asap->getKey(),
        'menu_id' => $this->menu->getKey(),
        'name' => 'Pickup',
        'quantity' => 0,
        'price' => 1,
    ]);

    expect($pickup->asSquareOrder()->getOrder()->getFulfillments()[0]->getType())->toBe('PICKUP')
        ->and($asap->asSquareOrder()->getOrder()->getFulfillments()[0]->getType())->toBe('PICKUP');
});

it('pushes completed and canceled fulfillment', function(): void {
    $order = deliveryOrder($this->location, $this->menu, $this->completed);
    SquareOrder::forOwner($order)->forceFill(['square_order_id' => 'ORDER-1'])->save();
    SquareOrder::saveOwner($order);

    $this->square->shouldReceive('retrieveOrder')->andReturn(
        Square::remoteOrder('ORDER-1', (string)$order->getKey(), 'COMPLETED'),
        Square::remoteOrder('ORDER-1', (string)$order->getKey(), 'PROPOSED'),
    );
    $this->square->shouldReceive('updateOrder')->andReturn(
        Square::remoteOrder('ORDER-1', (string)$order->getKey(), 'COMPLETED', 'COMPLETED'),
        Square::remoteOrder('ORDER-1', (string)$order->getKey(), 'CANCELED', 'CANCELED'),
    );

    $order->pushSquareStatus();
    $order->pushSquareStatus();

    $order->status_id = $this->canceled->getKey();
    $order->save();
    $order->unsetRelation('squareOrder');
    $order->pushSquareStatus();

    expect($order->fresh()->squareOrder->square_order_id)->toBe('ORDER-1');
});

it('does nothing when there is no remote order to update', function(): void {
    $order = deliveryOrder($this->location, $this->menu, $this->completed);
    SquareOrder::forOwner($order)->forceFill(['square_order_id' => 'ORDER-1'])->save();
    SquareOrder::saveOwner($order);
    $this->square->shouldReceive('retrieveOrder')->andReturn(null);

    $order->pushSquareStatus();

    $this->square->shouldHaveReceived('retrieveOrder');
});

it('skips a status push while a remote change is being applied', function(): void {
    $order = deliveryOrder($this->location, $this->menu, $this->completed);
    SquareOrder::forOwner($order)->forceFill(['square_order_id' => 'ORDER-1'])->save();
    SquareOrder::saveOwner($order);

    SyncContext::applying(function() use ($order): void {
        $order->pushSquareStatus();
    });

    $this->square->shouldNotHaveReceived('retrieveOrder');
});

it('does not build an order without a mapped location', function(): void {
    $order = Order::factory()->create([
        'location_id' => Location::factory()->create()->getKey(),
        'created_at' => now(),
    ]);

    expect($order->asSquareOrder())->toBeNull();
});

it('does not build an order when sync is disconnected', function(): void {
    $order = deliveryOrder($this->location, $this->menu, $this->completed);
    Square::configureSquare(['sync_orders' => false, 'connection_status' => false]);

    expect($order->asSquareOrder())->toBeNull();
});

it('notes a push failure and continues', function(): void {
    deliveryOrder($this->location, $this->menu, Status::factory()->create(['status_for' => 'order', 'status_name' => 'Received']));
    $this->square->shouldReceive('createOrder')->andThrow(new ClientException('order failed'));

    $task = Square::runPipe(PushOrdersJob::class);

    expect(implode(' ', $task->notes ?? []))->toContain('was not pushed');
});

it('pushes an order that has no square id yet', function(): void {
    $order = deliveryOrder($this->location, $this->menu, $this->completed);
    $this->square->shouldReceive('createOrder')->andReturn(new RemoteOrder([
        'id' => 'ORDER-NEW',
        'locationId' => 'LOC1',
        'version' => 1,
    ]));

    Square::runPipe(PushOrdersJob::class);

    expect($order->fresh()->squareOrder->square_order_id)->toBe('ORDER-NEW');
});

it('ignores a square order that belongs to a different local order', function(): void {
    $pending = Status::factory()->create(['status_for' => 'order', 'status_name' => 'Received']);
    $order = Order::factory()->create([
        'location_id' => $this->location->getKey(),
        'status_id' => $pending->getKey(),
        'created_at' => now(),
    ]);
    SquareOrder::forOwner($order)->forceFill(['square_order_id' => 'ORDER-1'])->save();
    SquareOrder::saveOwner($order);

    $order->applySquareFulfillment(Square::remoteOrder('ORDER-1', '999', 'COMPLETED'), Square::makeSyncTask());

    expect($order->fresh()->status_id)->toBe($pending->getKey());
});

it('uses the order state when no fulfillment is terminal', function(): void {
    $pending = Status::factory()->create(['status_for' => 'order', 'status_name' => 'Received']);
    $order = Order::factory()->create([
        'location_id' => $this->location->getKey(),
        'status_id' => $pending->getKey(),
        'created_at' => now(),
    ]);
    $remote = Square::remoteOrder('ORDER-STATE', (string)$order->getKey(), 'PROPOSED', 'COMPLETED');

    $order->applySquareFulfillment($remote, Square::makeSyncTask());

    expect($order->fresh()->status_id)->toBe($this->completed->getKey());
});

it('does not rewrite a status that is already applied', function(): void {
    $order = Order::factory()->create([
        'location_id' => $this->location->getKey(),
        'status_id' => $this->completed->getKey(),
        'created_at' => now(),
    ]);

    $order->applySquareFulfillment(Square::remoteOrder('ORDER-DONE', (string)$order->getKey(), 'COMPLETED'), Square::makeSyncTask());

    expect($order->fresh()->status_id)->toBe($this->completed->getKey());
});

it('pulls a canceled fulfillment onto the local order', function(): void {
    $pending = Status::factory()->create(['status_for' => 'order', 'status_name' => 'Received']);
    $open = Order::factory()->create([
        'location_id' => $this->location->getKey(),
        'status_id' => $pending->getKey(),
        'created_at' => now(),
    ]);
    SquareOrder::forOwner($open)->forceFill(['square_order_id' => 'ORDER-OPEN'])->save();
    SquareOrder::saveOwner($open);
    $this->square->shouldReceive('batchRetrieveOrders')->andReturn([
        Square::remoteOrder('ORDER-OPEN', (string)$open->getKey(), 'CANCELED', 'CANCELED'),
        Square::remoteOrder('ORDER-EXTRA', '999', 'COMPLETED'),
    ]);

    Square::runPipe(PullOrdersJob::class);

    expect($open->fresh()->status_id)->toBe($this->canceled->getKey());
});

it('records a batch failure and leaves the order alone', function(): void {
    $pending = Status::factory()->create(['status_for' => 'order', 'status_name' => 'Received']);
    $open = Order::factory()->create([
        'location_id' => $this->location->getKey(),
        'status_id' => $pending->getKey(),
        'created_at' => now(),
    ]);
    SquareOrder::forOwner($open)->forceFill(['square_order_id' => 'ORDER-OPEN'])->save();
    SquareOrder::saveOwner($open);
    $this->square->shouldReceive('batchRetrieveOrders')->andThrow(new ClientException('batch failed'));

    $task = Square::runPipe(PullOrdersJob::class);

    expect(implode(' ', $task->notes ?? []))->toContain('batch failed')
        ->and($open->fresh()->status_id)->toBe($pending->getKey());
});

it('ignores a fulfillment when terminal statuses are not configured', function(): void {
    $open = Order::factory()->create([
        'location_id' => $this->location->getKey(),
        'created_at' => now(),
    ]);
    SquareOrder::forOwner($open)->forceFill(['square_order_id' => 'ORDER-OPEN'])->save();
    SquareOrder::saveOwner($open);
    Settings::set(['order_completed_status' => null, 'order_canceled_status' => null]);
    $remote = Square::remoteOrder('ORDER-OPEN', (string)$open->getKey(), 'COMPLETED');
    $this->square->shouldReceive('batchRetrieveOrders')->andReturn([$remote]);

    Square::runPipe(PullOrdersJob::class);

    $bare = Square::remoteOrder('ORDER-BARE', (string)$open->getKey());
    $bare->setFulfillments();
    $open->applySquareFulfillment($bare, Square::makeSyncTask());
    $open->rememberSquareOrder(null);

    expect($open->fresh()->squareOrder->square_order_id)->toBe('ORDER-BARE');
});

it('skips orders that already have a square id or a blank one', function(): void {
    $blank = Order::factory()->create([
        'location_id' => $this->location->getKey(),
        'created_at' => now(),
    ]);
    SquareOrder::forOwner($blank)->forceFill(['square_order_id' => ''])->save();
    SquareOrder::saveOwner($blank);

    $task = Square::runPipe(PullOrdersJob::class);

    expect($task->notes)->not->toBeEmpty();
});

it('does nothing when order sync is disabled', function(): void {
    Square::configureSquare(['sync_orders' => false]);

    expect(Square::runPipe(PushOrdersJob::class)->notes)->toBeNull()
        ->and(Square::runPipe(PullOrdersJob::class)->notes)->toBeNull();

    $task = Square::makeSyncTask();
    (new SyncOrdersJob($task))->handle();

    expect($task->fresh()->notes)->toContain('Order fulfillment sync is disabled');
});

it('rethrows an unexpected batch failure', function(): void {
    Square::configureSquare(['sync_orders' => true, 'sync_notify_email' => 'ops@example.com']);
    $pending = Status::factory()->create(['status_for' => 'order', 'status_name' => 'Received']);
    $open = Order::factory()->create([
        'location_id' => $this->location->getKey(),
        'status_id' => $pending->getKey(),
        'created_at' => now(),
    ]);
    SquareOrder::forOwner($open)->forceFill(['square_order_id' => 'ORDER-OPEN'])->save();
    SquareOrder::saveOwner($open);
    $this->square->shouldReceive('batchRetrieveOrders')->andThrow(new RuntimeException('batch failed'));

    expect(fn() => (new SyncOrdersJob(Square::makeSyncTask()))->handle())->toThrow(RuntimeException::class);
});

it('updates a canceled order that comes back with an open fulfillment', function(): void {
    $order = deliveryOrder($this->location, $this->menu, $this->canceled);
    SquareOrder::forOwner($order)->forceFill(['square_order_id' => 'ORDER-NEW'])->save();
    SquareOrder::saveOwner($order);
    $this->square->shouldReceive('retrieveOrder')->andReturn(new RemoteOrder([
        'id' => 'ORDER-NEW',
        'locationId' => 'LOC1',
        'version' => 4,
        'state' => 'OPEN',
        'fulfillments' => [],
    ]));
    $this->square->shouldReceive('updateOrder')->andReturn(new RemoteOrder([
        'id' => 'ORDER-NEW',
        'locationId' => 'LOC1',
        'version' => 5,
        'state' => 'CANCELED',
    ]));

    $order->pushSquareStatus();

    expect($order->fresh()->squareOrder->square_order_id)->toBe('ORDER-NEW');
});

function deliveryOrder($location, $menu, Status $status): Order
{
    $option = MenuOption::factory()->create(['display_type' => 'radio']);
    $optionValue = MenuOptionValue::factory()->create([
        'option_id' => $option->getKey(),
        'name' => 'Hot',
        'price' => 0.5,
    ]);
    Square::linkOwner($optionValue, ['square_id' => 'MOD1']);

    $order = Order::factory()->create([
        'location_id' => $location->getKey(),
        'order_type' => 'delivery',
        'order_time_is_asap' => false,
        'status_id' => $status->getKey(),
        'email' => 'guest@example.com',
        'telephone' => '07000000000',
        'comment' => 'Door',
        'created_at' => now(),
    ]);
    $address = Address::factory()->create();
    $order->address_id = $address->getKey();
    $order->save();
    $line = OrderMenu::create([
        'order_id' => $order->getKey(),
        'menu_id' => $menu->getKey(),
        'name' => 'AMALA',
        'quantity' => 2,
        'price' => 4.5,
        'comment' => 'extra',
    ]);
    OrderMenuOptionValue::create([
        'order_id' => $order->getKey(),
        'order_menu_id' => $line->getKey(),
        'order_option_name' => 'Hot',
        'order_option_price' => 0.5,
        'menu_option_value_id' => $optionValue->getKey(),
    ]);

    return $order->fresh();
}

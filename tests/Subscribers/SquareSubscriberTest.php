<?php

use Igniter\Admin\Models\Status;
use Igniter\Admin\Widgets\Form;
use Igniter\Admin\Widgets\Lists;
use Igniter\Cart\Http\Controllers\Menus;
use Igniter\Cart\Http\Requests\MenuRequest;
use Igniter\Cart\Models\Category;
use Igniter\Cart\Models\Menu;
use Igniter\Cart\Models\MenuOption;
use Igniter\Cart\Models\MenuOptionValue;
use Igniter\Cart\Models\Order;
use Igniter\Cart\Models\OrderMenu;
use IgniterLabs\SquarePOS\BulkActionWidgets\SquareSync;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Classes\SyncContext;
use IgniterLabs\SquarePOS\Exceptions\ClientException;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SquareLink;
use IgniterLabs\SquarePOS\Models\SquareOrder;
use IgniterLabs\SquarePOS\Subscribers\MenuFormValidationSubscriber;
use IgniterLabs\SquarePOS\Subscribers\SquareSubscriber;
use IgniterLabs\SquarePOS\Tests\Support\Square;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

it('extends the menu form, list, and bulk actions', function(): void {
    $menu = Square::makeMenu();
    $controller = new Menus;
    $form = new Form($controller, ['model' => $menu, 'arrayName' => 'Menu', 'fields' => []]);
    (new ReflectionMethod($form, 'defineFormFields'))->invoke($form);
    expect($form->getField('squareLink[square_sku]'))->not->toBeNull()
        ->and($menu->squareLink)->not->toBeNull();

    $linked = Square::makeMenu();
    Square::linkOwner($linked, ['square_sku' => 'KEEP']);
    $existing = new Form($controller, ['model' => $linked->fresh(), 'arrayName' => 'Menu', 'fields' => []]);
    (new ReflectionMethod($existing, 'defineFormFields'))->invoke($existing);

    $categoryForm = new Form($controller, ['model' => Category::factory()->create(), 'arrayName' => 'Category', 'fields' => []]);
    (new ReflectionMethod($categoryForm, 'defineFormFields'))->invoke($categoryForm);

    $list = new Lists($controller, [
        'model' => new Menu,
        'columns' => ['menu_name' => ['label' => 'Name']],
    ]);
    (new ReflectionMethod($list, 'defineListColumns'))->invoke($list);
    Event::dispatch('admin.list.extendBulkActions', [$list]);
    expect($list->bulkActions)->toHaveKey('square_sync');

    $other = new Lists($controller, [
        'model' => new Category,
        'columns' => ['name' => ['label' => 'Name']],
    ]);
    (new ReflectionMethod($other, 'defineListColumns'))->invoke($other);
    Event::dispatch('admin.list.extendBulkActions', [$other]);
});

it('marks local catalog edits dirty and ignores remote applies', function(): void {
    $menu = Square::makeMenu();
    Square::linkOwner($menu, ['square_sku' => 'SKU', 'square_dirty' => false]);
    $menu->menu_name = 'Renamed';
    $menu->save();
    expect($menu->fresh()->squareLink->square_dirty)->toBeTrue();

    $category = Category::factory()->create();
    Square::linkOwner($category, ['square_dirty' => false]);
    $category->name = 'Renamed category';
    $category->save();
    expect($category->fresh()->squareLink->square_dirty)->toBeTrue();

    $option = MenuOption::factory()->create(['display_type' => 'radio']);
    Square::linkOwner($option, ['square_dirty' => false]);
    $option->option_name = 'Renamed option';
    $option->save();

    $value = MenuOptionValue::factory()->create([
        'option_id' => $option->getKey(),
        'name' => 'Mild',
        'price' => 1,
    ]);
    expect($option->fresh()->squareLink->square_dirty)->toBeTrue();

    $value->name = 'Hot';
    $value->save();
    expect($option->fresh()->squareLink->square_dirty)->toBeTrue();

    SyncContext::applying(function() use ($menu): void {
        $menu->menu_name = 'Remote name';
        $menu->save();
    });

    $plain = Square::makeMenu();
    $plain->menu_name = 'No link yet';
    $plain->save();

    $sku = Square::makeMenu();
    Square::linkOwner($sku, ['square_sku' => 'OLD', 'square_dirty' => false]);
    $sku->unsetRelation('squareLink');
    $sku->squareLink->square_sku = 'NEW';
    $sku->save();
    expect($sku->fresh()->squareLink->square_dirty)->toBeTrue();

    SquareLink::markDirty((new Menu)->getMorphClass(), null);
    SquareLink::markDirty((new Menu)->getMorphClass(), $plain->getKey());
    expect(SquareLink::findOwner(Menu::class, null))->toBeNull()
        ->and(SquareLink::findOwner(Menu::class, 'missing'))->toBeNull();

    $query = Menu::query();
    SquareLink::constrainEnabled($query);
    expect($query->pluck('menu_id'))->toContain($plain->getKey());

    $link = new SquareLink(['square_sync' => 0]);
    expect($link->isSyncEnabled())->toBeFalse()
        ->and((new SquareLink(['square_sync' => null]))->isSyncEnabled())->toBeTrue()
        ->and($link->square_sync)->toBeFalse();
});

it('pushes orders from checkout and status events', function(): void {
    Square::configureSquare(['sync_orders' => true]);
    $location = Square::mapLocation();
    $client = Square::bindSquare();
    $menu = Square::makeMenu();
    Square::linkOwner($menu, ['square_variation_id' => 'VAR1']);
    $order = Order::factory()->create([
        'location_id' => $location->getKey(),
        'created_at' => now(),
    ]);
    OrderMenu::create([
        'order_id' => $order->getKey(),
        'menu_id' => $menu->getKey(),
        'name' => 'AMALA',
        'quantity' => 1,
        'price' => 4,
    ]);

    $subscriber = new SquareSubscriber;
    $subscriber->pushOrder(new stdClass);
    $subscriber->pushOrderStatus(new stdClass);

    $failCreate = true;
    $created = null;
    $client->shouldReceive('createOrder')->andReturnUsing(function(): null {
        throw new ClientException('order failed');
    });
    Event::dispatch('igniter.checkout.afterSaveOrder', [$order]);

    $failCreate = false;
    $created = new \Square\Types\Order([
        'id' => 'ORDER-1',
        'locationId' => 'LOC1',
        'version' => 1,
    ]);
    Event::dispatch('igniter.checkout.afterSaveOrder', [$order->fresh()]);

    $subscriber->pushOrderStatus($order->fresh());

    $completed = Status::factory()->create([
        'status_for' => 'order',
        'status_name' => 'Completed',
    ]);
    Settings::set('order_completed_status', $completed->getKey());
    $order->status_id = $completed->getKey();
    $order->save();
    SquareOrder::forOwner($order)->forceFill(['square_order_id' => 'ORDER-1'])->save();
    SquareOrder::saveOwner($order);

    $throwing = Mockery::mock(SquareClient::class);
    $throwing->shouldReceive('retrieveOrder')->andThrow(new RuntimeException('status down'));
    app()->instance(SquareClient::class, $throwing);
    $subscriber->pushOrderStatus($order->fresh());

    SyncContext::applying(function() use ($subscriber, $order): void {
        $subscriber->pushOrderStatus($order);
    });

    Square::configureSquare(['sync_orders' => false]);
    Event::dispatch('igniter.checkout.afterSaveOrder', [$order]);

    $client->shouldHaveReceived('createOrder');
});

it('validates square fields on the menu form', function(): void {
    $menu = Square::makeMenu();
    Square::linkOwner($menu, ['square_sku' => 'TAKEN']);

    $subscriber = new MenuFormValidationSubscriber;
    expect($subscriber->subscribe(app('events')))->toHaveKey('system.formRequest.extendValidator');

    $holder = new stdClass;
    $holder->attributes = [];
    $holder->rules = [];
    $subscriber->extendMenuValidator(new stdClass, $holder);
    expect($holder->rules)->toBe([]);

    $request = MenuRequest::create('/admin/menus/edit/'.$menu->getKey(), 'POST');
    $request->setRouteResolver(fn(): object => new class($menu)
    {
        public function __construct(private $menu) {}

        public function parameter($name, $default = null)
        {
            return $name === 'slug' ? 'edit/'.$this->menu->getKey() : $default;
        }
    });

    $holder = new stdClass;
    $holder->attributes = [];
    $holder->rules = [];
    Event::dispatch('system.formRequest.extendValidator', [$request, $holder]);

    $valid = Validator::make([
        'squareLink' => ['square_sync' => 1, 'square_sku' => 'TAKEN', 'square_stock_mode' => 'push'],
    ], $holder->rules, [], $holder->attributes);
    expect($valid->passes())->toBeTrue();

    $create = MenuRequest::create('/admin/menus/create', 'POST');
    $create->setRouteResolver(fn(): object => new class
    {
        public function parameter($name, $default = null)
        {
            return $name === 'slug' ? 'create' : $default;
        }
    });
    $createHolder = new stdClass;
    $createHolder->attributes = [];
    $createHolder->rules = [];
    $subscriber->extendMenuValidator($create, $createHolder);
    $invalid = Validator::make([
        'squareLink' => ['square_sync' => 'yes', 'square_sku' => 'TAKEN', 'square_stock_mode' => 'ti'],
    ], $createHolder->rules, [], $createHolder->attributes);
    expect(fn() => $invalid->validate())->toThrow(ValidationException::class);
});

it('enables and disables square sync in bulk', function(): void {
    $menu = Square::makeMenu();
    $widget = (new ReflectionClass(SquareSync::class))->newInstanceWithoutConstructor();
    $widget->handleAction(['code' => 'square_sync.disable'], collect([$menu]));
    expect($menu->fresh()->squareLink->square_sync)->toBeFalse();

    $second = Square::makeMenu();
    $widget->handleAction(['code' => 'square_sync.enable'], collect([$menu, $second]));
    expect($menu->fresh()->squareLink->square_sync)->toBeTrue();

    $widget->handleAction(['code' => 'square_sync.enable'], collect());
});

<?php

use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Exceptions\ClientException;
use IgniterLabs\SquarePOS\Tests\Support\Square;
use Square\Catalog\CatalogClient;
use Square\Catalog\Images\ImagesClient;
use Square\Core\Pagination\CursorPager;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\Inventory\InventoryClient;
use Square\Locations\LocationsClient;
use Square\OAuth\OAuthClient;
use Square\Orders\OrdersClient;
use Square\Orders\Requests\UpdateOrderRequest;
use Square\Types\BatchChangeInventoryResponse;
use Square\Types\BatchDeleteCatalogObjectsResponse;
use Square\Types\BatchGetCatalogObjectsResponse;
use Square\Types\BatchGetOrdersResponse;
use Square\Types\BatchUpsertCatalogObjectsResponse;
use Square\Types\CreateCatalogImageResponse;
use Square\Types\CreateOrderRequest;
use Square\Types\CreateOrderResponse;
use Square\Types\GetOrderResponse;
use Square\Types\InventoryCount;
use Square\Types\ListLocationsResponse;
use Square\Types\Location;
use Square\Types\ObtainTokenResponse;
use Square\Types\Order;
use Square\Types\SearchCatalogObjectsResponse;
use Square\Types\UpdateOrderResponse;

function bindSdk(array $clients): SquareClient
{
    $sdk = (new ReflectionClass(\Square\SquareClient::class))->newInstanceWithoutConstructor();
    foreach ($clients as $property => $client) {
        $sdk->{$property} = $client;
    }

    app()->instance(\Square\SquareClient::class, $sdk);

    return new SquareClient;
}

function squarePager(array $items): CursorPager
{
    return new CursorPager(
        new stdClass,
        fn(): array => $items,
        function(): void {},
        fn(): ?string => null,
        fn(array $page): array => $page,
    );
}

it('lists active locations and rejects a failed response', function(): void {
    $locations = Mockery::mock(LocationsClient::class);
    $locations->shouldReceive('list')->once()->andReturn(new ListLocationsResponse([
        'locations' => [
            new Location([
                'id' => 'L1',
                'name' => 'Shop',
                'status' => 'ACTIVE',
                'capabilities' => ['CREDIT_CARD_PROCESSING'],
            ]),
            new Location([
                'status' => 'INACTIVE',
                'capabilities' => ['CREDIT_CARD_PROCESSING'],
            ]),
            new Location([
                'status' => 'ACTIVE',
            ]),
        ],
    ]));
    $locations->shouldReceive('list')->once()->andThrow(new SquareApiException('rejected', 400, null));
    $client = bindSdk(['locations' => $locations]);

    expect($client->listLocations())->toHaveCount(2);
    expect(fn() => $client->listLocations())->toThrow(ClientException::class);
});

it('normalizes catalog search types', function(): void {
    expect(SquareClient::resolveObjectTypes([]))->toBeNull()
        ->and(SquareClient::resolveObjectTypes(['types' => []]))->toBeNull()
        ->and(SquareClient::resolveObjectTypes(['object_types' => 'ITEM']))->toBe(['ITEM'])
        ->and(SquareClient::resolveObjectTypes(['types' => ['CATEGORY', 'ITEM']]))->toBe(['CATEGORY', 'ITEM']);
});

it('searches, lists, retrieves, upserts, and deletes catalog objects', function(): void {
    $result = new SearchCatalogObjectsResponse([
        'objects' => [],
        'idMappings' => [],
    ]);

    $image = new CreateCatalogImageResponse([
        'image' => Square::squareObject('IMAGE', 'IMG'),
    ]);
    $images = Mockery::mock(ImagesClient::class);
    $images->shouldReceive('create')->twice()->andReturn($image);
    $images->shouldReceive('create')->once()->andThrow(new SquareApiException('rejected', 400, null));

    $catalog = Mockery::mock(CatalogClient::class);
    $catalog->images = $images;
    $catalog->shouldReceive('search')->once()->andReturn($result);
    $catalog->shouldReceive('search')->once()->andThrow(new SquareException('search down'));
    $catalog->shouldReceive('list')->once()->andReturn(squarePager([Square::squareObject('ITEM', 'LISTED')]));
    $catalog->shouldReceive('list')->once()->andThrow(new SquareApiException('rejected', 400, null));
    $catalog->shouldReceive('batchGet')->once()->andReturn(new BatchGetCatalogObjectsResponse);
    $catalog->shouldReceive('batchGet')->once()->andThrow(new SquareApiException('rejected', 400, null));
    $catalog->shouldReceive('batchUpsert')->once()->andReturn(new BatchUpsertCatalogObjectsResponse);
    $catalog->shouldReceive('batchUpsert')->once()->andThrow(new SquareApiException('rejected', 400, null));
    $catalog->shouldReceive('batchDelete')->once()->andReturn(new BatchDeleteCatalogObjectsResponse);
    $catalog->shouldReceive('batchDelete')->once()->andThrow(new SquareApiException('rejected', 400, null));

    $client = bindSdk(['catalog' => $catalog]);
    $path = tempnam(sys_get_temp_dir(), 'sqimg');
    file_put_contents($path, 'img');

    expect($client->searchCatalogObjects([
        'object_types' => ['ITEM'],
        'cursor' => 'c',
        'limit' => 5,
        'beginTime' => '2020-01-01T00:00:00Z',
    ], true, true)->getResponse())->toBe($result)
        ->and($client->listCatalogObjects('ITEM', 'cursor')->getObjects())->toHaveCount(1)
        ->and($client->batchRetrieveCatalogObjects(['A'], true))->toBeInstanceOf(CatalogResponse::class)
        ->and($client->batchUpsertCatalogObjects([]))->toBeInstanceOf(CatalogResponse::class)
        ->and($client->batchDeleteCatalogObjects(['A']))->toBeInstanceOf(CatalogResponse::class)
        ->and(Square::squareRecord($client->createCatalogImage('key', Square::squareObject('IMAGE', '#temp'), $path, 'ITEM', 'image/png'))->getId())->toBe('IMG')
        ->and(Square::squareRecord($client->createCatalogImage('key', Square::squareObject('IMAGE', '#temp'), $path))->getId())->toBe('IMG');

    expect(fn(): CatalogResponse => $client->searchCatalogObjects([]))->toThrow(ClientException::class, 'search down');
    expect(fn(): CatalogResponse => $client->listCatalogObjects('ITEM'))->toThrow(ClientException::class);
    expect(fn(): CatalogResponse => $client->batchRetrieveCatalogObjects(['A']))->toThrow(ClientException::class);
    expect(fn(): CatalogResponse => $client->batchUpsertCatalogObjects([]))->toThrow(ClientException::class);
    expect(fn(): CatalogResponse => $client->batchDeleteCatalogObjects(['A']))->toThrow(ClientException::class);
    expect(fn() => $client->createCatalogImage('key', Square::squareObject('IMAGE', '#temp'), $path))->toThrow(ClientException::class);

    unlink($path);
});

it('changes and retrieves inventory counts', function(): void {
    $changed = new BatchChangeInventoryResponse;
    $inventory = Mockery::mock(InventoryClient::class);
    $inventory->shouldReceive('batchCreateChanges')->once()->andReturn($changed);
    $inventory->shouldReceive('batchCreateChanges')->once()->andThrow(new SquareApiException('rejected', 400, null));
    $inventory->shouldReceive('batchGetCounts')->twice()->andReturn(squarePager([
        new InventoryCount([
            'catalogObjectId' => 'V',
            'state' => 'IN_STOCK',
            'quantity' => '1',
        ]),
    ]));
    $inventory->shouldReceive('batchGetCounts')->once()->andThrow(new SquareApiException('rejected', 400, null));
    $client = bindSdk(['inventory' => $inventory]);

    expect($client->batchChangeInventory([]))->toBe($changed)
        ->and($client->batchRetrieveInventoryCounts(['V'], ['L'], 'cursor')->getCounts())->toHaveCount(1)
        ->and($client->batchRetrieveInventoryCounts(['V'], ['L'])->getCursor())->toBeNull();

    expect(fn() => $client->batchChangeInventory([]))->toThrow(ClientException::class);
    expect(fn() => $client->batchRetrieveInventoryCounts(['V'], ['L']))->toThrow(ClientException::class);
});

it('creates, updates, retrieves, and batches orders through the current sdk', function(): void {
    $order = new Order(['locationId' => 'L1', 'id' => 'ORDER']);
    $orders = Mockery::mock(OrdersClient::class);
    $orders->shouldReceive('create')->andReturn(new CreateOrderResponse(['order' => $order]));
    $orders->shouldReceive('update')->andReturn(new UpdateOrderResponse(['order' => $order]));
    $orders->shouldReceive('get')->andReturn(new GetOrderResponse(['order' => $order]));
    $orders->shouldReceive('batchGet')->andReturn(new BatchGetOrdersResponse(['orders' => [$order]]));

    $client = bindSdk(['orders' => $orders]);
    $request = new CreateOrderRequest(['order' => $order, 'idempotencyKey' => 'once']);
    $update = new UpdateOrderRequest(['orderId' => 'ORDER', 'order' => $order]);

    expect($client->createOrder($request)->getId())->toBe('ORDER')
        ->and($client->updateOrder($update)->getId())->toBe('ORDER')
        ->and($client->retrieveOrder('ORDER')->getId())->toBe('ORDER')
        ->and($client->batchRetrieveOrders(['ORDER']))->toHaveCount(1);

    $failing = Mockery::mock(OrdersClient::class);
    $failing->shouldReceive('create')->andThrow(new SquareApiException('no', 400, null));
    $failing->shouldReceive('get')->andThrow(new SquareException('network'));
    $broken = bindSdk(['orders' => $failing]);

    expect(fn() => $broken->createOrder($request))->toThrow(ClientException::class);
    expect(fn() => $broken->retrieveOrder('ORDER'))->toThrow(ClientException::class, 'network');
});

it('refreshes an access token and surfaces oauth errors', function(): void {
    $token = new ObtainTokenResponse(['accessToken' => 'fresh']);
    $oauth = Mockery::mock(OAuthClient::class);
    $oauth->shouldReceive('obtainToken')->once()->andReturn($token);
    $oauth->shouldReceive('obtainToken')->once()->andThrow(new SquareApiException('rejected', 401, null));
    $client = bindSdk(['oAuth' => $oauth]);

    expect($client->refreshAccessToken('id', 'secret', 'refresh'))->toBe($token);
    expect(fn() => $client->refreshAccessToken('id', 'secret', 'refresh'))->toThrow(ClientException::class);
});

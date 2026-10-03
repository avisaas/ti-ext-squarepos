<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Tests\Support;

use Igniter\Cart\Models\Menu;
use Igniter\Local\Models\Location;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use IgniterLabs\SquarePOS\Classes\CatalogResponse;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SquareLink;
use IgniterLabs\SquarePOS\Models\SyncTask;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use Square\Types\CatalogIdMapping;
use Square\Types\CatalogItem;
use Square\Types\CatalogItemVariation;
use Square\Types\CatalogObject;
use Square\Types\CatalogObjectCategory;
use Square\Types\CatalogObjectImage;
use Square\Types\CatalogObjectItem;
use Square\Types\CatalogObjectItemVariation;
use Square\Types\CatalogObjectModifier;
use Square\Types\CatalogObjectModifierList;
use Square\Types\Fulfillment;
use Square\Types\InventoryCount;
use Square\Types\Money;
use Square\Types\Order as RemoteOrder;

class Square
{
    public static function configureSquare(array $settings = []): void
    {
        Settings::set(array_merge([
            'connection_status' => true,
            'connection_data' => [
                'merchant_id' => 'MERCHANT',
                'access_token' => 'token',
                'refresh_token' => null,
                'expires_at' => null,
            ],
            'environment' => 'sandbox',
            'source' => Settings::IGNITER_SOR,
            'sync_catalog' => true,
            'sync_inventory' => false,
            'sync_orders' => false,
            'sync_batch_size' => 20,
            'sync_interval' => 60,
            'inventory_interval' => 30,
            'order_sync_interval' => 15,
            'handle_missing' => 'disable',
            'location_mappings' => [],
            'search_begin_times' => [],
        ], $settings));
    }

    public static function bindSquare(): SquareDouble
    {
        $client = Mockery::mock(SquareClient::class);
        $client->shouldReceive('searchCatalogObjects')->andReturn(self::catalogResponse())->byDefault();
        $client->shouldReceive('batchRetrieveCatalogObjects')->andReturn(self::catalogResponse())->byDefault();
        $client->shouldReceive('batchUpsertCatalogObjects')->andReturnUsing(
            fn(array $batches): CatalogResponse => self::catalogUpsertResponse($batches),
        )->byDefault();
        $client->shouldReceive('batchChangeInventory')->andReturn(self::inventoryCounts())->byDefault();
        $client->shouldReceive('batchRetrieveInventoryCounts')->andReturn(self::inventoryCounts())->byDefault();
        $client->shouldReceive('createCatalogImage')->andReturn(null)->byDefault();
        $client->shouldReceive('createOrder')->andReturn(null)->byDefault();
        $client->shouldReceive('updateOrder')->andReturn(null)->byDefault();
        $client->shouldReceive('retrieveOrder')->andReturn(null)->byDefault();
        $client->shouldReceive('batchRetrieveOrders')->andReturn([])->byDefault();
        $client->shouldReceive('listLocations')->andReturn(collect())->byDefault();
        app()->instance(SquareClient::class, $client);

        return new SquareDouble($client);
    }

    public static function catalogResponse(array $objects = [], array $mappings = [], ?string $cursor = null, array $related = []): CatalogResponse
    {
        return new CatalogResponse(new CatalogResult($objects, $mappings, $cursor, $related));
    }

    public static function inventoryCounts(array $counts = [], ?string $cursor = null): object
    {
        return new readonly class($counts, $cursor)
        {
            public function __construct(private array $counts, private ?string $cursor) {}

            public function getCounts(): array
            {
                return $this->counts;
            }

            public function getCursor(): ?string
            {
                return $this->cursor;
            }
        };
    }

    public static function makeSyncTask(array $config = [], string $systemOfRecord = Settings::IGNITER_SOR): SyncTask
    {
        $task = new SyncTask;
        $task->uuid = (string)Str::uuid();
        $task->description = 'Pushing catalog into Square';
        $task->config_data = array_merge([
            'batchSize' => 20,
            'mode' => 'export',
            'syncInventory' => false,
            'createMissing' => false,
            'systemOfRecord' => $systemOfRecord,
        ], $config);
        $task->save();

        return $task;
    }

    public static function mapLocation(?Location $location = null, string $remoteId = 'LOC1'): Location
    {
        $location = $location ?: Location::factory()->create();
        $mappings = Settings::locationMapping()->all();
        $mappings[] = ['client_id' => $location->getKey(), 'remote_id' => $remoteId];
        Settings::set('location_mappings', $mappings);

        return $location;
    }

    public static function linkOwner($owner, array $attributes = []): SquareLink
    {
        $link = SquareLink::forOwner($owner);
        foreach ($attributes as $key => $value) {
            $link->{$key} = $value;
        }

        SquareLink::saveOwner($owner);

        return $link;
    }

    public static function squareMoney(int $amount = 250): Money
    {
        return new Money([
            'amount' => $amount,
            'currency' => 'GBP',
        ]);
    }

    public static function squareObject(string $type, string $id, array $values = []): CatalogObject
    {
        $values = array_merge(['id' => $id], $values);

        return match ($type) {
            'ITEM' => CatalogObject::item(new CatalogObjectItem($values)),
            'ITEM_VARIATION' => CatalogObject::itemVariation(new CatalogObjectItemVariation($values)),
            'CATEGORY' => CatalogObject::category(new CatalogObjectCategory($values)),
            'MODIFIER_LIST' => CatalogObject::modifierList(new CatalogObjectModifierList($values)),
            'MODIFIER' => CatalogObject::modifier(new CatalogObjectModifier($values)),
            'IMAGE' => CatalogObject::image(new CatalogObjectImage($values)),
            default => throw new InvalidArgumentException('Unknown catalog type '.$type),
        };
    }

    public static function squareRecord(CatalogObject $object): object
    {
        return $object->getValue();
    }

    public static function squareCatalogItem(string $id, string $name = 'Item', ?string $sku = 'SKU-1', string $pricing = 'FIXED_PRICING'): CatalogObject
    {
        $variationData = new CatalogItemVariation([
            'name' => $name,
            'pricingType' => $pricing,
            'sku' => $sku,
        ]);
        if ($pricing !== 'VARIABLE_PRICING') {
            $variationData->setPriceMoney(self::squareMoney());
        }

        return self::squareObject('ITEM', $id, [
            'version' => 3,
            'itemData' => new CatalogItem([
                'name' => $name,
                'description' => '<p>html</p>',
                'descriptionPlaintext' => 'plain',
                'variations' => [
                    self::squareObject('ITEM_VARIATION', $id.'-var', [
                        'version' => 2,
                        'itemVariationData' => $variationData,
                    ]),
                ],
            ]),
        ]);
    }

    public static function runPipe(string $pipe, array $config = [], string $type = Settings::IGNITER_SOR): SyncTask
    {
        $task = self::makeSyncTask($config, $type);
        (new $pipe($task))->handle();

        return $task;
    }

    public static function inventoryCount(string $objectId, string $locationId, string $quantity, string $state = 'IN_STOCK'): InventoryCount
    {
        $count = new InventoryCount;
        $count->setState($state);
        $count->setCatalogObjectId($objectId);
        $count->setLocationId($locationId);
        $count->setQuantity($quantity);

        return $count;
    }

    public static function remoteOrder(string $id, string $reference, string $state = 'PROPOSED', ?string $orderState = null): RemoteOrder
    {
        return new RemoteOrder([
            'id' => $id,
            'locationId' => 'LOC1',
            'referenceId' => $reference,
            'version' => 3,
            'state' => $orderState,
            'fulfillments' => [new Fulfillment([
                'uid' => 'FUL-'.$id,
                'type' => 'PICKUP',
                'state' => $state,
            ])],
        ]);
    }

    public static function makeMenu(array $attributes = []): Menu
    {
        return Menu::factory()->create(array_merge([
            'menu_name' => 'AMALA',
            'menu_description' => 'yam flour',
            'menu_price' => 4.5,
            'menu_status' => true,
        ], $attributes));
    }

    protected static function catalogUpsertResponse(array $batches): CatalogResponse
    {
        $objects = [];
        $mappings = [];

        foreach ($batches as $batch) {
            foreach ($batch->getObjects() as $object) {
                $record = CatalogRecord::unwrap($object);
                self::assignSquareRemoteId($object, $mappings);
                if (method_exists($record, 'getModifierListData')) {
                    foreach ($record->getModifierListData()?->getModifiers() ?? [] as $modifier) {
                        self::assignSquareRemoteId($modifier, $mappings);
                    }
                }

                if (method_exists($record, 'getItemData')) {
                    foreach ($record->getItemData()?->getVariations() ?? [] as $variation) {
                        self::assignSquareRemoteId($variation, $mappings);
                    }
                }

                $objects[] = $object;
            }
        }

        return self::catalogResponse($objects, $mappings);
    }

    protected static function assignSquareRemoteId(object $object, array &$mappings): void
    {
        $record = CatalogRecord::unwrap($object);
        $clientId = $record->getId();
        $remoteId = 'SQ'.substr(md5((string)$clientId), 0, 20);
        $record->setId($remoteId);
        $record->setVersion(11);

        if (!$clientId) {
            return;
        }

        $mappings[] = new CatalogIdMapping([
            'clientObjectId' => $clientId,
            'objectId' => $remoteId,
        ]);
    }
}

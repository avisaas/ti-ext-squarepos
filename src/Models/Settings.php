<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Models;

use Igniter\Admin\Models\Status;
use Igniter\Flame\Database\Model;
use Igniter\System\Actions\SettingsModel;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Exceptions\ClientException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Square\Types\Location;
use Throwable;

class Settings extends Model
{
    public const string IGNITER_SOR = 'igniter';

    public const string SQUARE_SOR = 'square';

    public const string ENDPOINT = 'https://tastyigniter.com/connect/';

    public array $implement = [SettingsModel::class];

    public string $settingsCode = 'igniterlabs_squarepos_settings';

    public string $settingsFieldsConfig = 'settings';

    public function getRemoteIdOptions()
    {
        $locations = [];
        if (static::isConnected()) {
            try {
                $locations = cache()->rememberForever('squarepos.locations', fn() => resolve(SquareClient::class)
                    ->listLocations()
                    ->mapWithKeys(fn(Location $location): array => [$location->getId() => $location->getName()])->all());
            } catch (ClientException $ex) {
                flash()->error($ex->getMessage())->now();
            }
        }

        return $locations;
    }

    public function getSourceOptions(): array
    {
        return [
            static::SQUARE_SOR => 'igniterlabs.squarepos::default.text_square',
            static::IGNITER_SOR => 'igniterlabs.squarepos::default.text_tastyigniter',
        ];
    }

    public function getEnvironmentOptions(): array
    {
        return [
            'production' => 'igniterlabs.squarepos::default.text_production',
            'sandbox' => 'igniterlabs.squarepos::default.text_sandbox',
        ];
    }

    public function getSyncIntervalOptions(): array
    {
        return $this->intervalOptions();
    }

    public function getInventoryIntervalOptions(): array
    {
        return $this->intervalOptions();
    }

    public function getOrderSyncIntervalOptions(): array
    {
        return $this->intervalOptions();
    }

    public function getHandleMissingOptions(): array
    {
        return [
            'disable' => 'igniterlabs.squarepos::default.text_disable_missing',
            'delete' => 'igniterlabs.squarepos::default.text_delete_missing',
        ];
    }

    public function getOrderCompletedStatusOptions()
    {
        return Status::getDropdownOptionsForOrder();
    }

    public function getOrderCanceledStatusOptions()
    {
        return $this->getOrderCompletedStatusOptions();
    }

    protected function intervalOptions(): array
    {
        return [
            15 => 'igniterlabs.squarepos::default.text_interval_15',
            30 => 'igniterlabs.squarepos::default.text_interval_30',
            60 => 'igniterlabs.squarepos::default.text_interval_60',
        ];
    }

    public static function getSystemOfRecord()
    {
        return self::get('source', static::SQUARE_SOR);
    }

    public static function locationMapping(): Collection
    {
        return collect(self::get('location_mappings', []));
    }

    public static function isSandboxEnvironment(): bool
    {
        return self::get('environment', 'production') === 'sandbox';
    }

    public static function isSyncEnabled(): bool
    {
        return static::isConnected() && self::get('sync_inventory');
    }

    public static function isCatalogSyncEnabled(): bool
    {
        return static::isConnected() && self::get('sync_catalog', true);
    }

    public static function syncOrders(): bool
    {
        return static::isConnected() && self::get('sync_orders', false);
    }

    public static function catalogInterval(): int
    {
        return self::normalizeInterval(self::get('sync_interval', 60));
    }

    public static function inventoryInterval(): int
    {
        return self::normalizeInterval(self::get('inventory_interval', 60));
    }

    public static function orderInterval(): int
    {
        return self::normalizeInterval(self::get('order_sync_interval', 15), [15, 30, 60]);
    }

    public static function safeInterval(string $which): string
    {
        try {
            $minutes = match ($which) {
                'inventory' => self::inventoryInterval(),
                'orders' => self::orderInterval(),
                default => self::catalogInterval(),
            };
        } catch (Throwable) {
            $minutes = $which === 'orders' ? 15 : 60;
        }

        if ($minutes >= 60) {
            return '0 * * * *';
        }

        return '*/'.$minutes.' * * * *';
    }

    public static function disablesMissingItems(): bool
    {
        return self::get('handle_missing', 'disable') !== 'delete';
    }

    public static function completedStatusId()
    {
        return self::get('order_completed_status');
    }

    public static function canceledStatusId()
    {
        return self::get('order_canceled_status');
    }

    public static function clientLocationId($remoteId)
    {
        $mapping = self::locationMapping()->first(fn($row): bool => (string)array_get($row, 'remote_id') === (string)$remoteId);

        return $mapping ? array_get($mapping, 'client_id') : null;
    }

    public static function remoteLocationId($clientId)
    {
        $mapping = self::locationMapping()->first(fn($row): bool => (string)array_get($row, 'client_id') === (string)$clientId);

        return $mapping ? array_get($mapping, 'remote_id') : null;
    }

    public static function getJobRetentionDays(): int
    {
        return (int)self::get('job_retention_days', 30);
    }

    protected static function normalizeInterval($minutes, array $allowed = [15, 30, 60]): int
    {
        $minutes = (int)$minutes;

        return in_array($minutes, $allowed, true) ? $minutes : 60;
    }

    public static function batchSize(): int
    {
        return (int)self::get('sync_batch_size', 300);
    }

    public static function searchBeginTime(string $type): ?string
    {
        $beginTime = array_get((array)self::get('search_begin_times', []), $type);

        return is_string($beginTime) && $beginTime !== '' ? $beginTime : null;
    }

    public static function rememberSearchBeginTime(string $type, string $beginTime): void
    {
        $times = (array)self::get('search_begin_times', []);
        $times[$type] = $beginTime;

        self::set('search_begin_times', $times);
    }

    //
    //
    //

    public static function isConnected(): bool
    {
        return (bool)self::get('connection_status', false);
    }

    public static function getAuthorizeUrl(array $params): string
    {
        $params['scope'] = implode(',', [
            'MERCHANT_PROFILE_READ',
            'PAYMENTS_READ', 'PAYMENTS_WRITE',
            'ORDERS_READ', 'ORDERS_WRITE',
            'CUSTOMERS_READ', 'CUSTOMERS_WRITE',
            'ITEMS_READ', 'ITEMS_WRITE',
            'INVENTORY_READ', 'INVENTORY_WRITE',
        ]);

        return static::ENDPOINT.'authorize/square?'.http_build_query($params);
    }

    public static function accessToken()
    {
        return self::connectionData('access_token');
    }

    public static function connectionData($key = null, $default = null)
    {
        return array_get((array)self::get('connection_data', []), $key, $default);
    }

    public static function storeConnectionData(array $input): void
    {
        $data = array_only($input, [
            'merchant_id',
            'token_type',
            'access_token',
            'refresh_token',
            'expires_at',
        ]);

        self::set([
            'environment' => 'production',
            'connection_data' => $data,
            'connection_status' => !empty($data['access_token']),
        ]);
    }

    public function save(?array $options = null)
    {
        if ((string)$this->getSettingsValue('environment') === 'sandbox' && array_key_exists('access_token', $this->getDirty())) {
            $token = trim((string)$this->getSettingsValue('access_token'));
            $connection = (array)$this->getSettingsValue('connection_data', []);
            $connection['access_token'] = $token;
            $this->connection_data = $connection;
            $this->connection_status = $token !== '';
        }

        return parent::save($options);
    }

    public function filterFields($form, array &$fields): void
    {
        if ($this->connection_status && isset($fields['connect_button'])) {
            unset($fields['connect_button']);
        }
    }

    public static function clearConnectionData(): void
    {
        self::set([
            'connection_data' => [],
            'connection_status' => false,
            'location_mappings' => [],
            'access_token' => null,
        ]);

        cache()->forget('squarepos.locations');
    }

    public static function exchangeAuthorizationCode(string $code): void
    {
        $response = Http::asJson()->post(static::ENDPOINT.'token/square', [
            'code' => $code,
        ]);

        $payload = $response->json();
        if (!is_array($payload) || empty($payload['access_token'])) {
            $message = is_array($payload) ? (string)array_get($payload, 'error_message') : '';

            throw new ClientException($message !== '' ? $message : 'Unable to connect your Square account.');
        }

        self::storeConnectionData($payload);
    }

    public static function ensureFreshToken(): void
    {
        $expiresAt = self::connectionData('expires_at');
        $refreshToken = self::connectionData('refresh_token');
        if (!$refreshToken || !$expiresAt || now()->addMinutes(5)->lt($expiresAt)) {
            return;
        }

        try {
            $response = Http::asJson()
                ->post(static::ENDPOINT.'token/square', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'merchant_id' => self::connectionData('merchant_id'),
                ]);

            if ($response->successful() && $response->json('access_token')) {
                self::storeConnectionData(array_merge(self::connectionData(), $response->json()));
            }
        } catch (Throwable) {
            // Keep the current token when refresh is unavailable.
        }
    }
}

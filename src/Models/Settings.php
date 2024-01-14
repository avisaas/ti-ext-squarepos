<?php

namespace IgniterLabs\SquarePOS\Models;

use Igniter\Flame\Database\Model;
use IgniterLabs\SquarePOS\Classes\SquareClient;
use IgniterLabs\SquarePOS\Exceptions\ClientException;
use Square\Models\Location;

class Settings extends Model
{
    public const IGNITER_SOR = 'igniter';

    public const SQUARE_SOR = 'square';

    public const ENDPOINT = 'https://tastyigniter.com/connect/';

    protected static $locationCache;

    public array $implement = [\Igniter\System\Actions\SettingsModel::class];

    public string $settingsCode = 'igniterlabs_squarepos_settings';

    public string $settingsFieldsConfig = 'settings';

    public function getRemoteIdOptions()
    {
        if (self::$locationCache) {
            return self::$locationCache;
        }

        $locations = [];
        if (!$this->isConnected()) {
            return $locations;
        }

        try {
            $locations = resolve(SquareClient::class)
                ->listLocations()
                ->mapWithKeys(function (Location $location) {
                    return [$location->getId() => $location->getName()];
                })->all();
        } catch (ClientException $ex) {
            flash()->error($ex->getMessage())->now();
        }

        return self::$locationCache = $locations;
    }

    public function getSourceOptions()
    {
        return [
            static::SQUARE_SOR => 'igniterlabs.squarepos::default.text_square',
            static::IGNITER_SOR => 'igniterlabs.squarepos::default.text_tastyigniter',
        ];
    }

    public static function getSystemOfRecord()
    {
        return self::get('source', static::SQUARE_SOR);
    }

    public static function locationMapping()
    {
        return collect(self::get('location_mappings', []));
    }

    public static function isSyncEnabled()
    {
        return (bool)self::get('sync_inventory');
    }

    public static function batchSize()
    {
        return (int)self::get('sync_batch_size', 300);
    }

    public static function getSearchBeginTimes()
    {
        return self::get('search_begin_times', []);
    }

    public static function setSearchBeginTimes($value)
    {
        self::set('search_begin_times', $value);
    }

    //
    //
    //

    public static function isConnected()
    {
        return array_key_exists('status', self::connectionData());
    }

    public static function getAuthorizeUrl($params)
    {
        $params['scopes'] = implode(',', [
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

    public static function storeConnectionData(array $input)
    {
        $data = array_only($input, [
            'merchant_id',
            'token_type',
            'access_token',
            'refresh_token',
            'expires_at',
        ]);

        $data['status'] = !empty($data['access_token']);

        self::set('connection_data', $data);
    }

    public static function clearConnectionData()
    {
        self::set('connection_data', []);
    }
}

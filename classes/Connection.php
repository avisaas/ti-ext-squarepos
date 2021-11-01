<?php

namespace IgniterLabs\SquarePOS\Classes;

use Carbon\Carbon;
use Igniter\Flame\Exception\ApplicationException;
use Igniter\Flame\Traits\Singleton;
use IgniterLabs\SquarePOS\Models\Settings;
use October\Rain\Network\Http;

class Connection
{
    use Singleton;

    public const TASTYIGNITER_SOR = 'igniter';

    public const SQUARE_SOR = 'square';

    public const ENDPOINT = 'https://connect.tastyigniter.com/';

    public function getAuthorizeUrl(array $params = [])
    {
        $params['scopes'] = implode(',', [
            'MERCHANT_PROFILE_READ',
            'PAYMENTS_READ', 'PAYMENTS_WRITE',
            'ORDERS_READ', 'ORDERS_WRITE',
            'CUSTOMERS_READ', 'CUSTOMERS_WRITE',
            'ITEMS_READ', 'ITEMS_WRITE',
            'INVENTORY_READ', 'INVENTORY_WRITE',
        ]);

        return self::ENDPOINT.'square/authorize?'.http_build_query($params);
    }

    public function getRevokeUrl(array $params = [])
    {
        $params['merchant_id'] = $this->getConfig('merchant_id');
        $params['access_token'] = $this->getConfig('access_token');

        return self::ENDPOINT.'square/revoke?'.http_build_query($params);
    }

    public function getAccessToken()
    {
        return $this->getConfig('access_token');
    }

    public function getActiveAccessToken()
    {
        $accessToken = $this->getAccessToken();

        if ($accessToken && $this->isTokenExpired()) {
            $accessToken = $this->refreshAccessToken();
        }

        return $accessToken;
    }

    public function handleAuthCallback(array $response)
    {
        $data['merchant_id'] = array_get($response, 'merchant_id');
        $data['token_type'] = array_get($response, 'token_type');
        $data['access_token'] = array_get($response, 'access_token');
        $data['refresh_token'] = array_get($response, 'refresh_token');
        $data['expires_at'] = array_get($response, 'expires_at');

        Settings::set('connection_data', $data);
    }

    public function getConfig($key, $default = null)
    {
        return array_get(Settings::get('connection_data', []), $key, $default);
    }

    protected function setConfig($key, $value)
    {
        $data = Settings::get('connection_data', []);

        array_set($data, $key, $value);

        Settings::set('connection_data', $data);
    }

    protected function isTokenExpired(): bool
    {
        $expiresAt = $this->getConfig('expires_at');

        return Carbon::parse($expiresAt)->isBefore(Carbon::now());
    }

    protected function refreshAccessToken()
    {
        $result = $this->requestAccessToken();

        $this->handleAuthCallback($result);

        return array_get($result, 'access_token');
    }

    protected function requestAccessToken(array $params = [])
    {
        $params['merchant_id'] = $this->getConfig('merchant_id');
        $params['refresh_token'] = $this->getConfig('refresh_token');

        $response = Http::post(self::ENDPOINT.'square/token', function ($http) use ($params) {
            $http->noRedirect();
            $http->timeout(30);
            $http->data($params);
        });

        if ($response->code != 200)
            throw new ApplicationException();

        return @json_decode($response->body, TRUE);
    }
}

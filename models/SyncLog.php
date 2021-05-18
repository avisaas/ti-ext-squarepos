<?php

namespace IgniterLabs\SquarePOS\Models;

use Exception;
use IgniterLabs\SquarePOS\Classes\SyncDefinition;
use Illuminate\Support\Str;

class SyncLog extends \Model
{
    public $table = 'igniterlabs_squarepos_logs';

    public $guarded = [];

    protected $casts = [
        'config_data' => 'array',
        'exception' => 'array',
    ];

    public function systemOfRecord()
    {
        return $this->getConfig('systemOfRecord', SyncDefinition::TASTYIGNITER_SOR);
    }

    public function batchSize()
    {
        return Settings::batchSize();
    }

    //
    //
    //

    public static function storeLog(string $jobClass, array $attributes)
    {
        return self::create([
            'uuid' => Str::uuid(),
            'job' => $jobClass,
            'config_data' => $attributes,
        ]);
    }

    public function getConfig($key, $default = null)
    {
        return array_get($this->config_data, $key, $default);
    }

    public function setConfig($key, $value)
    {
        $configData = $this->config_data;

        array_set($configData, $key, $value);

        $this->config_data = $configData;
    }

    public function forgetConfig($key)
    {
        $configData = $this->config_data;

        array_forget($configData, $key);

        $this->config_data = $configData;
    }

    public function saveException(Exception $exception)
    {
        $this->exception = [
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ];

        $this->save();

        return $this;
    }

    public function markAsSuccessful()
    {
        $this->is_success = 1;
        $this->save();

        return $this;
    }

    public function clearException()
    {
        $this->exception = null;

        $this->save();

        return $this;
    }
}

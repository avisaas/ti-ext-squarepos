<?php

namespace IgniterLabs\SquarePOS\Models;

use Exception;
use Igniter\Flame\Database\Model;
use IgniterLabs\SquarePOS\Classes\Connection;
use IgniterLabs\SquarePOS\Classes\SyncDefinition;
use Illuminate\Support\Str;

class SyncLog extends Model
{
    public $table = 'igniterlabs_squarepos_logs';

    public $guarded = [];

    public $timestamps = TRUE;

    protected $casts = [
        'config_data' => 'array',
        'exception' => 'array',
    ];

    protected $appends = ['action_name', 'status_name', 'created_since'];

    public function systemOfRecord()
    {
        return $this->getConfig('systemOfRecord', Connection::TASTYIGNITER_SOR);
    }

    public function batchSize()
    {
        return $this->getConfig('batchSize', Settings::batchSize());
    }

    public function isSystemOfRecordSquare()
    {
        return $this->systemOfRecord() === Connection::SQUARE_SOR;
    }

    //
    //
    //

    public function getStatusNameAttribute($value)
    {
        return lang($this->is_success
            ? 'igniterlabs.squarepos::default.text_success'
            : 'igniterlabs.squarepos::default.text_failed'
        );
    }

    public function getCreatedSinceAttribute($value)
    {
        return $this->created_at ? time_elapsed($this->created_at) : null;
    }

    //
    //
    //

    public static function storeLog(string $jobClass, array $attributes = [])
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

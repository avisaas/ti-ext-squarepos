<?php

namespace IgniterLabs\SquarePOS\Models;

use Igniter\Flame\Database\Model;
use IgniterLabs\SquarePOS\Exceptions\SyncException;
use Throwable;

class SyncTask extends Model
{
    public $table = 'igniterlabs_squarepos_tasks';

    public $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'config_data' => 'array',
        'notes' => 'array',
        'exception' => 'array',
        'is_success' => 'boolean',
    ];

    protected $appends = ['status_name', 'created_since'];

    protected $syncJob;

    public static function findBy(string $uuid)
    {
        if (!$model = self::where('uuid', $uuid)->first()) {
            throw new SyncException('No sync task found for: '.$uuid);
        }

        return $model;
    }

    public function getStatusNameAttribute($value)
    {
        return lang(
            $this->is_success
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

    public function isSystemOfRecordSquare()
    {
        return $this->type === Settings::SQUARE_SOR;
    }

    public function batchSize()
    {
        return array_get($this->config_data, 'batchSize', Settings::batchSize());
    }

    public function mappedLocationIds()
    {
        return collect(Settings::locationMapping())->pluck('remote_id', 'client_id')->all();
    }

    public function setSyncJob($syncJob)
    {
        $this->syncJob = $syncJob;

        return $this;
    }

    public function note(string $note)
    {
        if (!is_null($this->syncJob->logsOutput)) {
            $this->syncJob->logsOutput->writeln($note);
        }

        $this->attributes['notes'][] = $note;

        return $this;
    }

    public function noteUpdated($model, string $name)
    {
        return $this->note(sprintf(
            '%s (%s) updated to %s (%s)',
            $name,
            $model->getKey(),
            $model->square_version,
            $model->square_id
        ));
    }

    public function noteDeleted($model, string $name)
    {
    }

    public function getConfigData($key, $default = null)
    {
        return array_get($this->config_data, $key, $default);
    }

    public function setConfigData($key, $value)
    {
        $configData = $this->config_data;

        array_set($configData, $key, $value);

        $this->config_data = $configData;

        return $this;
    }

    public function forgetConfigData($key)
    {
        $configData = $this->config_data;

        array_forget($configData, $key);

        $this->config_data = $configData;
    }

    public function saveException(Throwable $exception)
    {
        $this->exception = [
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ];

        $this->save();

        return $this;
    }

    public function markAsComplete()
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

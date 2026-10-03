<?php

declare(strict_types=1);

namespace IgniterLabs\SquarePOS\Models;

use Igniter\Flame\Database\Model;
use Igniter\System\Models\Concerns\SendsMailTemplate;
use IgniterLabs\SquarePOS\Classes\CatalogRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @property string|null $description
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class SyncTask extends Model
{
    use Prunable;
    use SendsMailTemplate;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    public $table = 'squarepos_tasks';

    public $guarded = [];

    public $timestamps = true;

    protected $attributes = [
        'status' => self::STATUS_QUEUED,
    ];

    protected $casts = [
        'config_data' => 'array',
        'notes' => 'array',
        'exception' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $appends = ['status_name', 'created_since'];

    public function getStatusNameAttribute($value): string
    {
        $label = match ($this->status) {
            self::STATUS_RUNNING => 'igniterlabs.squarepos::default.text_running',
            self::STATUS_SUCCESS => 'igniterlabs.squarepos::default.text_success',
            self::STATUS_FAILURE => 'igniterlabs.squarepos::default.text_failure',
            default => 'igniterlabs.squarepos::default.text_queued',
        };

        return lang($label);
    }

    public function getCreatedSinceAttribute($value): ?string
    {
        return $this->created_at ? time_elapsed($this->created_at) : null;
    }

    //
    //
    //

    public function isSystemOfRecordSquare(): bool
    {
        return $this->getConfigData('systemOfRecord') === Settings::SQUARE_SOR;
    }

    public function batchSize()
    {
        return array_get($this->config_data, 'batchSize', Settings::batchSize());
    }

    public function mappedLocationIds()
    {
        return collect(Settings::locationMapping())->pluck('remote_id', 'client_id')->all();
    }

    public function note(string $note): static
    {
        $notes = $this->notes ?? [];
        $notes[] = $note;
        $this->notes = $notes;

        return $this;
    }

    public function noteUpdated($model, string $name): static
    {
        return $this->note(sprintf(
            '%s (%s) updated to %s (%s)',
            $name,
            $model->getKey(),
            $model->squareLink?->square_version,
            $model->squareLink?->square_id
        ));
    }

    public function noteDeleted(object $model, string $name): static
    {
        $record = CatalogRecord::unwrap($model);
        $id = method_exists($record, 'getId') ? $record->getId() : null;

        return $this->note(sprintf('%s (%s) deleted in Square', $name, $id ?: 'unknown'));
    }

    public function getExceptionMessageAttribute(): ?string
    {
        return array_get($this->exception, 'message');
    }

    public function sendSummary(): void
    {
        if ($this->status !== self::STATUS_FAILURE || !Settings::get('sync_notify_email')) {
            return;
        }

        $this->mailSend('igniterlabs.squarepos::mail.sync_completed', 'notify');
    }

    public function mailGetRecipients($type): array
    {
        $email = Settings::get('sync_notify_email');
        if (!$email) {
            return [];
        }

        return [[$email, $email]];
    }

    public function mailGetData(): array
    {
        return [
            'sync_status' => match ($this->status) {
                self::STATUS_RUNNING => 'Running',
                self::STATUS_SUCCESS => 'Success',
                self::STATUS_FAILURE => 'Failure',
                default => 'Queued',
            },
            'sync_description' => (string)$this->description,
            'sync_notes' => implode("\n", $this->notes ?? []),
            'sync_exception' => (string)array_get($this->exception, 'message'),
        ];
    }

    public function getConfigData($key, $default = null)
    {
        return array_get($this->config_data, $key, $default);
    }

    public function setConfigData($key, $value): static
    {
        $configData = $this->config_data;

        array_set($configData, $key, $value);

        $this->config_data = $configData;

        return $this;
    }

    public function forgetConfigData($key): void
    {
        $configData = $this->config_data;

        array_forget($configData, $key);

        $this->config_data = $configData;
    }

    public function markAsFailure(Throwable $exception): static
    {
        $this->exception = [
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ];
        $this->status = self::STATUS_FAILURE;

        $this->save();

        return $this;
    }

    public function markAsQueued(): static
    {
        $this->status = self::STATUS_QUEUED;
        $this->save();

        return $this;
    }

    public function markAsRunning(): static
    {
        $this->started_at = now();
        $this->status = self::STATUS_RUNNING;
        $this->save();

        return $this;
    }

    public function markAsComplete(): static
    {
        $this->finished_at = now();
        $this->status = self::STATUS_SUCCESS;
        $this->save();

        return $this;
    }

    public function clearException(): static
    {
        $this->exception = null;

        $this->save();

        return $this;
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', Settings::getJobRetentionDays());
    }
}

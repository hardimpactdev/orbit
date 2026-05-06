<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_id
 * @property string $name
 * @property string $command
 * @property ProcessRestartPolicy $restart_policy
 * @property ProcessCrashNotification $crash_notification
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read App $app
 * @property-read Collection<int, ProcessEvent> $events
 */
class Process extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_id',
        'name',
        'command',
        'restart_policy',
        'crash_notification',
        'sort_order',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'restart_policy' => ProcessRestartPolicy::class,
            'crash_notification' => ProcessCrashNotification::class,
        ];
    }

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * @return HasMany<ProcessEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ProcessEvent::class)->latest('recorded_at');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 */
class Process extends Model
{
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
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceLifecyclePhase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_id
 * @property int $app_instance_id
 * @property WorkspaceLifecyclePhase $phase
 * @property int $sort_order
 * @property string $command
 * @property int $timeout_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read App|null $app
 * @property-read AppInstance $appInstance
 *
 * @method static WorkspaceStep|null find(int $id)
 */
class WorkspaceStep extends Model
{
    use HasFactory;

    public const int DEFAULT_TIMEOUT_SECONDS = 600;

    #[\Override]
    protected $fillable = [
        'app_id',
        'app_instance_id',
        'phase',
        'sort_order',
        'command',
        'timeout_seconds',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'phase' => WorkspaceLifecyclePhase::class,
            'timeout_seconds' => 'integer',
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
     * @return BelongsTo<AppInstance, $this>
     */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class);
    }

    public function timeoutSeconds(): int
    {
        return $this->timeout_seconds;
    }
}

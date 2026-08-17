<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceLifecyclePhase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $instance_id
 * @property WorkspaceLifecyclePhase $phase
 * @property int $sort_order
 * @property string $command
 * @property int $timeout_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read App|null $app
 * @property-read Instance $instance
 *
 * @method static WorkspaceStep|null find(int $id)
 */
class WorkspaceStep extends Model
{
    use HasFactory;

    public const int DEFAULT_TIMEOUT_SECONDS = 600;

    #[Override]
    protected $fillable = [
        'instance_id',
        'phase',
        'sort_order',
        'command',
        'timeout_seconds',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'phase' => WorkspaceLifecyclePhase::class,
            'timeout_seconds' => 'integer',
        ];
    }

    /**
     * @return HasOneThrough<App, Instance, $this>
     */
    public function app(): HasOneThrough
    {
        return $this->hasOneThrough(
            App::class,
            Instance::class,
            'id',
            'id',
            'instance_id',
            'app_id',
        );
    }

    /**
     * @return BelongsTo<Instance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public function timeoutSeconds(): int
    {
        return $this->timeout_seconds;
    }
}

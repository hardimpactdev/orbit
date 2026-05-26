<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceLifecyclePhase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @property int $id
 * @property int $app_id
 * @property WorkspaceLifecyclePhase $phase
 * @property int $sort_order
 * @property string $command
 * @property int $timeout_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read App|null $app
 */
class WorkspaceStep extends Model
{
    use HasFactory;

    public const int DEFAULT_TIMEOUT_SECONDS = 600;

    #[\Override]
    protected $fillable = [
        'app_id',
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

    public function timeoutSeconds(): int
    {
        return $this->timeout_seconds;
    }

    public static function createOrdered(
        int $appId,
        WorkspaceLifecyclePhase $phase,
        string $command,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?int $beforeStepId = null,
        ?int $afterStepId = null,
    ): self {
        return DB::transaction(function () use ($appId, $phase, $command, $timeoutSeconds, $beforeStepId, $afterStepId): self {
            $phaseSteps = self::query()
                ->where('app_id', $appId)
                ->where('phase', $phase);

            if ($beforeStepId !== null) {
                $anchor = (clone $phaseSteps)->find($beforeStepId);

                if (! $anchor instanceof self) {
                    throw new InvalidArgumentException("Step #{$beforeStepId} was not found.");
                }

                $sortOrder = $anchor->sort_order;
                $phaseSteps->where('sort_order', '>=', $sortOrder)->increment('sort_order');
            } elseif ($afterStepId !== null) {
                $anchor = (clone $phaseSteps)->find($afterStepId);

                if (! $anchor instanceof self) {
                    throw new InvalidArgumentException("Step #{$afterStepId} was not found.");
                }

                $sortOrder = $anchor->sort_order + 1;
                $phaseSteps->where('sort_order', '>=', $sortOrder)->increment('sort_order');
            } else {
                $sortOrder = ((clone $phaseSteps)->max('sort_order') ?? 0) + 1;
            }

            return self::query()->create([
                'app_id' => $appId,
                'phase' => $phase,
                'sort_order' => $sortOrder,
                'command' => $command,
                'timeout_seconds' => $timeoutSeconds,
            ]);
        });
    }

    public function deleteAndCompact(): void
    {
        DB::transaction(function (): void {
            $sortOrder = $this->sort_order;
            $appId = $this->app_id;
            $phase = $this->phase;

            $this->delete();

            self::query()
                ->where('app_id', $appId)
                ->where('phase', $phase)
                ->where('sort_order', '>', $sortOrder)
                ->decrement('sort_order');
        });
    }
}

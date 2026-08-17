<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Schedules\ScheduleScope;
use App\Exceptions\ScheduleOwnerInvariantViolation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property string $schedule_key
 * @property string $name
 * @property string $scope
 * @property int|null $instance_id
 * @property int|null $node_id
 * @property string $target_name
 * @property string $interval
 * @property string $timezone
 * @property string $execution_type
 * @property string $execution_value
 * @property int $timeout_seconds
 * @property bool $enabled
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read App|null $app
 * @property-read Instance|null $instance
 * @property-read Node|null $node
 * @property-read ScheduleRun|null $latestRun
 * @property-read Collection<int, ScheduleRun> $runs
 *
 * @mago-expect lint:too-many-methods
 */
class Schedule extends Model
{
    use HasFactory;

    #[Override]
    protected static function booted(): void
    {
        static::saving(static function (Schedule $schedule): void {
            $schedule->assertOwnerInvariant();
            $schedule->target_name = $schedule->liveTargetName();
        });
    }

    #[Override]
    protected $fillable = [
        'schedule_key',
        'name',
        'scope',
        'instance_id',
        'node_id',
        'target_name',
        'interval',
        'timezone',
        'execution_type',
        'execution_value',
        'timeout_seconds',
        'enabled',
        'status',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'timeout_seconds' => 'integer',
        ];
    }

    public function ownerScope(): ScheduleScope
    {
        $scope = ScheduleScope::tryFrom($this->scope);

        if (! $scope instanceof ScheduleScope) {
            throw new ScheduleOwnerInvariantViolation(
                "Schedule '{$this->name}' has an unknown owner scope '{$this->scope}'.",
            );
        }

        return $scope;
    }

    public function isInstanceOwned(): bool
    {
        return ScheduleScope::tryFrom($this->scope) === ScheduleScope::Instance;
    }

    public function liveTargetName(): string
    {
        return match ($this->ownerScope()) {
            ScheduleScope::Orbit => 'gateway',
            ScheduleScope::Node => $this->ownedNodeName(),
            ScheduleScope::Instance => $this->ownedInstanceTargetName(),
        };
    }

    public function assertOwnerInvariant(): void
    {
        $scope = ScheduleScope::tryFrom($this->scope);

        if (! $scope instanceof ScheduleScope) {
            throw new ScheduleOwnerInvariantViolation(
                "Schedule '{$this->name}' owner must be orbit, node, or instance.",
            );
        }

        $instanceId = $this->instance_id;
        $nodeId = $this->node_id;
        $valid = match ($scope) {
            ScheduleScope::Orbit => $instanceId === null && $nodeId === null,
            ScheduleScope::Node => $nodeId !== null && $instanceId === null,
            ScheduleScope::Instance => $instanceId !== null && $nodeId === null,
        };

        if ($valid) {
            return;
        }

        throw new ScheduleOwnerInvariantViolation(
            "Schedule '{$this->name}' owner is {$scope->value} but the persisted foreign keys are contradictory.",
        );
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

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return HasMany<ScheduleRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ScheduleRun::class, 'schedule_key', 'schedule_key')
            ->latest('started_at');
    }

    /**
     * @return HasOne<ScheduleRun, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(ScheduleRun::class, 'schedule_key', 'schedule_key')
            ->latestOfMany('started_at');
    }

    private function ownedNodeName(): string
    {
        $this->loadMissing('node');
        $name = $this->node?->name;

        if (! is_string($name) || $name === '') {
            throw new ScheduleOwnerInvariantViolation(
                "Schedule '{$this->name}' is node-owned but has no node target.",
            );
        }

        return $name;
    }

    private function ownedInstanceTargetName(): string
    {
        $this->loadMissing(['instance.app']);
        $instance = $this->instance;
        $app = $instance?->app;

        if (! $instance instanceof Instance || ! $app instanceof App) {
            throw new ScheduleOwnerInvariantViolation(
                "Schedule '{$this->name}' is instance-owned but has no instance target.",
            );
        }

        return "{$app->name}.{$instance->name}";
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property int $id
 * @property int $node_id
 * @property string $owner_type
 * @property int $owner_id
 * @property int|null $app_instance_id
 * @property string $name
 * @property string $command
 * @property ProcessRestartPolicy $restart_policy
 * @property ProcessCrashNotification $crash_notification
 * @property ProcessRuntime $runtime
 * @property string|null $tool
 * @property array<string, mixed> $runtime_config
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $owner
 * @property-read App|null $app
 * @property-read AppInstance|null $appInstance
 * @property-read Node|null $node
 * @property-read Collection<int, ProcessEvent> $events
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
class Process extends Model
{
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        static::saving(function (Process $process): void {
            $nodeId = $process->nodeIdForOwner();

            if ($nodeId === null) {
                return;
            }

            if ($process->node_id !== null && $process->node_id !== $nodeId) {
                throw new InvalidArgumentException(
                    "Process '{$process->name}' node does not match its canonical owner placement.",
                );
            }

            $process->node_id = $nodeId;
        });
    }

    #[\Override]
    protected $fillable = [
        'node_id',
        'owner_type',
        'owner_id',
        'app_instance_id',
        'name',
        'command',
        'restart_policy',
        'crash_notification',
        'runtime',
        'tool',
        'runtime_config',
        'sort_order',
    ];

    #[\Override]
    protected $attributes = [
        'runtime' => 'systemd',
        'runtime_config' => '[]',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'restart_policy' => ProcessRestartPolicy::class,
            'crash_notification' => ProcessCrashNotification::class,
            'runtime' => ProcessRuntime::class,
            'runtime_config' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return BelongsTo<AppInstance, $this>
     */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class);
    }

    /**
     * @return Builder<$this>
     */
    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey());
    }

    /**
     * @param  string|list<string>  $services
     * @return Builder<$this>
     */
    public function scopeWithRuntimeService(Builder $query, string|array $services): Builder
    {
        $services = is_array($services) ? array_values($services) : [$services];

        return $query->whereIn('runtime_config->service', $services);
    }

    public function ownerApp(): ?App
    {
        $this->loadMissing('owner');

        if ($this->owner instanceof App) {
            return $this->owner;
        }

        if ($this->owner instanceof Workspace) {
            $this->owner->loadMissing('app');

            return $this->owner->app;
        }

        return null;
    }

    public function getAppAttribute(): ?App
    {
        return $this->ownerApp();
    }

    private function nodeIdForOwner(): ?int
    {
        if ($this->owner_type === '' || $this->owner_id === null) {
            return null;
        }

        $ownerClass = Relation::getMorphedModel($this->owner_type) ?? $this->owner_type;

        if ($ownerClass === Node::class) {
            return (int) $this->owner_id;
        }

        if ($ownerClass === App::class) {
            return $this->appInstanceNodeIdForApp((int) $this->owner_id);
        }

        if ($ownerClass === Workspace::class) {
            $workspace = Workspace::query()->find($this->owner_id);

            if (! $workspace instanceof Workspace) {
                throw new InvalidArgumentException('Process workspace owner does not exist.');
            }

            if ($this->app_instance_id !== null && $workspace->app_instance_id !== $this->app_instance_id) {
                throw new InvalidArgumentException(
                    "Process '{$this->name}' app instance does not match its workspace owner.",
                );
            }

            return $this->appInstanceNodeIdForApp($workspace->app_id);
        }

        if ($ownerClass === NodeRoleAssignment::class) {
            $role = NodeRoleAssignment::query()->find($this->owner_id);

            return $role instanceof NodeRoleAssignment ? $role->node_id : null;
        }

        return null;
    }

    private function appInstanceNodeIdForApp(int $appId): ?int
    {
        if ($this->app_instance_id === null) {
            throw new InvalidArgumentException(
                "Process '{$this->name}' requires concrete app instance ownership.",
            );
        }

        $instance = AppInstance::query()->find($this->app_instance_id);

        if (! $instance instanceof AppInstance || $instance->app_id !== $appId) {
            throw new InvalidArgumentException(
                "Process '{$this->name}' app instance does not belong to its app owner.",
            );
        }

        $node = app(WorkspacePlacement::class)->nodeForInstance($instance);

        if (! $node instanceof Node) {
            throw new InvalidArgumentException(
                "Process '{$this->name}' app instance has no concrete serving node.",
            );
        }

        return $node->id;
    }

    /**
     * @return HasMany<ProcessEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ProcessEvent::class)->latest('recorded_at');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Apps\AppRuntimeConfig;
use App\Enums\Apps\AppRuntimeKind;
use Database\Factories\AppFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Override;

/**
 * @property string $name
 * @property int $id
 * @property int $node_id
 * @property string $environment
 * @property string|null $domain
 * @property string $path
 * @property string $document_root
 * @property string|null $repository
 * @property string $php_version
 * @property AppRuntimeKind $runtime
 * @property array<string, mixed>|null $runtime_config
 * @property bool $adopted
 * @property-read Node|null $node
 * @property-read Collection<int, Instance> $instances
 * @property-read Collection<int, Process> $processes
 * @property-read Collection<int, Schedule> $schedules
 * @property-read Collection<int, AppDependencyAuditSummary> $dependencyAuditSummaries
 * @property-read Collection<int, Workspace> $workspaces
 */
class App extends Model
{
    /** @use HasFactory<AppFactory> */
    use HasFactory;

    #[Override]
    protected $table = 'apps';

    #[Override]
    protected $fillable = [
        'name',
        'node_id',
        'environment',
        'domain',
        'path',
        'document_root',
        'repository',
        'php_version',
        'runtime',
        'runtime_config',
        'adopted',
    ];

    #[Override]
    protected $attributes = [
        'runtime' => 'php',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'adopted' => 'boolean',
            'runtime' => AppRuntimeKind::class,
            'runtime_config' => 'array',
        ];
    }

    public function runtimeConfig(): AppRuntimeConfig
    {
        return AppRuntimeConfig::fromArray(is_array($this->runtime_config) ? $this->runtime_config : null);
    }

    public function runtimeKind(): AppRuntimeKind
    {
        $runtime = $this->getRawOriginal('runtime') ?? $this->attributes['runtime'] ?? null;

        if ($runtime instanceof AppRuntimeKind) {
            return $runtime;
        }

        if (is_string($runtime)) {
            return AppRuntimeKind::tryFrom($runtime) ?? AppRuntimeKind::Php;
        }

        return AppRuntimeKind::Php;
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return HasMany<Instance, $this>
     */
    public function instances(): HasMany
    {
        $instances = $this->hasMany(Instance::class, 'app_id');
        $instances->orderBy('name');

        return $instances;
    }

    /**
     * @return MorphMany<Process, $this>
     */
    public function processes(): MorphMany
    {
        $processes = $this->morphMany(Process::class, 'owner');
        $processes->orderBy('sort_order');

        return $processes;
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        $schedules = $this->hasMany(Schedule::class, 'app_id');
        $schedules->orderBy('name');

        return $schedules;
    }

    /**
     * @return HasMany<Workspace, $this>
     */
    public function workspaces(): HasMany
    {
        $workspaces = $this->hasMany(Workspace::class, 'app_id');
        $workspaces->orderBy('name');

        return $workspaces;
    }

    /**
     * @return HasMany<AppDependencyAuditSummary, $this>
     */
    public function dependencyAuditSummaries(): HasMany
    {
        return $this->hasMany(AppDependencyAuditSummary::class, 'app_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Apps\AppRuntimeConfig;
use App\Enums\Apps\AppRuntimeKind;
use Database\Factories\AppFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Override;

/**
 * The App is the logical project identity. All placement and adoption
 * (node, environment, domain, source path, document root, adopted) lives on the
 * app's concrete Instances; App owns none of it.
 *
 * @property string $name
 * @property int $id
 * @property string|null $repository
 * @property string $php_version
 * @property AppRuntimeKind $runtime
 * @property array<string, mixed>|null $runtime_config
 * @property-read Collection<int, Instance> $instances
 * @property-read Collection<int, AppDevelopmentSetupStep> $developmentSetupSteps
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
        'repository',
        'php_version',
        'runtime',
        'runtime_config',
    ];

    #[Override]
    protected $attributes = [
        'runtime' => 'php',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
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
     * @return HasMany<Instance, $this>
     */
    public function instances(): HasMany
    {
        $instances = $this->hasMany(Instance::class, 'app_id');
        $instances->orderBy('name');

        return $instances;
    }

    /** @return HasMany<AppDevelopmentSetupStep, $this> */
    public function developmentSetupSteps(): HasMany
    {
        $steps = $this->hasMany(AppDevelopmentSetupStep::class);
        $steps->orderBy('sort_order')->orderBy('id');

        return $steps;
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
     * @return HasManyThrough<Schedule, Instance, $this>
     */
    public function schedules(): HasManyThrough
    {
        $schedules = $this->hasManyThrough(Schedule::class, Instance::class);
        $schedules->orderBy('schedules.name');

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

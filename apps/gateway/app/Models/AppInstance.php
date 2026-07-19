<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Apps\AppInstanceDriverConfigData;
use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use App\Data\Apps\PhpWorkerConfig;
use App\Enums\Apps\AppInstanceDriver;
use Database\Factories\AppInstanceFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $app_id
 * @property string $name
 * @property AppInstanceDriver $driver
 * @property AppInstanceDriverConfigData|null $driver_config
 * @property AppInstanceRuntimeRequirementsData|null $runtime_requirements
 * @property list<string>|null $deploy_warmup_paths
 * @property bool $worker_enabled
 * @property array<string, mixed>|null $worker_config
 * @property string|null $latest_deployment_status
 * @property int|null $latest_deployment_run_id
 * @property-read App $app
 * @property-read Collection<int, AppInstanceRuntimeMount> $runtimeMounts
 * @property-read Collection<int, DatabaseConnection> $databaseConnections
 * @property-read Collection<int, DeployStep> $deploySteps
 * @property-read Collection<int, DeploymentRun> $deploymentRuns
 * @property-read Collection<int, AppSetupRun> $setupRuns
 * @property-read Collection<int, AppSetupStep> $setupSteps
 * @property-read Collection<int, Schedule> $schedules
 *
 * @mago-expect lint:too-many-methods
 */
class AppInstance extends Model
{
    /** @use HasFactory<AppInstanceFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'app_id',
        'name',
        'driver',
        'driver_config',
        'runtime_requirements',
        'deploy_warmup_paths',
        'worker_enabled',
        'worker_config',
        'latest_deployment_status',
        'latest_deployment_run_id',
    ];

    #[\Override]
    protected $attributes = [
        'driver' => 'orbit',
        'worker_enabled' => false,
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'driver' => AppInstanceDriver::class,
            'driver_config' => AppInstanceDriverConfigData::class,
            'runtime_requirements' => AppInstanceRuntimeRequirementsData::class,
            'deploy_warmup_paths' => 'array',
            'worker_enabled' => 'boolean',
            'worker_config' => 'array',
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
     * @return HasMany<AppInstanceEnvVariable, $this>
     */
    public function envVariables(): HasMany
    {
        return $this->hasMany(AppInstanceEnvVariable::class)->orderBy('key');
    }

    /**
     * @return HasMany<DatabaseConnectionTarget, $this>
     */
    public function databaseConnectionTargets(): HasMany
    {
        return $this->hasMany(DatabaseConnectionTarget::class);
    }

    /**
     * @return BelongsToMany<DatabaseConnection, $this>
     */
    public function databaseConnections(): BelongsToMany
    {
        return $this
            ->belongsToMany(
                related: DatabaseConnection::class,
                table: 'database_connection_targets',
                foreignPivotKey: 'app_instance_id',
                relatedPivotKey: 'database_connection_id',
            )
            ->withPivot('env_prefix')
            ->withTimestamps();
    }

    /**
     * @return HasMany<AppInstanceRuntimeMount, $this>
     */
    public function runtimeMounts(): HasMany
    {
        $relation = $this->hasMany(AppInstanceRuntimeMount::class);
        $relation->getQuery()->orderBy('target');

        return $relation;
    }

    /**
     * @return HasMany<DeployStep, $this>
     */
    public function deploySteps(): HasMany
    {
        $relation = $this->hasMany(DeployStep::class);
        $relation->getQuery()->orderBy('sort_order');

        return $relation;
    }

    /**
     * @return HasMany<DeploymentRun, $this>
     */
    public function deploymentRuns(): HasMany
    {
        $relation = $this->hasMany(DeploymentRun::class);
        $relation->getQuery()->orderByDesc('started_at');

        return $relation;
    }

    /**
     * @return HasMany<AppSetupStep, $this>
     */
    public function setupSteps(): HasMany
    {
        $relation = $this->hasMany(AppSetupStep::class);
        $relation->getQuery()->orderBy('sort_order');

        return $relation;
    }

    /**
     * @return HasMany<AppSetupRun, $this>
     */
    public function setupRuns(): HasMany
    {
        $relation = $this->hasMany(AppSetupRun::class);
        $relation->getQuery()->orderByDesc('started_at');

        return $relation;
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        $relation = $this->hasMany(Schedule::class);
        $relation->getQuery()->orderBy('name');

        return $relation;
    }

    public function runtimeRequirements(): AppInstanceRuntimeRequirementsData
    {
        return $this->runtime_requirements instanceof AppInstanceRuntimeRequirementsData
            ? $this->runtime_requirements
            : new AppInstanceRuntimeRequirementsData;
    }

    public function workerConfig(): PhpWorkerConfig
    {
        return PhpWorkerConfig::fromArray(is_array($this->worker_config) ? $this->worker_config : []);
    }
}

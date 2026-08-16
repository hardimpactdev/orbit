<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Apps\InstanceDriverConfigData;
use App\Data\Apps\InstanceRuntimeRequirementsData;
use App\Data\Apps\PhpWorkerConfig;
use App\Enums\Apps\InstanceDriver;
use Database\Factories\InstanceFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Override;

/**
 * @property int $id
 * @property int $app_id
 * @property string $name
 * @property string|null $php_version
 * @property InstanceDriver $driver
 * @property InstanceDriverConfigData|null $driver_config
 * @property bool $adopted
 * @property InstanceRuntimeRequirementsData|null $runtime_requirements
 * @property list<string>|null $deploy_warmup_paths
 * @property bool $worker_enabled
 * @property array<string, mixed>|null $worker_config
 * @property-read App $app
 * @property-read Collection<int, InstanceEnvVariable> $envVariables
 * @property-read Collection<int, InstanceRuntimeMount> $runtimeMounts
 * @property-read Collection<int, DatabaseConnection> $databaseConnections
 * @property-read Collection<int, DeployStep> $deploySteps
 * @property-read Collection<int, DeploymentRun> $deploymentRuns
 * @property-read DeploymentRun|null $latestDeploymentRun
 * @property-read Collection<int, AppSetupRun> $setupRuns
 * @property-read Collection<int, AppSetupStep> $setupSteps
 * @property-read Collection<int, Schedule> $schedules
 * @property-read AppAnalyticsBinding|null $analyticsBinding
 * @property-read AppWebSocketBinding|null $webSocketBinding
 * @property-read Collection<int, ProxyRoute> $proxyRoutes
 *
 * @mago-expect lint:too-many-methods
 */
class Instance extends Model
{
    #[Override]
    protected $table = 'instances';

    /** @use HasFactory<InstanceFactory> */
    use HasFactory;

    #[Override]
    protected $fillable = [
        'app_id',
        'name',
        'php_version',
        'driver',
        'driver_config',
        'adopted',
        'runtime_requirements',
        'deploy_warmup_paths',
        'worker_enabled',
        'worker_config',
    ];

    #[Override]
    protected $attributes = [
        'driver' => 'orbit',
        'worker_enabled' => false,
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'driver' => InstanceDriver::class,
            'driver_config' => InstanceDriverConfigData::class,
            'adopted' => 'boolean',
            'runtime_requirements' => InstanceRuntimeRequirementsData::class,
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
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * @return HasMany<InstanceEnvVariable, $this>
     */
    public function envVariables(): HasMany
    {
        return $this->hasMany(InstanceEnvVariable::class);
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
                foreignPivotKey: 'instance_id',
                relatedPivotKey: 'database_connection_id',
            )
            ->withPivot('env_prefix')
            ->withTimestamps();
    }

    /**
     * @return HasMany<InstanceRuntimeMount, $this>
     */
    public function runtimeMounts(): HasMany
    {
        $relation = $this->hasMany(InstanceRuntimeMount::class);
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
     * @return HasOne<DeploymentRun, $this>
     */
    public function latestDeploymentRun(): HasOne
    {
        return $this->hasOne(DeploymentRun::class)->latestOfMany();
    }

    /**
     * @return HasMany<ProxyRoute, $this>
     */
    public function proxyRoutes(): HasMany
    {
        return $this->hasMany(ProxyRoute::class);
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

    /**
     * @return HasOne<AppWebSocketBinding, $this>
     */
    public function webSocketBinding(): HasOne
    {
        return $this->hasOne(AppWebSocketBinding::class);
    }

    /**
     * @return HasOne<AppAnalyticsBinding, $this>
     */
    public function analyticsBinding(): HasOne
    {
        return $this->hasOne(AppAnalyticsBinding::class);
    }

    public function runtimeRequirements(): InstanceRuntimeRequirementsData
    {
        return $this->runtime_requirements instanceof InstanceRuntimeRequirementsData
            ? $this->runtime_requirements
            : new InstanceRuntimeRequirementsData;
    }

    public function workerConfig(): PhpWorkerConfig
    {
        return PhpWorkerConfig::fromArray(is_array($this->worker_config) ? $this->worker_config : []);
    }
}

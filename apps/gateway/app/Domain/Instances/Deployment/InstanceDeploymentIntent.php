<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Instances\InstanceDeploymentConfigResolver;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Processes\ProcessSwampSafety;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitCommitSha;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use InvalidArgumentException;

/**
 * Freezes deployment intent without storing scripts, environment contents or credentials.
 *
 * @phpstan-type IntentStep array{name:string,phase:string,required:bool,timeout_seconds:int,command_digest:string}
 * @phpstan-type Intent array{variant:string,runtime_synchronous:bool,php_profile:?array<string,mixed>,projection_route_id:?int,php:bool,needs_vp:bool,php_version:?string,php_runtime:?array<string,mixed>,php_refresh:?array<string,mixed>,project_id:int,branch:string,repository:string,home:string,user:?string,relative_root:?string,application_suffix:?string,served:list<array{web_root:string,directory:string}>,shared_paths:list<string>,steps:list<IntentStep>}
 */
final readonly class InstanceDeploymentIntent
{
    public function __construct(
        private InstanceDeploymentConfigResolver $production,
        private ProjectDevelopmentDeployStepStore $development,
        private ProductionPhpRuntimeConfigRenderer $php,
        private ServiceMetricsProjection $metrics,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(Instance $instance, bool $rollback = false): array
    {
        $instance->refresh()->loadMissing(['project', 'node']);
        if ($instance->placedOnAppDev() && ! $rollback) {
            $this->assertDevelopment($instance);
            $development = $this->development->ordered($instance->project);
            $steps = array_map(static fn (DevelopmentDeployStep $step): array => [
                'name' => $step->name, 'phase' => 'before_activation', 'required' => $step->required,
                'timeout_seconds' => $step->timeoutSeconds, 'command_digest' => hash('sha256', $step->command),
            ], $development);

            return [
                'variant' => 'development', 'runtime_synchronous' => false, 'php_profile' => null, 'projection_route_id' => $instance->development_projection_pending ? $instance->authoritativeRoute()?->id : null, 'php' => false, 'steps' => $steps,
                'php_version' => null, 'php_runtime' => null, 'php_refresh' => null,
                'user' => null, 'relative_root' => null, 'application_suffix' => null, 'served' => [], 'shared_paths' => [],
                'needs_vp' => array_any($development, static fn (DevelopmentDeployStep $step): bool => InstanceVpEnvironmentMaterial::required($step->name, $step->command)),
                'project_id' => $instance->project_id, 'branch' => $instance->project->default_branch,
                'repository' => $instance->project->repository_url, 'home' => $instance->checkout_path,
            ];
        }
        $config = $this->production->resolve($instance);
        [$runtime, $refresh] = $this->runtimeIntent($instance);

        return [
            'runtime_synchronous' => app(ProcessSwampSafety::class)->requiresSynchronous($instance->node),
            'php_profile' => is_string($instance->selected_php_version) ? app(RemotePhpPackageManager::class)->runtimeInput($instance->node, $instance->selected_php_version) : null,
            'variant' => $rollback ? 'rollback' : 'production', 'projection_route_id' => null,
            'needs_vp' => ! $rollback && array_any($config->steps, static fn (DeploymentStep $step): bool => InstanceVpEnvironmentMaterial::required($step->name, $step->command)),
            'php' => is_string($instance->selected_php_version), 'php_version' => $instance->selected_php_version,
            'php_runtime' => $runtime, 'php_refresh' => $refresh,
            'project_id' => $instance->project_id, 'branch' => $config->branch,
            'repository' => $instance->project->repository_url,
            'home' => $instance->production_home, 'user' => $instance->production_user,
            'relative_root' => $instance->root ?? $instance->project->root,
            'application_suffix' => ProductionApplicationPaths::suffix($instance->root ?? $instance->project->root),
            'served' => array_map(static fn (array $application): array => [
                'web_root' => $application['web_root'], 'directory' => $application['directory'],
            ], RouteWebRoot::servedApplications($instance)),
            'shared_paths' => SharedPaths::forInstance($instance),
            'steps' => $rollback ? [] : array_map(static fn (DeploymentStep $step): array => [
                'name' => $step->name, 'phase' => $step->phase->value, 'required' => true,
                'timeout_seconds' => $step->timeoutSeconds, 'command_digest' => hash('sha256', $step->command),
            ], $config->steps),
        ];
    }

    /** @return array{array<string,mixed>|null,array<string,mixed>|null} */
    public function runtimeIntent(Instance $instance, ?Route $activating = null): array
    {
        if (! is_string($instance->selected_php_version)) {
            return [null, null];
        }
        $identity = ProductionPhpRuntimeIdentity::from($instance);
        $applications = RouteWebRoot::servedApplications($instance, $activating);
        $webRoot = $activating?->web_root;
        if (is_string($webRoot) && RouteWebRoot::poolSuffix($instance, $webRoot) !== null) {
            $directory = RouteWebRoot::relativeDirectory($webRoot);
            if (! array_any($applications, static fn (array $application): bool => $application['directory'] === $directory)) {
                $applications[] = ['web_root' => $webRoot, 'directory' => $directory, 'suffix' => substr(hash('sha256', $directory), 0, 8)];
            }
        }
        $metrics = $this->metrics->enabled($instance->node);
        $configuration = $this->php->render($identity, $metrics, applications: $applications);
        $initial = $this->php->render($identity, $metrics, initialRelease: true, applications: $applications);

        return [[
            'mode' => 'converge', 'user' => $identity->user, 'home' => $identity->home,
            'version' => $identity->version, 'marker' => $identity->marker(),
            'configuration' => ['main' => $configuration->main, 'pool' => $configuration->pool,
                'local_defaults' => $configuration->localDefaults, 'master_ini' => $configuration->masterIni,
                'unit' => $configuration->unit, 'initial_pool' => $initial->pool],
            'application' => $identity->applicationDirectory(), 'initial_application' => $identity->applicationDirectory(initialRelease: true),
        ], ['user' => $identity->user, 'home' => $identity->home, 'version' => $identity->version,
            'marker' => $identity->marker(), 'deadline_seconds' => 30]];
    }

    /** @return Intent */
    public static function admitted(Operation $operation): array
    {
        if (self::legacy($operation)) {
            throw new ResourceOperationException('deployment.interrupted', 'This deployment predates the Swamp execution contract. Reconcile it before retrying.', 409);
        }
        $data = ValidatedData::object($operation->input['deployment']);
        $variant = ValidatedData::string($data['variant']);
        if (! in_array($variant, ['development', 'production', 'rollback'], true)
            || ! is_bool($data['runtime_synchronous']) || ! is_bool($data['php']) || ! is_bool($data['needs_vp'])
            || ! is_array($data['steps']) || ! array_is_list($data['steps'])) {
            throw new InvalidArgumentException('Invalid admitted deployment intent.');
        }
        $steps = [];
        foreach ($data['steps'] as $step) {
            $step = ValidatedData::object($step);
            if (! is_bool($step['required'])) {
                throw new InvalidArgumentException('Invalid admitted deployment step.');
            }
            $steps[] = ['name' => ValidatedData::string($step['name']), 'phase' => ValidatedData::string($step['phase']),
                'required' => $step['required'], 'timeout_seconds' => ValidatedData::integer($step['timeout_seconds']),
                'command_digest' => ValidatedData::string($step['command_digest'])];
        }
        $served = [];
        if ($variant !== 'development') {
            if (! is_array($data['served']) || ! array_is_list($data['served'])) {
                throw new InvalidArgumentException('Invalid admitted application roots.');
            }
            foreach ($data['served'] as $application) {
                $application = ValidatedData::object($application);
                $served[] = ['web_root' => ValidatedData::string($application['web_root']), 'directory' => ValidatedData::string($application['directory'])];
            }
        }

        return ['variant' => $variant, 'runtime_synchronous' => $data['runtime_synchronous'],
            'php_profile' => $data['php_profile'] === null ? null : ValidatedData::object($data['php_profile']), 'projection_route_id' => isset($data['projection_route_id']) ? ValidatedData::integer($data['projection_route_id']) : null, 'php' => $data['php'], 'needs_vp' => $data['needs_vp'],
            'php_version' => ValidatedData::nullableString($data['php_version'] ?? null),
            'php_runtime' => $data['php_runtime'] === null ? null : ValidatedData::object($data['php_runtime']),
            'php_refresh' => $data['php_refresh'] === null ? null : ValidatedData::object($data['php_refresh']),
            'project_id' => ValidatedData::integer($data['project_id']), 'branch' => ValidatedData::string($data['branch']),
            'repository' => ValidatedData::string($data['repository']), 'home' => ValidatedData::string($data['home']),
            'user' => $variant === 'development' ? null : ValidatedData::string($data['user']),
            'relative_root' => $variant === 'development' ? null : ValidatedData::string($data['relative_root']),
            'application_suffix' => $variant === 'development' ? null : ValidatedData::string($data['application_suffix']),
            'served' => $served, 'shared_paths' => $variant === 'development' ? [] : ValidatedData::stringList($data['shared_paths']), 'steps' => $steps];
    }

    public function assertDevelopment(Instance $instance): void
    {
        if (! $instance->placedOnAppDev() || $instance->name !== 'default'
            || $instance->status !== InstanceState::Active
            || $instance->source_layout !== InstanceSourceLayout::Checkout->value) {
            throw new ResourceOperationException('deployment_config.unavailable', 'Development deployment is available only for an active default checkout on app-dev.', 409);
        }
    }

    public static function commit(Operation $operation): ?string
    {
        $target = $operation->input['target'] ?? null;
        $commit = $operation->kind === 'instance.rollback'
            ? ((is_array($target) ? ($target['commit'] ?? null) : null) ?? $operation->input['commit'] ?? null)
            : ($operation->input['commit'] ?? null);

        return is_string($commit) && GitCommitSha::isValid($commit) ? $commit : null;
    }

    public static function legacy(Operation $operation): bool
    {
        return ! is_array($operation->input['deployment'] ?? null);
    }

    /** Stable across retries and ordered by admission time for the agent's retention policy. */
    public static function releaseName(Operation $operation): string
    {
        $created = $operation->created_at ?? throw new InvalidArgumentException('An admitted Operation needs a creation time.');

        return $created->toImmutable()->utc()->format('YmdHis').'-op-'.sprintf('%020d', $operation->id);
    }

    public static function provenActivation(Operation $operation): ?DeploymentRelease
    {
        $run = InstanceExecutionEvidence::run($operation, 'activation');
        if (($run['name'] ?? null) !== 'instance.activate-release' || ($run['transport'] ?? null) !== 'instance-deploy-agent'
            || isset($run['result']['error_code'])) {
            return null;
        }

        return self::storedRelease($run['result']);
    }

    /** @return array{name:string,path:string,commit:string}|null */
    public static function storableRelease(?DeploymentRelease $release): ?array
    {
        return $release === null ? null : ['name' => $release->name, 'path' => $release->path, 'commit' => $release->commit];
    }

    public static function storedRelease(mixed $value): ?DeploymentRelease
    {
        if (! is_array($value) || ! is_string($value['name'] ?? null)
            || ! is_string($value['path'] ?? null) || ! is_string($value['commit'] ?? null)) {
            return null;
        }

        return new DeploymentRelease($value['name'], $value['path'], $value['commit']);
    }
}

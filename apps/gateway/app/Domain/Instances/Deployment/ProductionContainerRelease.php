<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Instances\InstanceDeploymentConfigResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentSync;
use App\Domain\Instances\InstanceGitHubReadTokens;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class ProductionContainerRelease
{
    public const string Workflow = 'production-release';

    public const array Terminal = ['job' => 'release', 'step' => 'Read the serving release', 'native' => true,
        'captures' => ['receipt' => ['job' => 'release', 'step' => 'Read the serving release', 'field' => 'stdout']]];

    /** @return array<string,mixed> */
    public function admission(Instance $instance, ?string $commit, bool $rollback = false, bool $automatic = false): array
    {
        $this->owner($instance);
        $material = $this->material($instance, $rollback);
        $current = ValidatedData::nullableString(ValidatedData::object($material['legacy'] ?? [])['commit'] ?? null);
        if ($current !== null && ! $automatic && $commit !== null && $commit !== $current) {
            throw new ResourceOperationException('deployment.migration_commit_mismatch', "First deploy the current checkout commit [{$current}] to adopt it, then deploy the requested commit.", 409);
        }
        $sha = $current ?? $commit ?? app(RemoteBranchHeads::class)->head($instance->project, ValidatedData::string($material['branch']));
        if (preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
            throw new ResourceOperationException('deployment.source_unconfirmed', 'The production release requires a full, confirmed Git commit.', 409);
        }
        if ($rollback && ($instance->production_release['old'] ?? null) !== $sha) {
            throw new ResourceOperationException('rollback.release_not_retained', 'This Instance does not retain the selected rollback container.', 422);
        }
        $environment = app(NativeInstanceEnvironmentSync::class)->plan(app(InstanceEnvironmentContextResolver::class)->resolve($instance, requireActiveNode: true, lockRoute: true, productionContainer: true), includeRuntime: false);

        return ['commit' => $sha, 'direct_environment' => $environment, 'production_release' => [
            'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'project_id' => $instance->project_id,
            'sha' => $sha, 'retained' => $rollback, 'expected_live' => $instance->production_release['live'] ?? null,
            'material_digest' => hash('sha256', json_encode($material, JSON_THROW_ON_ERROR)),
        ]];
    }

    private function owner(Instance $instance): void
    {
        $roles = $instance->node->roles->where('status', LifecycleStatus::Active)->pluck('role');
        if (! $instance->placedOnAppProd() || $instance->status !== InstanceState::Active || $instance->node->status !== LifecycleStatus::Active
            || ! $roles->contains(RoleName::AppProd) || $roles->contains(RoleName::AppDev)
            || $instance->project->repository_identity !== GitRepositoryIdentity::derive($instance->project->repository_url)) {
            throw new ResourceOperationException('deployment.identity_invalid', 'The active AppProd placement and Project repository identity must match.', 409);
        }
    }

    /** @return array<string,mixed> Private material. Only its digest enters the Operation. */
    private function material(Instance $instance, bool $rollback): array
    {
        $configuration = app(InstanceDeploymentConfigResolver::class)->resolve($instance);
        $members = app(ProductionContainerUnits::class)->forInstance($instance);
        $routes = $instance->routes()->where('routes.status', RouteStatus::Active)->orderBy('routes.id')->get()
            ->filter(fn (Route $route): bool => $route->isApp() && $route->project_id === $instance->project_id)
            ->map(fn (Route $route): array => ['id' => $route->id, 'domain' => $route->domain, 'web_root' => RouteWebRoot::served($route, $instance) ?? '.'])->values()->all();
        if ($routes === []) {
            throw new ResourceOperationException('deployment.route_unavailable', 'The Instance needs an active application Route for release readiness and traffic switching.', 409);
        }
        $legacy = null;
        if ($instance->production_release === null && ! $instance->usesProductionReleaseLayout()
            && ($instance->production_home !== null || $instance->production_user !== null || $instance->starting_commit !== null)) {
            throw new ResourceOperationException('deployment.identity_invalid', 'The recorded production checkout cannot be safely adopted.', 409);
        }
        if ($instance->production_release === null && $instance->usesProductionReleaseLayout()) {
            $home = '/home/orbit-app-'.$instance->project_id;
            if ($instance->production_home !== $home || $instance->production_user !== 'orbit-app-'.$instance->project_id) {
                throw new ResourceOperationException('deployment.identity_invalid', 'The checkout belongs to a different production account.', 409);
            }
            $release = basename($instance->checkout_path);
            $commit = RecordedReleases::of($instance)[$release] ?? ($release === 'initial' ? ($instance->starting_commit) : null);
            if (! is_string($commit) || preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
                throw new ResourceOperationException('deployment.migration_source_unconfirmed', 'The current checkout has no confirmed serving commit for migration.', 409);
            }
            $legacy = ['home' => $home, 'project_id' => $instance->project_id, 'commit' => $commit,
                'units' => array_values(array_filter([...$members['host_units'], $instance->production_php_service]))];
        }

        return ['action' => $rollback ? 'rollback' : 'deploy', 'expected_live' => $instance->production_release['live'] ?? null,
            'repository' => $instance->project->repository_url, 'branch' => $configuration->branch,
            'application_suffix' => ProductionApplicationPaths::suffix($instance->root ?? $instance->project->root),
            'php_version' => $instance->selected_php_version ?? $instance->project->php_version ?? '8.5',
            'shared_paths' => SharedPaths::forInstance($instance), 'routes' => $routes,
            'health_path' => $instance->project->is_laravel ? '/up' : '/',
            'steps' => $rollback ? [] : array_map(fn (DeploymentStep $step): array => ['name' => $step->name, 'phase' => $step->phase->value,
                'command' => $step->command, 'timeout_seconds' => $step->timeoutSeconds], $configuration->steps),
            'units' => $members['units'], 'processes' => $members['processes'], 'timers' => $members['timers'], 'legacy' => $legacy];
    }

    /** @return array<string,mixed> */
    public function workflowInput(Operation $operation): array
    {
        $release = ValidatedData::object($operation->input['production_release']);
        $instance = Instance::query()->with(['node.roles', 'project'])->findOrFail($operation->target_id);
        $this->owner($instance);
        $rollback = $operation->kind === 'instance.rollback';
        $material = $this->material($instance, $rollback);
        if (! in_array($operation->kind, ['instance.deploy', 'instance.rollback'], true) || $operation->target_type !== 'instance'
            || $release['retained'] !== $rollback || $release['instance_id'] !== $instance->id || $release['node_id'] !== $instance->node_id
            || $operation->node_id !== $instance->node_id || $release['project_id'] !== $instance->project_id
            || ($rollback && $release['sha'] !== ($instance->production_release['old'] ?? null))
            || hash('sha256', json_encode($material, JSON_THROW_ON_ERROR)) !== $release['material_digest']) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The production release target or admitted configuration changed.', 409);
        }
        $intent = ['target' => 'instance-'.$instance->id, 'instance_id' => $instance->id, 'sha' => $release['sha'], 'retained' => $rollback, 'ssh' => app(SwampSshExecutionInput::class)->forNode($instance->node)];
        $files = app(NativeInstanceEnvironmentSync::class)->admittedFiles($operation);
        $private = [...$material, 'operation_id' => $operation->id, 'node_id' => $instance->node_id,
            'instance_id' => $instance->id, 'sha' => $release['sha'], 'environment' => $files['content'],
            ...($files['testing'] === null ? [] : ['testing_environment' => $files['testing']['content']])];
        $key = 'production-release-operation-'.$operation->id;
        $copy = $rollback ? app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', $key, json_encode([...$private, 'credential' => ''], JSON_THROW_ON_ERROR))
            : app(InstanceGitHubReadTokens::class)->credentialCopy(ValidatedData::string($material['repository']), $key, $private);

        return ['intent' => $intent, 'execution_secrets' => ['release' => $copy]];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            app(DirectOperation::class)->assertRun($locked, ValidatedData::string($data['run_id']));
            if ($locked->status->terminal()) {
                return $locked;
            }
            $release = ValidatedData::object($locked->input['production_release']);
            $instance = Instance::query()->where('node_id', $locked->node_id)->where('project_id', $release['project_id'])->lockForUpdate()->findOrFail($locked->target_id);
            $status = ValidatedData::string($data['status']);
            $receipt = null;
            $raw = ValidatedData::object($data['result'] ?? [])['receipt'] ?? null;
            $observed = is_string($raw) && strlen($raw) <= 8192 ? json_decode($raw, true) : null;
            $selected = null;
            if (is_array($observed) && ($observed['instance_id'] ?? null) === $instance->id && ($observed['node_id'] ?? null) === $locked->node_id
                && ($observed['operation_id'] ?? null) === $locked->id && is_string($observed['live'] ?? null)
                && array_key_exists('old', $observed) && ($instance->production_release['live'] ?? null) === $release['expected_live']
                && preg_match('/\Asha256:[0-9a-f]{64}\z/D', is_string($observed['artifact'] ?? null) ? $observed['artifact'] : '') === 1) {
                if ($observed['live'] === $release['sha'] && $observed['old'] === $release['expected_live']) {
                    $receipt = $observed;
                    $selected = substr($observed['live'], 0, 12);
                } elseif ($status !== 'succeeded' && $observed['live'] === $release['expected_live']
                    && $observed['old'] === ($instance->production_release['old'] ?? null) && $observed['artifact'] === ($instance->production_release['artifact'] ?? null)) {
                    $selected = substr($observed['live'], 0, 12);
                }
            }
            if ($status === 'succeeded' && $receipt === null) {
                $data['status'] = $status = 'failed';
                $data['error_code'] = 'deployment.selection_unconfirmed';
            }
            $name = $receipt === null ? null : substr(ValidatedData::string($release['sha']), 0, 12);
            $facts = ['deployment_id' => $locked->state['deployment_id'] ?? null, 'release' => $name, 'selected_release' => $selected,
                'commit' => $release['sha'], 'failed_step' => $status === 'failed' ? 'production-release' : null];
            if ($status === 'failed') {
                $detail = ValidatedData::object($data['failure'] ?? [])['detail'] ?? null;
                if (is_string($detail) && preg_match('/\b(deployment\.[a-z_]{1,80}):/', $detail, $match) === 1 && ($data['error_code'] ?? null) !== 'deployment.selection_unconfirmed') {
                    $data['error_code'] = $match[1];
                }
            }
            $result = app(DirectOperation::class)->project($locked, $data, $facts);
            if ($status !== 'running') {
                if ($receipt !== null) {
                    $instance->forceFill(['production_release' => ['live' => $receipt['live'], 'old' => $receipt['old'], 'artifact' => $receipt['artifact']],
                        'environment_owned_keys' => ValidatedData::object($locked->input['direct_environment'])['keys'], 'environment_runtime_pending_at' => null])->saveOrFail();
                }
                $row = InstanceDeployment::query()->where('instance_id', $instance->id)->findOrFail(ValidatedData::integer($facts['deployment_id']));
                app(InstanceDeploymentRecorder::class)->recordNative($row, $status, ValidatedData::nullableString($data['error_code'] ?? null), $facts);
            }

            return $result;
        });
    }
}

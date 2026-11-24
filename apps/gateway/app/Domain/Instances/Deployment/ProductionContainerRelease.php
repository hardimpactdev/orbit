<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Instances\InstanceDeploymentConfigResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentSync;
use App\Domain\Instances\InstanceGitHubReadTokens;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\NativeFailedStep;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Schedules\NativeSchedules;
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
use App\Models\Process;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class ProductionContainerRelease
{
    public const string Workflow = 'production-release';

    // The shared chart bounds selection/restore, verification, drain, retirement and cleanup separately.
    // Include its clone, parallel readiness/park, SSH and queue allowances in the outer deadline.
    public const int HandoverBudgetSeconds = 7800;

    // Each SSH phase in production-release declares this maximum in its input schema.
    public const int MaximumPhaseSeconds = 90000;

    public const array Terminal = ['job' => 'release', 'step' => 'Read the serving release', 'native' => true,
        'captures' => ['receipt' => ['job' => 'release', 'step' => 'Read the serving release', 'field' => 'stdout']]];

    /** @return array{instance_id:int,node_id:int,project:string,name:string,release:string}|null */
    public static function target(Instance $instance): ?array
    {
        $fresh = Instance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
        if ($fresh->node_id !== $instance->node_id) {
            throw new ResourceOperationException('deployment.identity_invalid', 'The Instance placement changed after admission.', 409);
        }
        if (! $fresh->placedOnAppProd()) {
            if ($fresh->production_release_started_at !== null || $fresh->production_release !== null) {
                throw new ResourceOperationException('deployment.identity_invalid', 'The recorded release belongs to a different AppProd placement.', 409);
            }

            return null;
        }
        $sha = $fresh->production_release['live'] ?? null;
        if ($fresh->production_release_started_at === null && $sha === null) {
            return null;
        }
        if ($sha === null || Operation::query()
            ->where('lock_key', OperationStore::lockKey('instance', $instance->id))
            ->whereNotNull('input->production_release')->exists()) {
            throw new ResourceOperationException('deployment.selection_unconfirmed', 'Wait for the serving production release to be confirmed before changing its runtime.', 409);
        }

        return ['instance_id' => $fresh->id, 'node_id' => $fresh->node_id, 'project' => 'orbit-releases',
            'name' => 'instance-'.$fresh->id.'-'.substr($sha, 0, 12), 'release' => $sha];
    }

    /** @return array<string,mixed> */
    public function admission(Instance $instance, ?string $commit, bool $rollback = false, bool $automatic = false): array
    {
        $this->owner($instance);
        if (Operation::query()->where('node_id', $instance->node_id)
            ->where(fn ($query) => $query->whereNotIn('status', OperationStatus::terminalValues())->orWhereNotNull('lock_key'))
            ->where(function ($query) use ($instance): void {
                $query->where(fn ($q) => $q->where('target_type', 'instance')->where('target_id', $instance->id)
                    ->where(fn ($kind) => $kind->where('kind', 'like', 'schedule.%')->orWhereIn('kind', ['instance.sync_environment', 'instance.import_environment'])))
                    ->orWhere(fn ($q) => $q->where('target_type', 'process')->whereIn('target_id', Process::query()
                        ->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)->select('id')));
            })->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'Wait for this Instance’s runtime control to finish before releasing it.', 409);
        }
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

        $steps = $material['steps'];
        if (! is_array($steps) || ! array_is_list($steps)) {
            throw new \LogicException('Invalid deployment step list.');
        }
        $before = $material['needs_vp'] === true ? 3450 : 2400;
        $after = 60;
        $optional = [];
        foreach ($steps as $step) {
            $step = ValidatedData::object($step);
            $time = ValidatedData::integer($step['timeout_seconds']) + 20;
            if ($step['phase'] === 'before_activation') {
                $before += $time;
            } else {
                $after += $time;
            }
            if ($step['required'] === false) {
                $optional['step:'.ValidatedData::string($step['name'])] = ValidatedData::string($step['phase']);
            }
        }

        if ($before > self::MaximumPhaseSeconds || $after > self::MaximumPhaseSeconds) {
            throw new ResourceOperationException('deployment.step_budget_exceeded', 'The configured commands exceed the Swamp release phase deadline.', 422);
        }

        return ['commit' => $sha, 'run_budget_seconds' => $before + $after + self::HandoverBudgetSeconds, 'direct_environment' => $environment, 'production_release' => [
            'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'project_id' => $instance->project_id,
            'sha' => $sha, 'legacy_commit' => $current, 'retained' => $rollback, 'expected_live' => $instance->production_release['live'] ?? null,
            'build_timeout_seconds' => $before, 'after_timeout_seconds' => $after, 'optional_steps' => $optional,
            'material_digest' => hash('sha256', json_encode($material, JSON_THROW_ON_ERROR)),
        ]];
    }

    private function owner(Instance $instance): void
    {
        $roles = $instance->node->roles->where('status', LifecycleStatus::Active)->pluck('role');
        if (! $instance->placedOnAppProd() || $instance->status !== InstanceState::Active || $instance->node->status !== LifecycleStatus::Active
            || ! $roles->contains(RoleName::AppProd) || $roles->contains(RoleName::AppDev)
            || $instance->project->repository_identity !== GitRepositoryIdentity::derive($instance->project->repositoryUrl())) {
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
            ->map(function (Route $route) use ($instance): array {
                $webRoot = RouteWebRoot::served($route, $instance);
                if ($route->web_root === null && ($webRoot === null || $webRoot === '.')
                    && ($instance->source_is_laravel || $instance->project->type->isWebServing())) {
                    $webRoot = 'public';
                }

                return ['id' => $route->id, 'domain' => $route->domain, 'web_root' => $webRoot ?? '.'];
            })->values()->all();
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
            'repository' => $instance->project->repositoryUrl(), 'branch' => $configuration->branch,
            'application_suffix' => ProductionApplicationPaths::suffix($instance->root ?? $instance->project->root),
            'php_version' => $instance->selected_php_version ?? $instance->project->php_version ?? '8.5',
            'needs_vp' => ! $rollback && array_any($configuration->steps, static fn (DeploymentStep $step): bool => InstanceVpEnvironmentMaterial::required($step->name, $step->command)),
            'shared_paths' => SharedPaths::forProduction($instance), 'routes' => $routes,
            'health_path' => $instance->project->is_laravel ? '/up' : '/',
            'steps' => $rollback ? [] : array_map(fn (DeploymentStep $step): array => ['name' => $step->name, 'phase' => $step->phase->value,
                'command' => $step->command, 'required' => $step->required, 'timeout_seconds' => $step->timeoutSeconds], $configuration->steps),
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
        $intent = ['target' => 'instance-'.$instance->id, 'instance_id' => $instance->id, 'sha' => $release['sha'], 'retained' => $rollback, 'build_timeout_seconds' => $release['build_timeout_seconds'], 'after_timeout_seconds' => $release['after_timeout_seconds'], 'ssh' => app(SwampSshExecutionInput::class)->forNode($instance->node)];
        $files = app(NativeInstanceEnvironmentSync::class)->admittedFiles($operation);
        $private = [...$material, 'operation_id' => $operation->id, 'node_id' => $instance->node_id,
            'instance_id' => $instance->id, 'sha' => $release['sha'], 'environment' => $files['content'], 'sql' => $files['sql'],
            ...($files['testing'] === null ? [] : ['testing_environment' => $files['testing']['content']])];
        if ($material['legacy'] !== null) {
            // A previously scheduled host fire must not start after the final data copy.
            app(NativeSchedules::class)->pauseForInstance($instance->id);
        }
        $key = 'production-release-operation-'.$operation->id;
        $copy = $rollback ? app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', $key, json_encode([...$private, 'credential' => ''], JSON_THROW_ON_ERROR))
            : app(InstanceGitHubReadTokens::class)->credentialCopy(ValidatedData::string($material['repository']), $key, $private);

        return ['intent' => $intent, 'execution_secrets' => ['release' => $copy]];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        $projected = DB::transaction(function () use ($operation, $data): Operation {
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
                'commit' => $release['sha'], 'failed_step' => null];
            if ($status === 'failed') {
                $detail = ValidatedData::object($data['failure'] ?? [])['detail'] ?? null;
                if (is_string($detail) && preg_match('/\b(deployment\.[a-z_]{1,80}):/', $detail, $match) === 1 && ($data['error_code'] ?? null) !== 'deployment.selection_unconfirmed') {
                    $data['error_code'] = $match[1];
                }
            }
            if ($status === 'failed') {
                $label = NativeFailedStep::label($data, ['Check release ownership', 'Build the Instance release',
                    'Clone the prepared release', 'Sleep preparation', 'Check the Instance candidate', 'Hand over the release',
                    'Release unused candidate', 'Read the serving release']);
                $facts['failed_step'] = ($data['error_code'] ?? null) === 'deployment.node_capacity' ? 'preparation' : ($label === 'Check release ownership' ? 'preparation' : ($label ?? 'release'));
            }
            if ($receipt !== null && is_array($receipt['best_effort_steps'] ?? null)) {
                $rows = [];
                foreach (array_slice($receipt['best_effort_steps'], 0, 32) as $step) {
                    if (is_array($step) && is_string($step['name'] ?? null) && is_int($step['exit_code'] ?? null)
                        && $step['exit_code'] >= 0 && $step['exit_code'] <= 255 && is_string($step['phase'] ?? null)
                        && (ValidatedData::object($release['optional_steps'] ?? [])['step:'.$step['name']] ?? null) === $step['phase']) {
                        $rows[$step['name']] = ['name' => $step['name'], 'exit_code' => $step['exit_code']];
                    }
                }
                $facts['best_effort_steps'] = array_values($rows);
            }
            $result = app(DirectOperation::class)->project($locked, $data, $facts);
            if ($status !== 'running') {
                if ($receipt !== null) {
                    $instance->forceFill(['production_release' => ['live' => $receipt['live'], 'old' => $receipt['old'], 'artifact' => $receipt['artifact']],
                        'environment_owned_keys' => ValidatedData::object($locked->input['direct_environment'])['keys'], 'environment_runtime_pending_at' => null])->saveOrFail();
                }
                if ($status !== 'succeeded' && $instance->production_release === null && $release['expected_live'] === null
                    && is_string($release['legacy_commit'] ?? null) && is_array($observed)
                    && ($observed['operation_id'] ?? null) === $locked->id && ($observed['node_id'] ?? null) === $instance->node_id
                    && ($observed['instance_id'] ?? null) === $instance->id && ($observed['legacy_restored'] ?? null) === true
                    && ($observed['legacy_commit'] ?? null) === $release['legacy_commit'] && array_key_exists('live', $observed)
                    && $observed['live'] === null && array_key_exists('old', $observed) && $observed['old'] === null) {
                    $instance->forceFill(['production_release_started_at' => null])->saveOrFail();
                }
                $row = InstanceDeployment::query()->where('instance_id', $instance->id)->findOrFail(ValidatedData::integer($facts['deployment_id']));
                app(InstanceDeploymentRecorder::class)->recordNative($row, $status, ValidatedData::nullableString($data['error_code'] ?? null), $facts);
            }

            return $result;
        });
        if ($projected->status->terminal() && (ValidatedData::object($operation->input['production_release'])['expected_live'] ?? null) === null) {
            $instance = Instance::query()->findOrFail($operation->target_id);
            if ($instance->production_release_started_at === null || isset($instance->production_release['live'])) {
                app(NativeSchedules::class)->republishForInstance($instance->id);
            }
        }

        return $projected;
    }
}

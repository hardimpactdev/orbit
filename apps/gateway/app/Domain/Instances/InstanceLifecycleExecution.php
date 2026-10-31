<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use InvalidArgumentException;

/**
 * Binds public descriptors to admitted intent and projects transport-proven receipts. Contains no executor.
 *
 * @phpstan-import-type Descriptor from OperationFamilyExecution
 * @phpstan-import-type CancellationBoundary from OperationFamilyExecution
 * @phpstan-import-type RunInput from \App\Domain\Operations\SwampOperationExecutor
 */
final readonly class InstanceLifecycleExecution implements OperationFamilyChildren, OperationFamilyExecution
{
    public function __construct(private InstanceLifecycleState $state) {}

    public function childContext(Operation $parent): array
    {
        return app(InstanceLifecycleChildren::class)->context($parent);
    }

    public function assertChild(Operation $parent, string $key, string $kind): Operation
    {
        $child = app(OperationChildren::class)->requireSucceeded($parent, $key);
        if ($child->kind !== $kind) {
            throw new ResourceOperationException('operation.child_unproven', 'The child kind does not match the lifecycle boundary.', 409);
        }

        return $child;
    }

    /** @return array{descriptors: list<Descriptor>, result?: array<string, mixed>} */
    public function context(Operation $operation): array
    {
        $descriptors = match ($operation->kind) {
            'instance.create' => $this->create($operation),
            'instance.rename' => [$this->inspect($operation, 'rename-inspect')],
            'instance.clone' => ($operation->input['initial_completed'] ?? false) ? [] : $this->clone($operation),
            'instance.register' => app(RegistrationLifecycleExecution::class)->descriptors($operation),
            'instance.destroy' => app(RemovalLifecycleExecution::class)->descriptors($operation),
            'instance.transfer', 'instance.transfer_destination' => app(TransferLifecycleExecution::class)->descriptors($operation),
            default => throw $this->forbidden(),
        };
        $descriptors[] = $this->descriptor('lifecycle-finish', 'gateway', 'instance.lifecycle-state', [
            'action' => 'finish', 'operation_id' => $operation->id,
        ]);

        foreach ($descriptors as &$descriptor) {
            $bound = ValidatedData::object($operation->state['lifecycle_bindings'] ?? [])[$descriptor['key']] ?? null;
            if (is_array($bound)) {
                $descriptor['transport'] = ValidatedData::string($bound['transport']);
                $descriptor['name'] = ValidatedData::string($bound['name']);
                $descriptor['step'] = ValidatedData::string($bound['step']);
                $descriptor['input'] = ValidatedData::object($bound['input']);
            }
        }
        unset($descriptor);

        return ['descriptors' => $descriptors, ...($operation->kind === 'instance.transfer' ? ['result' => [
            'rollback_pending' => app(TransferLifecycleExecution::class)->cancellationPendingRecovery($operation),
        ]] : [])];
    }

    /** @param RunInput $run
     * @return array<string, mixed>
     */
    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['transport'] === $run['transport'] && $descriptor['name'] === $run['name']) {
                return $descriptor['input'];
            }
        }
        throw $this->forbidden();
    }

    /** @param RunInput $run */
    public function beforeRun(Operation $operation, array $run): void
    {
        $expected = $this->input($operation, $run);
        $bindings = ValidatedData::object($operation->state['lifecycle_bindings'] ?? []);
        if (! isset($bindings[$run['key']])) {
            $bindings[$run['key']] = ['transport' => $run['transport'], 'name' => $run['name'], 'step' => $run['key'], 'input' => $expected];
            app(OperationStore::class)->change($operation, ['state' => [...($operation->state ?? []), 'lifecycle_bindings' => $bindings]]);
        }
        if ($operation->kind === 'instance.create' && $run['key'] === 'instance-activate') {
            $this->assertChild($operation, 'environment-sync', 'instance.sync_environment');
            $this->assertChild($operation, 'vp-environment', 'instance.prepare_vp_environment');
            if ($operation->input['route_id'] !== null) {
                $this->assertChild($operation, 'route-converge', 'route.converge');
            }
        }
        if ($operation->kind === 'instance.clone' && $run['key'] === 'clone-definitions') {
            $this->assertChild($operation, 'clone-environment-sync', 'instance.sync_environment');
        }
        if ($run['key'] === 'lifecycle-finish') {
            $this->assertEvidence($operation);

            return;
        }
        if ($operation->kind === 'instance.rename') {
            $instance = $this->state->instance($operation);
            if ($instance->status !== InstanceState::Active || ! $instance->placedOnAppDev()) {
                throw new ResourceOperationException('instance.rename_inactive', 'Rename requires an active development Instance.', 409);
            }
        }
        if ($operation->kind === 'instance.clone' && $run['key'] === 'clone-user') {
            $candidate = Instance::query()->findOrFail(ValidatedData::integer($operation->input['candidate_id']));
            $current = app(InstanceCloneCandidateInspector::class)->inspect($candidate, ValidatedData::string($operation->input['branch']));
            if ($current->commit !== $operation->input['candidate_commit']
                || $current->basePath !== $operation->input['candidate_path']
                || $current->node->id !== $operation->input['candidate_node_id']
                || $current->executionUser !== $operation->input['candidate_user']
                || $current->environment !== $operation->input['candidate_environment']
                || $current->branch !== $operation->input['candidate_branch']) {
                throw new ResourceOperationException('instance.clone_candidate_changed', 'The admitted candidate source changed.', 409);
            }
        }
        $prerequisites = match ($run['key']) {
            'source-inspect' => ($operation->input['initial_status'] ?? null) === 'active' ? [] : ['source-prepare'],
            'source-resolve' => ['source-prepare', 'source-inspect'],
            'source-profile' => ['source-resolve'],
            'source-configuration' => ['source-profile'],
            'instance-activate' => ['source-configuration'],
            'database-intent' => ($operation->input['initial_status'] ?? null) === 'active' ? ['source-inspect'] : ['instance-activate'],
            'clone-source-prepare' => ['clone-user'],
            'clone-source-resolve' => ['clone-source-prepare'],
            'clone-source-profile' => ['clone-source-resolve'],
            'clone-caddy-access' => ['clone-source-profile'],
            'clone-environment' => ['clone-caddy-access'],
            'clone-sqlite-intent' => ['clone-environment'],
            'clone-sqlite-attach' => ['clone-sqlite-intent'],
            'clone-definitions' => ['clone-environment'],
            default => [],
        };
        foreach ($prerequisites as $key) {
            $this->proven($operation, $key);
        }
        match ($operation->kind) {
            'instance.register' => app(RegistrationLifecycleExecution::class)->beforeRun($operation, $run),
            'instance.destroy' => app(RemovalLifecycleExecution::class)->beforeRun($operation, $run),
            'instance.transfer', 'instance.transfer_destination' => app(TransferLifecycleExecution::class)->beforeRun($operation, $run),
            default => null,
        };
    }

    /** @param RunInput $run
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code'])) {
            return $receipt;
        }
        if ($run['key'] === 'rename-inspect') {
            try {
                $valid = is_string($receipt['origin'] ?? null)
                    && GitRepositoryIdentity::derive($receipt['origin']) === $operation->input['repository_identity'];
            } catch (InvalidArgumentException) {
                $valid = false;
            }
            if (! $valid) {
                return ['error_code' => 'instance.source_identity_invalid'];
            }
            if (($operation->input['branch'] ?? null) !== null && ($receipt['branch'] ?? null) !== $operation->input['branch']) {
                return ['error_code' => 'instance.branch_not_checked_out'];
            }

            return ['source_verified' => true, 'branch' => $receipt['branch'] ?? null];
        }
        if ($run['key'] === 'source-inspect' && ($operation->input['initial_status'] ?? null) === 'active') {
            return $this->state->retainedSource($operation);
        }
        if ($run['key'] === 'source-prepare') {
            $this->state->prepared($operation, $receipt);
        }
        if ($run['key'] === 'clone-user' && $run['transport'] === 'instance_lifecycle') {
            $this->state->instance($operation)->update(['provisioning_step' => 'clone-user-prepared']);
        }
        if ($run['key'] === 'clone-source-prepare' && $run['transport'] === 'instance_lifecycle') {
            $this->state->instance($operation)->update(['status' => InstanceState::CheckoutPrepared, 'provisioning_step' => 'clone-source-prepared']);
        }
        if (in_array($run['key'], ['source-resolve', 'clone-source-resolve'], true) && $run['transport'] === 'instance_lifecycle') {
            $this->state->resolved($operation, $receipt);
            if ($operation->kind === 'instance.clone') {
                $this->state->instance($operation)->update(['provisioning_step' => 'clone-source-resolved']);
            }
        }
        if ($run['key'] === 'clone-caddy-access' && $run['transport'] === 'instance_lifecycle') {
            $this->state->instance($operation)->update(['provisioning_step' => 'clone-caddy-access-prepared']);
        }
        if ($run['key'] === 'clone-source-profile' && $run['transport'] === 'gateway') {
            return ['php_version' => $operation->input['selected_php_version'], 'laravel' => $operation->input['source_is_laravel']];
        }
        if (in_array($run['key'], ['source-profile', 'clone-source-profile'], true)) {
            return $this->classifyProfile($operation, $run['transport'] === 'gateway' ? ['kind' => 'none'] : $receipt);
        }
        if ($operation->kind === 'instance.create' && $run['key'] === 'source-configuration') {
            if (($receipt['environment_template'] ?? null) !== ['$secret' => 'instance.create_environment', 'id' => $operation->target_id]) {
                throw $this->forbidden();
            }

            return ['written' => true];
        }
        if ($operation->kind === 'instance.register') {
            return app(RegistrationLifecycleExecution::class)->received($operation, $run, $receipt);
        }
        if ($operation->kind === 'instance.destroy') {
            return app(RemovalLifecycleExecution::class)->received($operation, $run, $receipt);
        }
        if (in_array($operation->kind, ['instance.transfer', 'instance.transfer_destination'], true)) {
            return app(TransferLifecycleExecution::class)->received($operation, $run, $receipt);
        }

        return $receipt;
    }

    /** @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    public function classifyProfile(Operation $operation, array $receipt): array
    {
        try {
            return $this->state->classified($operation, $receipt);
        } catch (RuntimeConvergenceException|ResourceOperationException $exception) {
            return ['error_code' => $exception->errorCode];
        }
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($operation->kind === 'instance.transfer' && ($outcome->result['rollback_pending'] ?? false) === true
            && ($outcome->status !== OperationStatus::Cancelled
                || ! app(TransferLifecycleExecution::class)->cancellationPendingRecovery($operation))) {
            throw new ResourceOperationException('operation.completion_unproven', 'Pending recovery requires the cancelled transfer original capture intent.', 409);
        }
        if ($operation->kind === 'instance.register' && $outcome->status !== OperationStatus::Succeeded) {
            app(RegistrationLifecycleExecution::class)->assertFailure($operation, $outcome);
        }
        if ($operation->kind === 'instance.transfer' && $outcome->status !== OperationStatus::Succeeded
            && app(InstanceTransferState::class)->transfer($operation)->cutover_at === null
            && app(InstanceLifecycleChildren::class)->succeeded($operation, 'transfer-stop-source')
            && ($operation->cancel_requested_at !== null || app(TransferCompensation::class)->eligible($operation))) {
            if (app(TransferLifecycleExecution::class)->capturePending($operation)) {
                if ($outcome->status === OperationStatus::Cancelled
                    && app(TransferLifecycleExecution::class)->cancellationPendingRecovery($operation)
                    && ($outcome->result['rollback_pending'] ?? null) === true) {
                    return;
                }
                throw new ResourceOperationException('operation.execution_uncertain', 'Read the original transfer-capture receipt before admitting compensation or ending this transfer.', 409);
            }
            $rollback = app(OperationChildren::class)->child($operation, 'transfer-rollback');
            if (! $rollback->status->terminal() || $rollback->kind !== 'instance.transfer_rollback'
                || ($rollback->status === OperationStatus::Succeeded && ($rollback->result['restored'] ?? null) !== true)
                || ($rollback->status !== OperationStatus::Succeeded
                    && ($outcome->status !== OperationStatus::Failed || $rollback->error_code !== $outcome->errorCode))) {
                throw new ResourceOperationException('operation.completion_unproven', 'Settle the admitted transfer compensation before ending the stopped transfer.', 409);
            }
        }
        if ($outcome->status === OperationStatus::Succeeded) {
            $this->assertEvidence($operation);
            $this->proven($operation, 'lifecycle-finish');

            return;
        }
        $code = $outcome->errorCode ?? 'operation.cancelled';
        if ($outcome->status === OperationStatus::Cancelled && $operation->cancel_requested_at !== null) {
            return;
        }
        foreach (ValidatedData::object($operation->state['executor_runs'] ?? []) as $entry) {
            if (is_array($entry) && is_array($entry['result'] ?? null) && ($entry['result']['error_code'] ?? null) === $code) {
                return;
            }
        }
        foreach ($operation->children()->get() as $child) {
            app(OperationChildren::class)->verify($operation, $child);
            if ($child->status->terminal() && ($child->error_code === $code || ($child->result['error_code'] ?? null) === $code)) {
                return;
            }
        }
        throw new ResourceOperationException('operation.completion_unproven', 'The lifecycle failure needs a recorded run or child refusal.', 409);
    }

    /** @return CancellationBoundary|null */
    public function cancellationBoundary(Operation $operation): ?array
    {
        return match ($operation->kind) {
            'instance.create' => ['key' => 'instance-activate', 'transport' => 'gateway', 'name' => 'instance.lifecycle-state', 'result' => ['activated' => true]],
            'instance.transfer' => ['key' => 'transfer-cutover', 'transport' => 'gateway', 'name' => 'instance.lifecycle-state', 'result' => ['cutover' => true]],
            default => null,
        };
    }

    public function assertEvidence(Operation $operation): void
    {
        $this->assertChildren($operation);
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['required'] && $descriptor['key'] !== 'lifecycle-finish') {
                $this->proven($operation, $descriptor['key']);
            }
        }
    }

    private function assertChildren(Operation $operation): void
    {
        $input = $operation->input;
        if ($operation->kind === 'instance.create') {
            if ($input['initial_status'] === 'active' && $input['first_setup_pending'] === false) {
                return;
            }
            if ($input['initial_status'] !== 'active') {
                $this->assertChild($operation, 'environment-sync', 'instance.sync_environment');
                $this->assertChild($operation, 'vp-environment', 'instance.prepare_vp_environment');
                if ($input['route_id'] !== null) {
                    $this->assertChild($operation, 'route-converge', 'route.converge');
                }
            }
            if ($input['database_server_id'] !== null || $input['database_clone_source_id'] !== null) {
                $this->assertChild($operation, 'database', app(InstanceDatabaseIntent::class)->child($operation, 'database')['kind']);
                $this->assertChild($operation, 'database-environment-sync', 'instance.sync_environment');
            }
            $this->assertStepList($operation, 'setup');
        } elseif ($operation->kind === 'instance.rename' && $input['domain'] !== null) {
            $this->assertChild($operation, 'route-update', 'route.update');
        } elseif ($operation->kind === 'instance.clone' && $input['initial_completed'] !== true) {
            if ($input['sqlite_source_path'] !== null && ! app(InstanceSqliteSeedIntent::class)->retainedClone($operation)) {
                $this->assertChild($operation, 'clone-sqlite', 'database.sqlite_seed');
            }
            $this->assertChild($operation, 'clone-environment-sync', 'instance.sync_environment');
            $this->assertChild($operation, 'clone-processes', 'process.install_instance_definitions');
            $this->assertChild($operation, 'clone-schedules', 'schedule.install_instance_definitions');
            if ($input['route_id'] !== null) {
                $this->assertChild($operation, 'route-converge', 'route.converge');
            }
        } elseif ($operation->kind === 'instance.register') {
            foreach (InstanceLifecycleInput::members($input) as $member) {
                if ($member['route_id'] !== null) {
                    $this->assertChild($operation, 'route-'.ValidatedData::integer($member['instance_id']), 'route.converge');
                }
            }
            if ($input['run_setup'] === true) {
                $this->assertStepList($operation, 'setup');
            }
        } elseif ($operation->kind === 'instance.destroy') {
            $intent = app(RemovalLifecycleExecution::class)->intent($operation);
            foreach (ValidatedData::integerList($intent['additional_route_ids']) as $route) {
                $this->assertChild($operation, "withdraw-extra-{$route}", 'route.withdraw');
            }
            foreach (InstanceLifecycleInput::members($intent) as $member) {
                if (($member['row_deleted_at'] ?? null) !== null) {
                    continue;
                }
                $id = ValidatedData::integer($member['instance_id']);
                if ($member['run_teardown'] === true) {
                    $this->assertStepList($operation, "teardown-{$id}");
                }
                if ($member['environment'] !== 'production') {
                    if ($member['route_id'] !== null && ($member['route_cleared_at'] ?? null) === null) {
                        $this->assertChild($operation, "withdraw-{$id}", 'route.withdraw');
                    }
                    $this->assertChild($operation, "withdraw-instance-{$id}", 'route.withdraw_instance');
                }
                $this->assertChild($operation, "remove-databases-{$id}", 'database.destroy_owned');
                app(InstanceRemovalRuntime::class)->assertRemoved($operation, $id);
                $this->assertChild($operation, "cleanup-instance-{$id}", 'route.cleanup_instance');
            }
        } elseif ($operation->kind === 'instance.transfer' && $input['initial_completed'] !== true) {
            if ($input['initial_cutover'] !== true) {
                $this->assertChild($operation, 'transfer-destination', 'instance.transfer_destination');
            }
            $this->assertChild($operation, 'transfer-activate-destination', 'instance.transfer_activate');
            $this->assertChild($operation, 'transfer-retire-source', 'route.retire_source');
            $this->assertChild($operation, 'transfer-retire-runtime', 'process.instance_runtime');
        }
    }

    public function assertStepList(Operation $operation, string $key): void
    {
        $child = $this->assertChild($operation, $key, 'instance.run_steps');
        if (($child->result['outcome'] ?? null) !== 'succeeded' || ($child->result['error_code'] ?? null) !== null) {
            throw new ResourceOperationException('operation.child_unproven', 'The lifecycle list did not prove a successful outcome.', 409);
        }
    }

    /** @return array<string, mixed> */
    public function proven(Operation $operation, string $key): array
    {
        $run = ValidatedData::object($operation->state['executor_runs'] ?? [])[$key] ?? null;
        $descriptor = ValidatedData::object($operation->state['lifecycle_bindings'] ?? [])[$key] ?? null;
        if (! is_array($run) || ! is_array($descriptor) || ! is_array($run['result'] ?? null)
            || isset($run['result']['error_code']) || ($run['transport'] ?? null) !== $descriptor['transport']
            || ($run['name'] ?? null) !== $descriptor['name']
            || ($run['step'] ?? null) !== $descriptor['step'] || ($run['input'] ?? null) !== $descriptor['input']
            || ! $operation->steps()->where('name', $descriptor['step'])->where('status', 'succeeded')->exists()) {
            throw new ResourceOperationException('operation.completion_unproven', 'A proven successful lifecycle step is required.', 409);
        }

        return ValidatedData::object($run['result']);
    }

    /** @return array<string, mixed> */
    public function recordedResult(Operation $operation, string $key): array
    {
        $entry = ValidatedData::object($operation->state['executor_runs'] ?? [])[$key] ?? null;

        return is_array($entry) && is_array($entry['result'] ?? null) ? ValidatedData::object($entry['result']) : [];
    }

    /** @return list<Descriptor> */
    private function create(Operation $operation): array
    {
        if ($operation->children()->where('child_key', 'rollback-removal')->where('status', 'succeeded')->exists()) {
            $this->assertChild($operation, 'rollback-removal', ValidatedData::string($operation->children()->where('child_key', 'rollback-removal')->value('kind')));
            $descriptors = [];
            foreach (ValidatedData::object($operation->state['lifecycle_bindings'] ?? []) as $key => $binding) {
                $binding = ValidatedData::object($binding);
                $descriptors[] = $this->descriptor($key, ValidatedData::string($binding['transport']),
                    ValidatedData::string($binding['name']), ValidatedData::object($binding['input']));
            }

            return $descriptors;
        }
        $input = $operation->input;
        $database = $input['database_server_id'] !== null || $input['database_clone_source_id'] !== null
            ? [$this->descriptor('database-intent', 'gateway', 'instance.lifecycle-state', ['action' => 'database-intent', 'operation_id' => $operation->id])] : [];
        if (($input['initial_status'] ?? null) === 'active') {
            return [$this->inspect($operation, 'source-inspect'), ...$database];
        }
        $source = $this->source($operation);
        $instance = $this->state->instance($operation);

        return [
            $this->descriptor('source-prepare', 'instance_lifecycle', 'instance.prepare-development-source', [
                ...$source, 'allow_existing' => true, 'seed_commit' => $input['seed_commit'], 'network' => ! $input['task_vm'],
                'git_read' => ['$secret' => 'instance.lifecycle_git', 'id' => $input['project_id']],
            ]),
            $this->inspect($operation, 'source-inspect'),
            $this->descriptor('source-resolve', 'instance_lifecycle', 'instance.resolve-development-source', [
                ...$source, 'instance_name' => $input['name'], 'default_branch' => $input['default_branch'],
                'branch_override' => $input['branch_override'], 'seed_commit' => $input['seed_commit'], 'network' => ! $input['task_vm'],
                'protocols' => ValidatedData::stringList(config('orbit.git.checkout_protocols', ['https'])),
                'git_read' => ['$secret' => 'instance.lifecycle_git', 'id' => $input['project_id']],
            ]),
            $instance->project->type === ProjectType::Monorepo && ! $instance->routes()->exists()
                ? $this->descriptor('source-profile', 'gateway', 'instance.lifecycle-state', ['action' => 'configuration', 'operation_id' => $operation->id])
                : $this->descriptor('source-profile', 'instance_lifecycle', 'instance.inspect-development-profile', [
                    'application' => $instance->applicationDirectory(), 'owner' => $source['user'],
                    'entry_point' => $instance->project->type->frameworkEntryPoint(),
                ]),
            $this->descriptor('source-configuration', 'instance_lifecycle', 'instance.read-development-environment-template', [
                ...$source, 'application' => $instance->applicationDirectory(), 'commit' => $instance->starting_commit,
            ]),
            $this->descriptor('instance-activate', 'gateway', 'instance.lifecycle-state', ['action' => 'activate', 'operation_id' => $operation->id]),
            ...$database,
        ];
    }

    /** @return Descriptor */
    private function inspect(Operation $operation, string $key): array
    {
        return $this->descriptor($key, 'instance_lifecycle', 'instance.inspect-development-source', [
            ...$this->source($operation), 'verify_origin' => $key !== 'rename-inspect', 'require_idle' => $key === 'rename-inspect',
        ]);
    }

    /** @return array<string, mixed> */
    private function source(Operation $operation): array
    {
        $input = $operation->input;
        $this->state->instance($operation);

        return [
            'repository' => $input['repository'], 'checkout' => $input['checkout'],
            'root' => $input['source_root'],
            'user' => $input['user'], 'group' => $input['group'],
            'prepare_id' => $input['prepare_id'] ?? null, 'seed_repository' => $input['seed_repository'] ?? null,
        ];
    }

    /** @return list<Descriptor> */
    private function clone(Operation $operation): array
    {
        $input = $operation->input;
        $home = ['user' => $input['production_user'], 'home' => $input['production_home']];
        $source = [...$home, 'repository' => $input['repository'], 'instance' => $input['instance_id']];
        $git = ['$secret' => 'instance.lifecycle_git', 'id' => $input['project_id']];
        $instance = $this->state->instance($operation);
        $root = $instance->root ?? $instance->project->root;
        if (! is_string($root)) {
            throw $this->forbidden();
        }

        $descriptors = [
            $this->descriptor('clone-user', 'instance_lifecycle', 'instance.prepare-production-user', $home),
            $this->descriptor('clone-source-prepare', 'instance_lifecycle', 'instance.prepare-production-source', [...$source, 'allow_existing' => true, 'git_read' => $git]),
            $this->descriptor('clone-source-resolve', 'instance_lifecycle', 'instance.resolve-production-source', [...$source, 'relative_root' => $root, 'application_suffix' => ProductionApplicationPaths::suffix($root), 'branch' => $input['branch'], 'clone_target' => true, 'git_read' => $git]),
            $this->descriptor('clone-source-profile', 'instance_lifecycle', 'instance.inspect-production-profile', [
                'user' => $home['user'], 'relative_root' => $root, 'checkout' => $input['checkout'],
                'application' => ApplicationDirectory::resolve(ValidatedData::string($input['checkout']), $root),
                'entry_point' => $instance->project->type->frameworkEntryPoint(),
            ]),
            $this->descriptor('clone-caddy-access', 'instance_lifecycle', 'instance.grant-production-caddy-access', [...$home, 'relative_root' => $root, 'clone_target' => true]),
            $this->descriptor('clone-environment', 'gateway', 'instance.lifecycle-state', ['action' => 'clone-environment', 'operation_id' => $operation->id]),
            ...($input['sqlite_source_path'] === null ? [] : [
                $this->descriptor('clone-sqlite-intent', 'gateway', 'instance.lifecycle-state', ['action' => 'clone-sqlite-intent', 'operation_id' => $operation->id]),
                $this->descriptor('clone-sqlite-attach', 'gateway', 'instance.lifecycle-state', ['action' => 'clone-sqlite-attach', 'operation_id' => $operation->id]),
            ]),
            $this->descriptor('clone-definitions', 'gateway', 'instance.lifecycle-state', ['action' => 'clone-definitions', 'operation_id' => $operation->id]),
        ];
        $checkpoints = [
            'clone-user' => 'clone-user-prepared', 'clone-source-prepare' => 'clone-source-prepared',
            'clone-source-resolve' => 'clone-source-resolved', 'clone-source-profile' => 'clone-source-classified',
            'clone-caddy-access' => 'clone-caddy-access-prepared', 'clone-environment' => 'clone-environment-synchronized',
            'clone-definitions' => 'clone-definitions-instantiated',
        ];
        $order = ['clone-reserved', 'clone-user-prepared', 'clone-source-prepared', 'clone-source-resolved',
            'clone-source-classified', 'clone-caddy-access-prepared', 'clone-environment-synchronized',
            'clone-sqlite-prepared', 'clone-sqlite-attached', 'clone-definitions-instantiated',
            'clone-runtime-prepared', 'clone-certificate-prepared', 'clone-firewall-prepared',
            'clone-workload-caddy-published', 'clone-router-certificate-prepared', 'clone-route-firewall-prepared',
            'clone-workload-verified', 'clone-router-caddy-published', 'clone-dns-published', 'active'];
        $initial = array_search($input['initial_provisioning_step'], $order, true);
        if ($initial === false) {
            throw $this->forbidden();
        }
        if ($initial >= 4 && ! is_bool($input['source_is_laravel'])) {
            throw new ResourceOperationException('app-prod.source_evidence_changed', 'The retained clone has incomplete source profile evidence.', 409);
        }
        foreach ($descriptors as &$descriptor) {
            $checkpoint = array_search($checkpoints[$descriptor['key']] ?? null, $order, true);
            if ($checkpoint !== false && $initial >= $checkpoint) {
                $descriptor = $this->descriptor($descriptor['key'], 'gateway', 'instance.lifecycle-state',
                    ['action' => 'clone-checkpoint', 'operation_id' => $operation->id]);
            }
        }
        unset($descriptor);

        return $descriptors;

    }

    /** @return Descriptor */
    public function url(Operation $operation, string $key, Instance $instance, string $domain): array
    {
        return $this->descriptor($key, 'instance_lifecycle', 'instance.write-development-url', [
            'directory' => $instance->applicationDirectory(), 'owner' => $instance->node->user, 'url' => 'https://'.$domain,
            'app_key' => ['$secret' => 'instance.lifecycle_url', 'id' => $operation->input['instance_id']],
            'app_key_stored' => $instance->environmentValues()->where('env_key', 'APP_KEY')->exists(),
            'app_name' => ['$secret' => 'instance.lifecycle_url', 'id' => $operation->input['instance_id']],
            'app_name_stored' => $instance->environmentValues()->where('env_key', 'APP_NAME')->exists(), 'require_artisan' => false,
        ]);
    }

    /** @param array<string, mixed> $input
     * @return Descriptor
     */
    public function descriptor(string $key, string $transport, string $name, array $input, bool $required = true, bool $resumable = true): array
    {
        return ['key' => $key, 'step' => $key, 'transport' => $transport, 'name' => $name,
            'input' => $input, 'required' => $required, 'resumable' => $resumable];
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The lifecycle invocation does not match admitted intent.', 422);
    }
}

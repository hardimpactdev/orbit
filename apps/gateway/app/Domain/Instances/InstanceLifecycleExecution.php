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
use App\Domain\SourceControl\GitRepositoryIdentity;
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

    /** @return array{descriptors: list<Descriptor>} */
    public function context(Operation $operation): array
    {
        $descriptors = match ($operation->kind) {
            'instance.create' => $this->create($operation),
            'instance.rename' => [$this->inspect($operation, 'rename-inspect')],
            'instance.register' => app(RegistrationLifecycleExecution::class)->descriptors($operation),
            'instance.destroy' => app(RemovalLifecycleExecution::class)->descriptors($operation),
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

        return ['descriptors' => $descriptors];
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
        if ($operation->kind === 'instance.register') {
            return app(RegistrationLifecycleExecution::class)->received($operation, $run, $receipt);
        }
        if ($operation->kind === 'instance.destroy') {
            return app(RemovalLifecycleExecution::class)->received($operation, $run, $receipt);
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
        if ($operation->kind === 'instance.register' && $outcome->status !== OperationStatus::Succeeded) {
            app(RegistrationLifecycleExecution::class)->assertFailure($operation, $outcome);
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
                $this->assertChild($operation, 'database', $input['database_clone_source_id'] !== null ? 'database.clone' : 'database.create');
                $this->assertChild($operation, 'database-environment-sync', 'instance.sync_environment');
            }
            $this->assertStepList($operation, 'setup');
        } elseif ($operation->kind === 'instance.rename' && $input['domain'] !== null) {
            $this->assertChild($operation, 'route-update', 'route.update');
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
                if ($member['route_id'] !== null) {
                    $this->assertChild($operation, "withdraw-{$id}", 'route.withdraw');
                }
                $this->assertChild($operation, "withdraw-instance-{$id}", 'route.withdraw_instance');
                $this->assertChild($operation, "remove-databases-{$id}", 'database.destroy_owned');
                app(InstanceRemovalRuntime::class)->assertRemoved($operation, $id);
                $this->assertChild($operation, "cleanup-instance-{$id}", 'route.cleanup_instance');
            }
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
        $input = $operation->input;
        $database = $input['database_server_id'] !== null || $input['database_clone_source_id'] !== null
            ? [$this->descriptor('database-intent', 'gateway', 'instance.lifecycle-state', ['action' => 'database-intent', 'operation_id' => $operation->id])] : [];
        if (($input['initial_status'] ?? null) === 'active') {
            return [$this->inspect($operation, 'source-inspect'), ...$database];
        }
        $source = $this->source($operation);
        $profile = $this->recordedResult($operation, 'source-profile');
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
            ($profile['laravel'] ?? false) && isset($input['route_domain'])
                ? $this->url($operation, 'source-configuration', $instance, ValidatedData::string($input['route_domain']))
                : $this->descriptor('source-configuration', 'gateway', 'instance.lifecycle-state', ['action' => 'configuration', 'operation_id' => $operation->id]),
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

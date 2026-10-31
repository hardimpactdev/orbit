<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\InstanceRemovalState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Instances\RemovalLifecycleExecution;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'recovery-node', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Recovery', 'slug' => 'recovery', 'type' => 'monorepo', 'root' => '.', 'repository_url' => 'https://github.com/example/recovery.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'proof',
        'checkout_path' => '/srv/apps/recovery/proof', 'status' => 'active', 'source_layout' => 'worktree', 'branch' => 'proof', 'starting_commit' => str_repeat('a', 40)]);
    $removal = InstanceRemoval::query()->create(['id' => 'recovery-ledger', 'requested_instance_id' => $this->instance->id,
        'requested_name' => 'proof', 'force' => true, 'inventory_digest' => str_repeat('a', 64), 'total' => 1, 'status' => 'removing', 'current_step' => 'source_preparation']);
    $this->member = $removal->members()->create(['position' => 0, 'instance_id' => $this->instance->id, 'project_id' => $project->id,
        'node_id' => $node->id, 'name' => 'proof', 'environment' => 'development', 'source_layout' => 'worktree',
        'repository_identity' => $project->repository_identity, 'checkout_path' => $this->instance->checkout_path, 'root' => '.',
        'common_repository_path' => '/srv/apps/recovery/default/.git', 'linked_worktree_paths' => [$this->instance->checkout_path],
        'branch' => 'proof', 'starting_commit' => str_repeat('a', 40), 'source_commit' => str_repeat('a', 40),
        'source_identity' => '12:34', 'source_digest' => str_repeat('b', 64)]);
    $this->instance->update(['status' => 'removing']);
    $this->member->update(['source_prepared_at' => now()]);
    $this->member->update(['route_cleared_at' => now(), 'route_outcome' => 'none']);
    $this->operation = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $node->id, 'status' => 'running', 'input' => ['instance_id' => $this->instance->id, 'project_id' => $project->id,
            'force' => true, 'removal_id' => $removal->id, 'repository' => $project->repository_url,
            'members' => [[...$this->member->toArray(), 'source_root' => '/srv/apps', 'user' => 'orbit', 'group' => 'orbit', 'run_teardown' => false]]],
        'state' => ['executor_lease_node_id' => 17, 'executor_runs' => ['earlier' => ['result' => ['ready' => true]]]]]);
    $this->key = 'removal-'.$this->instance->id.'-source-finalize';
    $this->descriptor = fn (): array => array_find(app(InstanceLifecycleExecution::class)->context($this->operation)['descriptors'], fn (array $descriptor): bool => $descriptor['key'] === $this->key);
});

it('cleans only an authenticated partial removal receipt and requires its exact finalization identity', function (): void {
    $reader = Mockery::mock(DevelopmentInstanceSourceFinalizer::class);
    $reader->shouldReceive('revalidate')->once()->andReturn(InstanceSourceRevalidationState::ReceiptPendingCleanup);
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $reader);
    app(InstanceRemovalState::class)->revalidateRetained($this->operation);
    $descriptor = ($this->descriptor)();
    expect($descriptor['name'])->toBe('instance.clean-removal-receipt')->and($descriptor['transport'])->toBe('instance_lifecycle');
    expect($descriptor['input'])->not->toHaveKey('git_read')->and($descriptor['input']['checkout'])->toBe($this->instance->checkout_path);
    $execution = app(RemovalLifecycleExecution::class);
    $run = ['worker' => 'recovery-worker', ...$descriptor];
    expect($execution->received($this->operation, $run, ['receipt' => 'another-removal']))->toBe(['error_code' => 'instance.finalization_evidence_invalid']);
    expect($this->member->refresh()->source_finalized_at)->toBeNull();
    expect($execution->received($this->operation, $run, ['receipt' => $descriptor['input']['receipt']]))
        ->toBe(['finalized' => true, 'receipt' => $descriptor['input']['receipt']]);
    expect($this->member->refresh()->source_finalized_at)->not->toBeNull();
    expect($this->operation->refresh()->state['executor_lease_node_id'])->toBe(17);
});

it('projects an already completed authenticated source receipt without asking for another deletion', function (): void {
    $reader = Mockery::mock(DevelopmentInstanceSourceFinalizer::class);
    $reader->shouldReceive('revalidate')->once()->andReturn(InstanceSourceRevalidationState::Completed);
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $reader);
    app(InstanceRemovalState::class)->revalidateRetained($this->operation);

    expect($this->member->refresh()->source_finalized_at)->not->toBeNull();
    expect(($this->descriptor)()['transport'])->toBe('gateway');
    expect($this->operation->state['executor_runs']['earlier']['result'])->toBe(['ready' => true]);
});

it('retains a started descriptor identity when later source state selects a Gateway checkpoint', function (): void {
    $descriptor = ($this->descriptor)();
    $this->operation->update(['state' => [...$this->operation->state, 'lifecycle_bindings' => [$this->key => $descriptor]]]);
    $this->member->update(['source_finalized_at' => now(), 'finalization_receipt' => str_repeat('d', 64)]);

    expect(($this->descriptor)())->toBe($descriptor);
});

it('refuses database preparation until runtime children have proven cleanup', function (): void {
    $execution = app(InstanceLifecycleExecution::class);
    foreach (['removal-accept', 'removal-'.$this->instance->id.'-runtime-intent'] as $key) {
        $descriptor = $execution->descriptor($key, 'gateway', 'instance.lifecycle-state', ['action' => 'configuration', 'operation_id' => $this->operation->id]);
        $state = $this->operation->state;
        $state['lifecycle_bindings'][$key] = $descriptor;
        $state['executor_runs'][$key] = [...$descriptor, 'result' => ['ready' => true]];
        $this->operation->update(['state' => $state]);
        $this->operation->steps()->create(['name' => $key, 'status' => 'succeeded', 'position' => $this->operation->steps()->count() + 1, 'sequence' => 1, 'resumable' => true]);
    }
    $run = ['worker' => 'recovery-worker', 'key' => 'removal-'.$this->instance->id.'-database-intent',
        'transport' => 'gateway', 'name' => 'instance.lifecycle-state', 'input' => []];
    expect(fn () => app(RemovalLifecycleExecution::class)->beforeRun($this->operation, $run))
        ->toThrow(ResourceOperationException::class);
    expect($this->operation->refresh()->state)->not->toHaveKey('lifecycle_owned_databases');
});

it('captures development runtime inventory before checkout finalization after source and Route withdrawal', function (): void {
    $result = app(InstanceRemovalState::class)->transition($this->operation, [
        'action' => 'removal-runtime-intent', 'instance_id' => $this->instance->id,
    ]);

    expect($result)->toBe(['process_ids' => [], 'schedule_ids' => [], 'database_ids' => []]);
    expect($this->member->refresh()->source_finalized_at)->toBeNull();
    $this->assertModelExists($this->instance);
});

it('requires the successful database child before finalizing development checkout', function (string $status): void {
    $execution = app(InstanceLifecycleExecution::class);
    $prefix = 'removal-'.$this->instance->id;
    foreach (['removal-accept', $prefix.'-route-clear', $prefix.'-runtime-intent', $prefix.'-database-intent'] as $key) {
        $descriptor = $execution->descriptor($key, 'gateway', 'instance.lifecycle-state', ['action' => 'configuration', 'operation_id' => $this->operation->id]);
        $state = $this->operation->state;
        $state['lifecycle_bindings'][$key] = $descriptor;
        $state['executor_runs'][$key] = [...$descriptor, 'result' => $key === $prefix.'-runtime-intent'
            ? ['process_ids' => [], 'schedule_ids' => []] : ['ready' => true]];
        $this->operation->update(['state' => $state]);
        $this->operation->steps()->create(['name' => $key, 'status' => 'succeeded', 'position' => $this->operation->steps()->count() + 1, 'sequence' => 1, 'resumable' => true]);
    }
    if ($status !== 'missing') {
        $key = 'remove-databases-'.$this->instance->id;
        $identity = ['parent_operation_id' => $this->operation->id, 'key' => $key, 'kind' => 'database.destroy_owned',
            'node_id' => $this->operation->node_id, 'target_type' => 'instance', 'target_id' => $this->instance->id,
            'input' => ['owner_instance_id' => $this->instance->id], 'run_constraints' => []];
        $snapshot = ['intent' => ['key' => $key, 'kind' => 'database.destroy_owned', 'node_id' => $this->operation->node_id,
            'input' => $identity['input']], 'admitted' => $identity];
        $this->operation->children()->make(['kind' => 'database.destroy_owned',
            'node_id' => $this->operation->node_id, 'target_type' => 'instance', 'target_id' => $this->instance->id,
            'input' => $identity['input'], 'status' => $status])->forceFill(['child_key' => $key, 'run_constraints' => [],
                'child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    }
    $run = ['worker' => 'recovery-worker', ...($this->descriptor)()];

    if ($status === 'succeeded') {
        app(RemovalLifecycleExecution::class)->beforeRun($this->operation, $run);
    } else {
        expect(fn () => app(RemovalLifecycleExecution::class)->beforeRun($this->operation, $run))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.child_unproven'));
    }
    expect($this->member->refresh()->source_finalized_at)->toBeNull();
})->with(['missing', 'failed', 'succeeded']);

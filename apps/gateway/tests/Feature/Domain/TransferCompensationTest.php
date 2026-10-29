<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\DatabaseServers\DatabaseOperationExecution;
use App\Domain\Instances\InstanceLifecycleCompletion;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\InstanceSqliteSeedIntent;
use App\Domain\Instances\TransferCompensation;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class CompensationProcessAdmission implements OperationChildAdmission
{
    public function admit(Operation $parent, array $intent): Operation
    {
        return app(StartOperationAction::class)->execute($intent['kind'], 'instance', $parent->target_id, $intent['node_id'], $intent['input'], null, null);
    }
}

beforeEach(function (): void {
    $definitions = [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
        'process.instance_runtime' => ['executor' => 'swamp', 'admission' => CompensationProcessAdmission::class,
            'completion' => InstanceLifecycleCompletion::class, 'runs' => [['transport' => 'gateway', 'name' => 'instance.lifecycle-state']],
            'completion_contract' => ['step' => 'fixture', 'transport' => 'gateway', 'name' => 'instance.lifecycle-state', 'result' => []], 'result_fields' => ['ready']],
    ];
    $definitions['instance.transfer_activate'] = $definitions['process.instance_runtime'];
    // This compensation path has scalar receipts; C gates the complete root family.
    $definitions['instance.transfer']['result_fields'] = array_values(array_filter($definitions['instance.transfer']['result_fields'], is_string(...)));
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $source = Node::query()->create(['name' => 'compensation-source', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $destination = Node::query()->create(['name' => 'compensation-target', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $source->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $destination->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Compensation proof', 'slug' => 'compensation-proof', 'type' => 'monorepo', 'root' => '.', 'repository_url' => 'https://github.com/example/compensation.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $source->id, 'name' => 'proof',
        'checkout_path' => '/srv/apps/compensation-proof/proof', 'source_layout' => 'checkout', 'status' => 'active']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $source->id, 'domain' => 'compensation.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->transfer = InstanceTransfer::query()->create(['instance_id' => $this->instance->id, 'source_node_id' => $source->id,
        'destination_node_id' => $destination->id, 'source_path' => $this->instance->checkout_path, 'source_layout' => 'checkout',
        'source_route_id' => $route->id, 'destination_name' => 'proof', 'destination_path' => '/srv/apps/moved/proof',
        'destination_domain' => 'compensation.test', 'status' => 'in_progress', 'current_step' => 'source-captured']);
    $this->root = app(StartOperationAction::class)->execute('instance.transfer', 'instance', $this->instance->id, $source->id,
        ['instance_id' => $this->instance->id, 'transfer_id' => $this->transfer->id, 'initial_completed' => false, 'initial_cutover' => false], null, null);
    $this->executor = app(SwampOperationExecutor::class);
    $this->root = $this->executor->claim('instance.transfer', 'parent-worker', $source->id, $this->root->id);
    $this->stop = $this->executor->child($this->root, ['worker' => 'parent-worker', 'key' => 'transfer-stop-source'], $source->id)['operation'];
    $this->peer = $source->id;
    $this->captureRefusal = function (): void {
        $this->root->update(['state' => [...$this->root->state, 'executor_runs' => ['transfer-capture' => [
            'started' => true, 'result' => ['error_code' => 'instance.transfer_source_failed']]]]]);
    };
});

it('does not authorize compensation from cancellation alone or an unresolved source capture', function (): void {
    $policy = app(TransferCompensation::class);
    $this->root->update(['cancel_requested_at' => now()]);
    expect($policy->eligible($this->root))->toBeFalse();
    $this->stop->update(['status' => 'succeeded']);
    expect($policy->eligible($this->root))->toBeTrue();
    $this->root->update(['state' => [...$this->root->state, 'executor_runs' => ['transfer-capture' => ['started' => true, 'result' => null]]]]);
    expect($policy->eligible($this->root))->toBeFalse();
    $this->root->update(['state' => [...$this->root->state, 'executor_runs' => []]]);
    $this->stop->forceFill(['child_fingerprint' => str_repeat('f', 64)])->save();
    expect(fn () => $policy->eligible($this->root))->toThrow(ResourceOperationException::class);
});

it('keeps compensation Gateway steps in the child and restores state only after both exact children succeed', function (): void {
    $this->stop->update(['status' => 'succeeded']);
    ($this->captureRefusal)();
    $this->root->update(['cancel_requested_at' => now()]);
    $rollback = $this->executor->child($this->root, ['worker' => 'parent-worker', 'key' => 'transfer-rollback'], $this->peer)['operation'];
    $rollback = $this->executor->claim('instance.transfer_rollback', 'rollback-worker', $this->peer, $rollback->id);
    $this->instance->environmentValues()->create(['env_key' => 'IMPORTED_KEY', 'env_value' => 'synthetic-import']);
    $this->instance->environmentValues()->create(['env_key' => 'RETAINED_KEY', 'env_value' => 'synthetic-retained']);
    $this->transfer->update(['imported_environment_keys' => ['IMPORTED_KEY']]);
    foreach ([$this->transfer->source_node_id, $this->transfer->destination_node_id] as $nodeId) {
        foreach (['vite_port_assignments' => 5173, 'ssr_port_assignments' => 13719] as $table => $port) {
            DB::table($table)->insert(['instance_id' => $this->instance->id, 'node_id' => $nodeId, 'port' => $port]);
        }
    }
    $execute = function (string $key) use ($rollback): array {
        $descriptor = array_find(app(TransferCompensation::class)->context($rollback->refresh())['descriptors'], static fn (array $descriptor): bool => $descriptor['key'] === $key);
        $result = $this->executor->run($rollback, ['worker' => 'rollback-worker', 'key' => $key,
            'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => $descriptor['input']], $this->peer);
        $this->executor->step($rollback, ['worker' => 'rollback-worker', 'key' => $key.'.finish', 'name' => $key, 'status' => 'succeeded', 'result' => $result], $this->peer);

        return $result;
    };
    expect($execute('rollback-record'))->toBe(['recorded' => true, 'seed_abandon_required' => false, 'destination_node_id' => $this->transfer->destination_node_id, 'seed_abandon_node_id' => null]);
    expect(fn () => $execute('rollback-state'))->toThrow(ResourceOperationException::class);
    $discard = $this->executor->child($rollback, ['worker' => 'rollback-worker', 'key' => 'transfer-discard-destination'], $this->peer)['operation'];
    expect($discard->node_id)->toBe($this->transfer->destination_node_id)->and($discard->parent_operation_id)->toBe($rollback->id);
    $discard->update(['status' => 'succeeded', 'result' => ['lifecycle_complete' => true]]);
    $restore = $this->executor->child($rollback, ['worker' => 'rollback-worker', 'key' => 'transfer-restore-source'], $this->peer)['operation'];
    expect($restore->node_id)->toBe($this->transfer->source_node_id)->and($restore->lock_key)->toBeNull()
        ->and($restore->input)->toBe(['instance_id' => $this->instance->id, 'action' => 'transfer_restore']);
    expect(fn () => $execute('rollback-state'))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseHas('vite_port_assignments', ['instance_id' => $this->instance->id, 'node_id' => $this->transfer->destination_node_id]);
    $this->assertDatabaseHas('ssr_port_assignments', ['instance_id' => $this->instance->id, 'node_id' => $this->transfer->destination_node_id]);
    $restore->update(['status' => 'succeeded']);
    expect($execute('rollback-state'))->toMatchArray(['transfer_id' => $this->transfer->id, 'restored' => true]);
    foreach (['vite_port_assignments', 'ssr_port_assignments'] as $table) {
        $this->assertDatabaseMissing($table, ['instance_id' => $this->instance->id, 'node_id' => $this->transfer->destination_node_id]);
        $this->assertDatabaseHas($table, ['instance_id' => $this->instance->id, 'node_id' => $this->transfer->source_node_id]);
    }
    $result = $execute('compensation-proof');
    $this->executor->complete($rollback, ['worker' => 'rollback-worker', 'status' => 'succeeded', 'result' => $result], $this->peer);
    $this->executor->complete($this->root, ['worker' => 'parent-worker', 'status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => []], $this->peer);
    expect($this->transfer->refresh()->recovery_evidence)->toBeNull()->and($this->transfer->current_step->value)->toBe('reserved');
    expect($this->instance->environmentValues()->pluck('env_key')->all())->toBe(['RETAINED_KEY']);
    expect($this->root->refresh()->status->value)->toBe('cancelled')->and($rollback->refresh()->status->value)->toBe('succeeded');
});

it('refuses a fabricated rollback child and any parent effect after cancellation', function (): void {
    $this->stop->update(['status' => 'succeeded']);
    $this->root->update(['cancel_requested_at' => now()]);
    $intent = ['key' => 'transfer-rollback', 'kind' => 'instance.transfer_rollback', 'node_id' => $this->transfer->destination_node_id,
        'input' => ['instance_id' => $this->instance->id, 'transfer_id' => $this->transfer->id]];
    expect(fn () => app(TransferCompensation::class)->admit($this->root, $intent))->toThrow(ResourceOperationException::class);
    expect(fn () => $this->executor->complete($this->root, ['worker' => 'parent-worker', 'status' => 'cancelled', 'result' => []], $this->peer))
        ->toThrow(ResourceOperationException::class);
    expect($this->root->refresh()->status->value)->toBe('running');
});

it('retains the recorded SQLite attempt and blocks rollback cleanup until its seed and export settle', function (string $busyIntent): void {
    $this->stop->update(['status' => 'succeeded']);
    $path = $this->instance->checkout_path.'/database.sqlite';
    $this->transfer->update(['sqlite_source_path' => $path]);
    $this->root->update(['input' => [...$this->root->input, 'sqlite_source_path' => $path]]);
    $seed = app(InstanceSqliteSeedIntent::class);
    expect(fn () => $seed->prepareTransfer($this->root))->toThrow(ResourceOperationException::class);
    expect(DatabaseExecutionIntent::query()->count())->toBe(0);
    $binding = ['step' => 'transfer-capture', 'transport' => 'instance_lifecycle', 'name' => 'instance.transfer-source', 'input' => []];
    $this->root->update(['state' => [...$this->root->state, 'lifecycle_bindings' => ['transfer-capture' => $binding],
        'executor_runs' => ['transfer-capture' => [...$binding, 'result' => ['captured' => true]]]]]);
    $this->root->steps()->create(['position' => 1, 'name' => 'transfer-capture', 'status' => 'succeeded', 'resumable' => false, 'sequence' => 1]);

    expect($seed->prepareTransfer($this->root))->toBe(['prepared' => true, 'seed_required' => true]);
    $descriptor = $seed->transferChild($this->root);
    $intent = DatabaseExecutionIntent::recorded($descriptor['input']['intent_id']);
    expect($intent->parent_operation_id)->toBe($this->root->id);
    expect($intent->payload['projection']['seed_attempt']['id'])->toBe((string) $this->root->id);
    expect($intent->payload['commands']['database-apply']['input']['base'])->toBe($this->transfer->destination_path);
    expect($seed->prepareTransfer($this->root))->toBe(['prepared' => true, 'seed_required' => true]);
    expect($seed->transferChild($this->root))->toBe($descriptor);
    expect(DatabaseExecutionIntent::query()->count())->toBe(3);
    expect(DatabaseExecutionIntent::query()->pluck('parent_operation_id')->unique()->all())->toBe([$this->root->id]);
    ($this->captureRefusal)();
    $this->root->update(['cancel_requested_at' => now()]);
    $rollback = $this->executor->child($this->root, ['worker' => 'parent-worker', 'key' => 'transfer-rollback'], $this->peer)['operation'];
    $rollback = $this->executor->claim('instance.transfer_rollback', 'seed-rollback-worker', $this->peer, $rollback->id);
    $result = $this->executor->run($rollback, ['worker' => 'seed-rollback-worker', 'key' => 'rollback-record',
        'transport' => 'gateway', 'name' => 'instance.transfer-compensation', 'input' => ['action' => 'record']], $this->peer);

    expect($result)->toBe(['recorded' => true, 'seed_abandon_required' => true, 'destination_node_id' => $this->transfer->destination_node_id, 'seed_abandon_node_id' => $this->transfer->destination_node_id]);
    $abandon = DatabaseExecutionIntent::recorded($rollback->refresh()->state['lifecycle_sqlite_abandon']['input']['intent_id']);
    expect($abandon->parent_operation_id)->toBe($rollback->id);
    expect($abandon->payload['projection']['seed_intent_id'])->toBe($intent->id);
    expect($abandon->node_id)->toBe($this->transfer->destination_node_id);
    $source = DatabaseExecutionIntent::recorded($abandon->payload['projection']['children'][0]['input']['intent_id']);
    expect($source->parent_operation_id)->toBe($rollback->id)->and($source->node_id)->toBe($this->transfer->source_node_id);
    expect($this->root->refresh()->state['executor_lease_node_id'])->toBe($this->peer);
    expect(json_encode($result))->not->toContain($path, 'intent_id', 'seed_attempt', 'state_dir');

    $this->executor->step($rollback, ['worker' => 'seed-rollback-worker', 'key' => 'rollback-record.finish',
        'name' => 'rollback-record', 'status' => 'succeeded', 'result' => $result], $this->peer);
    $child = $this->executor->child($rollback, ['worker' => 'seed-rollback-worker', 'key' => 'transfer-abandon-sqlite'], $this->peer)['operation'];
    expect($child->input['intent_id'])->toBe($abandon->id)->and($child->parent_operation_id)->toBe($rollback->id)->and($child->node_id)->toBe($result['seed_abandon_node_id']);
    $busyId = $busyIntent === 'seed' ? $intent->id : $intent->payload['commands']['prepare-target']['export_intent_id'];
    $busyRecord = DatabaseExecutionIntent::recorded($busyId);
    $inflight = Operation::query()->create(['kind' => $busyIntent === 'seed' ? 'database.sqlite_seed' : 'database.sqlite_export',
        'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $busyRecord->node_id,
        'input' => ['intent_id' => $busyId], 'status' => 'running']);
    $execution = app(DatabaseOperationExecution::class);
    $descriptor = $execution->context($child)['descriptors'][0];
    $run = ['worker' => 'seed-cleanup-worker', 'key' => $descriptor['key'], 'transport' => $descriptor['transport'],
        'name' => $descriptor['name'], 'input' => $descriptor['input']];

    expect(fn () => $execution->beforeRun($child, $run))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('sqlite.seed_busy');
    });
    expect(array_column(app(TransferCompensation::class)->childContext($rollback)['children'], 'key'))
        ->toBe(['transfer-abandon-sqlite']);
    $this->assertDatabaseHas('instances', ['id' => $this->instance->id, 'node_id' => $this->transfer->source_node_id]);

    $inflight->update(['status' => 'failed']);
    $execution->beforeRun($child, $run);
    expect($child->refresh()->state['executor_runs'] ?? [])->toBe([]);
})->with(['seed' => ['seed'], 'source export' => ['export']]);
it('recovers an uncertain capture receipt after cancellation before admitting source restoration', function (): void {
    $this->stop->update(['status' => 'succeeded']);
    $execution = app(InstanceLifecycleExecution::class);
    $descriptor = array_find($execution->context($this->root)['descriptors'], static fn (array $entry): bool => $entry['key'] === 'transfer-capture');
    $run = ['worker' => 'parent-worker', 'key' => $descriptor['key'], 'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => $descriptor['input']];
    $this->executor->step($this->root, ['worker' => 'parent-worker', 'key' => 'capture:start', 'name' => 'transfer-capture', 'status' => 'running'], $this->peer);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name): bool => $node->id === $this->transfer->source_node_id && $name === 'instance.transfer-source')
        ->andThrow(new RuntimeException('Lost capture reply'));
    app()->instance(AgentCommandRunner::class, $runner);
    expect(fn () => $this->executor->run($this->root, $run, $this->peer))->toThrow(ResourceOperationException::class);
    $this->root->refresh()->update(['cancel_requested_at' => now()]);
    expect($this->root->state['executor_runs']['transfer-capture']['started'])->toBeTrue()
        ->and($this->root->state['executor_runs']['transfer-capture']['result'])->toBeNull();
    expect(app(TransferCompensation::class)->eligible($this->root))->toBeFalse();
    expect(fn () => $this->executor->complete($this->root, ['worker' => 'parent-worker', 'status' => 'cancelled', 'result' => []], $this->peer))
        ->toThrow(ResourceOperationException::class);
    $identity = $this->root->state['executor_effect_identity'];
    $capture = ['head' => str_repeat('a', 40), 'branch' => 'proof', 'detached' => false,
        'archive' => '/tmp/orbit-transfer-proof-0123456789abcdef.tar', 'common' => null, 'refs' => ['refs/heads/proof']];
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name, array $input): bool => $node->id === $this->transfer->source_node_id
        && $name === 'agent.effect-receipt' && $input === ['command' => 'instance.transfer-source', 'effect_key' => 'op-'.$this->root->id.'.transfer-capture', 'owner' => 'orbit'])
        ->andReturn(['effect_key' => 'op-'.$this->root->id.'.transfer-capture', 'state' => 'succeeded',
            'result' => ['ok' => true, 'data' => $capture], 'sanitized' => true, 'effect_nonce' => $identity['nonce'],
            'effect_created_at' => $identity['created_at'], 'exit_code' => 0, 'finished_at' => '2026-10-29T10:00:00Z']);
    $receipt = $this->executor->receipt($this->root, 'transfer-capture', $this->peer);
    expect($receipt['result'])->toEqual(['captured' => true, 'commit' => str_repeat('a', 40), 'branch' => 'proof'])
        ->and($this->root->refresh()->state['lifecycle_transfer_capture'])->toBe($capture);
    expect($this->root->state['executor_runs']['transfer-capture']['result'])->toBe($receipt['result']);
    $this->executor->step($this->root, ['worker' => 'parent-worker', 'key' => 'capture:finish', 'name' => 'transfer-capture',
        'status' => 'succeeded', 'result' => $receipt['result']], $this->peer);
    $rollback = $this->executor->child($this->root, ['worker' => 'parent-worker', 'key' => 'transfer-rollback'], $this->peer)['operation'];
    expect($rollback->kind)->toBe('instance.transfer_rollback')->and($rollback->node_id)->toBe($this->transfer->source_node_id);
    expect($this->executor->receipt($this->root, 'transfer-capture', $this->peer))->toBe($receipt);
});

it('ends an unknown cancelled capture with recorded pending recovery and no restoration or second capture', function (): void {
    $this->stop->update(['status' => 'succeeded']);
    $execution = app(InstanceLifecycleExecution::class);
    $descriptor = array_find($execution->context($this->root)['descriptors'], static fn (array $entry): bool => $entry['key'] === 'transfer-capture');
    $run = ['worker' => 'parent-worker', 'key' => $descriptor['key'], 'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => $descriptor['input']];
    $this->executor->step($this->root, ['worker' => 'parent-worker', 'key' => 'capture:start', 'name' => 'transfer-capture', 'status' => 'running'], $this->peer);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name): bool => $node->id === $this->peer && $name === 'instance.transfer-source')
        ->andThrow(new RuntimeException('SSH dropped before dispatch'));
    app()->instance(AgentCommandRunner::class, $runner);
    expect(fn () => $this->executor->run($this->root, $run, $this->peer))->toThrow(ResourceOperationException::class);
    $this->root->refresh()->update(['cancel_requested_at' => now()]);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name): bool => $node->id === $this->peer && $name === 'agent.effect-receipt')
        ->andReturn(['effect_key' => 'op-'.$this->root->id.'.transfer-capture', 'state' => 'unknown',
            'result' => null, 'exit_code' => null, 'finished_at' => null]);

    expect($this->executor->receipt($this->root, 'transfer-capture', $this->peer)['state'])->toBe('unknown');
    expect($execution->context($this->root)['result'])->toBe(['rollback_pending' => true]);
    expect(fn () => $this->executor->child($this->root, ['worker' => 'parent-worker', 'key' => 'transfer-rollback'], $this->peer))
        ->toThrow(ResourceOperationException::class);
    $result = ['error_code' => 'operation.cancelled', 'rollback_pending' => true];
    $this->executor->step($this->root, ['worker' => 'parent-worker', 'key' => 'capture:pending', 'name' => 'transfer-capture',
        'status' => 'failed', 'error_code' => 'operation.cancelled', 'result' => $result], $this->peer);
    $this->executor->complete($this->root, ['worker' => 'parent-worker', 'status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => $result], $this->peer);

    expect($this->root->refresh()->status->value)->toBe('cancelled')->and($this->root->lock_key)->toBeNull();
    expect($this->root->state['executor_runs']['transfer-capture']['result'])->toBeNull();
    expect($this->transfer->refresh()->recovery_evidence)->toBe([
        'rollback_pending' => true, 'capture_pending' => true, 'transfer_operation_id' => $this->root->id,
    ]);
    expect($this->transfer->rolled_back_at)->toBeNull();
    expect($this->root->children()->pluck('child_key')->all())->toBe(['transfer-stop-source']);
    $this->assertDatabaseHas('instances', ['id' => $this->instance->id, 'node_id' => $this->peer]);
});

it('refuses a caller pending-recovery claim without its original uncertain capture', function (): void {
    $this->root->update(['cancel_requested_at' => now()]);

    expect(fn () => $this->executor->complete($this->root, ['worker' => 'parent-worker', 'status' => 'cancelled',
        'result' => ['rollback_pending' => true]], $this->peer))->toThrow(ResourceOperationException::class);

    expect($this->root->refresh()->status->value)->toBe('running');
    expect($this->transfer->refresh()->recovery_evidence)->toBeNull();
});

it('refuses cancel after committed cutover before changing the parent or its activation child', function (bool $receiptSaved): void {
    $this->stop->update(['status' => 'succeeded']);
    $this->transfer->update(['cutover_at' => now(), 'current_step' => 'cutover']);
    $this->instance->update(['node_id' => $this->transfer->destination_node_id, 'checkout_path' => $this->transfer->destination_path]);
    if ($receiptSaved) {
        $this->root->update(['state' => [...$this->root->state, 'executor_runs' => ['transfer-cutover' => [
            'transport' => 'gateway', 'name' => 'instance.lifecycle-state',
            'input' => ['action' => 'transfer-cutover', 'operation_id' => $this->root->id],
            'result' => ['cutover' => true, 'transfer_id' => $this->transfer->id]]]]]);
        $this->root->steps()->create(['position' => 1, 'name' => 'transfer-cutover', 'status' => 'succeeded', 'resumable' => true, 'sequence' => 1]);
    }
    $activation = $this->executor->child($this->root, ['worker' => 'parent-worker', 'key' => 'transfer-activate-destination'], $this->peer)['operation'];
    $parentBefore = $this->root->refresh()->getAttributes();
    $childBefore = $activation->getAttributes();

    expect(fn () => app(CancelOperationAction::class)->execute($this->root))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('operation.cancel_refused');
    });

    expect($this->root->refresh()->getAttributes())->toBe($parentBefore);
    expect($activation->refresh()->getAttributes())->toBe($childBefore);
    expect($this->executor->child($this->root, ['worker' => 'parent-worker', 'key' => 'transfer-activate-destination'], $this->peer)['operation']->id)->toBe($activation->id);
})->with(['proven cutover' => [true], 'lost cutover reply' => [false]])
    ->skip(! OperationKinds::supports('cancel_guard'), 'Await the lane 0d cancel guard runtime.');

it('still stamps and cascades pre-cutover cancellation through the shared cancel action', function (): void {
    app(CancelOperationAction::class)->execute($this->root);

    expect($this->root->refresh()->cancel_requested_at)->not->toBeNull();
    expect($this->stop->refresh()->status->value)->toBe('cancelled');
})->skip(! OperationKinds::supports('cancel_guard'), 'Await the lane 0d cancel guard runtime.');

<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceLifecycleCompletion;
use App\Domain\Instances\InstanceSqliteSeedIntent;
use App\Domain\Instances\TransferCompensation;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;

final readonly class CompensationProcessAdmission implements OperationChildAdmission
{
    public function admit(Operation $parent, array $intent): Operation
    {
        return app(StartOperationAction::class)->execute('process.instance_runtime', 'instance', $parent->target_id, $intent['node_id'], $intent['input'], null, null);
    }
}

beforeEach(function (): void {
    $definitions = [
        ...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
        'process.instance_runtime' => ['executor' => 'swamp', 'admission' => CompensationProcessAdmission::class,
            'completion' => InstanceLifecycleCompletion::class, 'runs' => [['transport' => 'gateway', 'name' => 'instance.lifecycle-state']],
            'completion_contract' => ['step' => 'fixture', 'transport' => 'gateway', 'name' => 'instance.lifecycle-state', 'result' => []], 'result_fields' => ['ready']],
    ];
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
    $execute = function (string $key) use ($rollback): array {
        $descriptor = array_find(app(TransferCompensation::class)->context($rollback->refresh())['descriptors'], static fn (array $descriptor): bool => $descriptor['key'] === $key);
        $result = $this->executor->run($rollback, ['worker' => 'rollback-worker', 'key' => $key,
            'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => $descriptor['input']], $this->peer);
        $this->executor->step($rollback, ['worker' => 'rollback-worker', 'key' => $key.'.finish', 'name' => $key, 'status' => 'succeeded', 'result' => $result], $this->peer);

        return $result;
    };
    expect($execute('rollback-record'))->toBe(['recorded' => true, 'seed_abandon_required' => false]);
    expect(fn () => $execute('rollback-state'))->toThrow(ResourceOperationException::class);
    $discard = $this->executor->child($rollback, ['worker' => 'rollback-worker', 'key' => 'transfer-discard-destination'], $this->peer)['operation'];
    expect($discard->node_id)->toBe($this->transfer->destination_node_id)->and($discard->parent_operation_id)->toBe($rollback->id);
    $discard->update(['status' => 'succeeded', 'result' => ['lifecycle_complete' => true]]);
    $restore = $this->executor->child($rollback, ['worker' => 'rollback-worker', 'key' => 'transfer-restore-source'], $this->peer)['operation'];
    expect($restore->node_id)->toBe($this->transfer->source_node_id)->and($restore->lock_key)->toBeNull()
        ->and($restore->input)->toBe(['instance_id' => $this->instance->id, 'action' => 'transfer_restore']);
    expect(fn () => $execute('rollback-state'))->toThrow(ResourceOperationException::class);
    $restore->update(['status' => 'succeeded']);
    expect($execute('rollback-state'))->toMatchArray(['transfer_id' => $this->transfer->id, 'restored' => true]);
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

it('retains a recorded SQLite attempt on the transfer parent and prepares rollback from that exact intent', function (): void {
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

    expect($result)->toBe(['recorded' => true, 'seed_abandon_required' => true]);
    $abandon = DatabaseExecutionIntent::recorded($rollback->refresh()->state['lifecycle_sqlite_abandon']['input']['intent_id']);
    expect($abandon->parent_operation_id)->toBe($rollback->id);
    expect($abandon->payload['projection']['seed_intent_id'])->toBe($intent->id);
    expect($abandon->node_id)->toBe($this->transfer->destination_node_id);
    $source = DatabaseExecutionIntent::recorded($abandon->payload['projection']['children'][0]['input']['intent_id']);
    expect($source->parent_operation_id)->toBe($rollback->id)->and($source->node_id)->toBe($this->transfer->source_node_id);
    expect($this->root->refresh()->state['executor_lease_node_id'])->toBe($this->peer);
    expect(json_encode($result))->not->toContain($path, 'intent_id', 'seed_attempt', 'state_dir');
});

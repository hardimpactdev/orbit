<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Actions\TaskVms\ForgetTaskVmWorkspacesAction;
use App\Actions\TaskVms\StartTaskVmIncusEffectAction;
use App\Actions\TaskVms\StartTaskVmLifecycleOperationAction;
use App\Actions\TaskVms\StartTaskVmPackageCacheMountAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmFamilyChildren;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmLifecycleCommand;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    if (! OperationKinds::supports('receipts')) {
        $this->markTestSkipped('Requires the matching kit v2 receipts slice.');
    }
});

function taskVmEffectFixture(string $verb = 'start'): Operation
{
    app()->instance(OperationKinds::class, new OperationKinds(app()));
    $host = Node::query()->create(['name' => 'dev-host', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.10', 'public_ssh_port' => 22, 'user' => 'orbit', 'wireguard_ip' => '10.44.0.7']);
    $gateway = Node::query()->create(['public_ssh_host' => '192.0.2.11', 'user' => 'orbit', 'name' => 'gateway', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.1']);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['public_ssh_host' => '192.0.2.12', 'user' => 'orbit', 'name' => 'services', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.3']);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    test()->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 '.base64_encode(str_repeat('a', 32)));
    app()->instance(SshKeyProvider::class, $keys);
    $vm = TaskVm::query()->create(['name' => 'tvm-pending', 'provider' => 'incus', 'host_node_id' => $host->id, 'state' => 'provisioning',
        'wireguard_ip' => '10.44.0.129', 'image_fingerprint' => str_repeat('a', 64), 'recipe_identity' => 'sha256:'.str_repeat('b', 64)]);
    $vm->update(['name' => 'tvm-'.$vm->id]);

    return app(StartTaskVmIncusEffectAction::class)->execute($vm, $verb);
}

function taskVmEffectRun(Operation $operation): array
{
    return ['worker' => 'vm-workflow', 'key' => str_replace('_', '-', substr($operation->kind, strlen('task_vm.'))), 'transport' => 'agent', 'name' => 'incus.vm',
        'input' => array_intersect_key($operation->input, array_flip(['vm', 'operation']))];
}

function taskVmEffectClaim(Operation $operation): void
{
    test()->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'vm-workflow'])->assertOk()->assertJsonPath('data.id', $operation->id);
}

it('records exact Incus intent without a PHP executor or queue', function (string $verb): void {
    Queue::fake();
    $operation = taskVmEffectFixture($verb);

    expect($operation->status)->toBe(OperationStatus::Queued);
    expect($operation->input['vm']['name'])->toBe('tvm-'.$operation->target_id);
    expect($operation->input['operation']['kind'])->toBe($verb);
    expect($operation->node_id)->toBe(TaskVm::query()->sole()->host_node_id);
    Queue::assertNothingPushed();
})->with(['create', 'start', 'stop', 'delete']);

it('requires Services identity before claiming a task VM effect', function (): void {
    $operation = taskVmEffectFixture();
    $host = Node::query()->where('name', 'dev-host')->sole();
    $host->accessibleNodes()->attach(Node::query()->where('name', 'gateway')->sole());
    $this->withServerVariables(['REMOTE_ADDR' => $host->wireguard_ip]);

    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'vm-workflow'])
        ->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
});

it('rejects another Node, command, malformed input or changed admitted VM', function (string $change): void {
    $operation = taskVmEffectFixture();
    taskVmEffectClaim($operation);
    $body = taskVmEffectRun($operation);
    if ($change === 'node') {
        $body['node_id'] = 999;
    } elseif ($change === 'agent') {
        $body['name'] = 'incus.image';
    } elseif ($change === 'remote_program') {
        $body['transport'] = 'remote_program';
        $body['name'] = 'gateway.task-workspace-owner';
    } elseif ($change === 'schema') {
        $body['input']['operation']['unexpected'] = true;
    } else {
        $body['input']['vm']['name'] = 'tvm-999';
    }
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);

    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $body)->assertUnprocessable();
})->with(['node', 'agent', 'remote_program', 'schema', 'intent']);

it('refuses missing, forged or nonconfirming completion evidence', function (string $evidence): void {
    $operation = taskVmEffectFixture();
    taskVmEffectClaim($operation);
    $path = "/api/v1/operation-executor/{$operation->id}";
    if ($evidence !== 'missing') {
        if ($evidence === 'false') {
            $runner = Mockery::mock(AgentCommandRunner::class);
            $runner->shouldReceive('run')->once()->andReturn(['ready' => false]);
            app()->instance(AgentCommandRunner::class, $runner);
            $this->postJson($path.'/run', taskVmEffectRun($operation))->assertOk();
        }
        $this->postJson($path.'/step', ['worker' => 'vm-workflow', 'key' => 'finish', 'name' => 'incus-start', 'status' => 'succeeded', 'result' => ['ready' => true]])
            ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    }

    $this->postJson($path.'/complete', ['worker' => 'vm-workflow', 'status' => 'succeeded', 'result' => ['ready' => true]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
})->with(['missing', 'forged', 'false']);

it('redacts secret-bearing receipts and replays proven effects without another Node command', function (): void {
    $operation = taskVmEffectFixture();
    taskVmEffectClaim($operation);
    $path = "/api/v1/operation-executor/{$operation->id}";
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name, array $input): bool => $node->id === $operation->node_id
        && $name === 'incus.vm' && $input['vm']['name'] === $operation->input['vm']['name'])
        ->andReturn(['ready' => true, 'token' => 'must-not-persist', 'environment' => ['PASSWORD' => 'must-not-persist']]);
    app()->instance(AgentCommandRunner::class, $runner);
    foreach (range(1, 2) as $_) {
        $this->postJson($path.'/run', taskVmEffectRun($operation))->assertOk()->assertJsonPath('data', ['ready' => true]);
    }
    $this->postJson($path.'/step', ['worker' => 'vm-workflow', 'key' => 'finish', 'name' => 'incus-start', 'status' => 'succeeded',
        'result' => ['ready' => true, 'token' => 'must-not-persist'], 'output' => 'token=must-not-persist'])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'vm-workflow', 'status' => 'succeeded', 'result' => ['ready' => true, 'token' => 'must-not-persist']])
        ->assertOk()->assertJsonPath('data.status', 'succeeded');

    expect(json_encode($operation->fresh()->toArray()))->not->toContain('must-not-persist');
    expect($operation->fresh()->result)->toBe(['ready' => true]);
});

it('rejects another owned VM hidden inside an admitted Incus child', function (): void {
    $operation = taskVmEffectFixture('delete');
    $input = $operation->input;
    $input['vm']['name'] = 'tvm-999';
    $operation->update(['input' => $input]);
    taskVmEffectClaim($operation);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", taskVmEffectRun($operation))->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('hydrates runtime credentials only after target ownership and keeps references in receipts', function (): void {
    $old = taskVmEffectFixture();
    $old->delete();
    $vm = TaskVm::query()->sole();
    $vm->update(['model_key' => 'test-owned-model-key', 'ai_router_origin' => 'https://router.example.test']);
    $input = ['task_vm_id' => $vm->id, 'origin' => $vm->ai_router_origin, 'key' => ['$secret' => 'task_vm.model_key', 'id' => $vm->id]];
    $operation = app(StartOperationAction::class)->execute('task_vm.model_issue', 'task_vm', $vm->id, $vm->host_node_id, $input, 'test', null);
    $keys = Mockery::mock(ModelKeyIssuer::class);
    $keys->shouldReceive('assertReady')->once();
    $keys->shouldReceive('issue')->once()->with($vm->ai_router_origin, 'test-owned-model-key');
    app()->instance(ModelKeyIssuer::class, $keys);
    taskVmEffectClaim($operation);
    $body = ['worker' => 'vm-workflow', 'key' => 'model-issue', 'transport' => 'task_vm_runtime', 'name' => 'task_vm.runtime', 'input' => [...$input, 'action' => 'issue']];
    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", $body)->assertOk()->assertJsonPath('data', ['ready' => true]);
    expect(json_encode($operation->refresh()->state))->not->toContain('test-owned-model-key');
    expect($operation->state['executor_runs']['model-issue']['input']['key'])->toBe($input['key']);
});

it('refuses a literal runtime key or a reference to another target before hydration', function (string $change): void {
    $old = taskVmEffectFixture();
    $old->delete();
    $vm = TaskVm::query()->sole();
    $vm->update(['model_key' => 'test-owned-model-key', 'ai_router_origin' => 'https://router.example.test']);
    $key = $change === 'literal' ? 'test-owned-model-key' : ['$secret' => 'task_vm.model_key', 'id' => $vm->id + 1];
    $input = ['task_vm_id' => $vm->id, 'origin' => $vm->ai_router_origin, 'key' => $key];
    $operation = app(StartOperationAction::class)->execute('task_vm.model_issue', 'task_vm', $vm->id, $vm->host_node_id, $input, 'test', null);
    $keys = Mockery::mock(ModelKeyIssuer::class);
    $keys->shouldNotReceive('issue');
    app()->instance(ModelKeyIssuer::class, $keys);
    taskVmEffectClaim($operation);
    $this->postJson("/api/v1/operation-executor/{$operation->id}/run", ['worker' => 'vm-workflow', 'key' => 'model-issue', 'transport' => 'task_vm_runtime', 'name' => 'task_vm.runtime', 'input' => [...$input, 'action' => 'issue']])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
})->with(['literal', 'other-owner']);

function taskVmParkParent(): Operation
{
    $previous = taskVmEffectFixture();
    $id = $previous->target_id;
    $previous->delete();
    TaskVm::query()->whereKey($id)->update(['state' => 'ready']);

    return app(StartTaskVmLifecycleOperationAction::class)->execute($id, 'park');
}

it('builds VM child inputs in the Gateway and rejects caller-selected commands or Nodes', function (): void {
    $parent = taskVmParkParent();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'vm-parent'])->assertOk();
    $path = "/api/v1/operation-executor/{$parent->id}/children";
    $this->postJson($path, ['worker' => 'vm-parent', 'key' => 'provider-stop', 'node_id' => $parent->node_id + 100])
        ->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->postJson($path, ['worker' => 'vm-parent', 'key' => 'provider-stop', 'kind' => 'task_vm.incus_delete'])
        ->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->postJson($path, ['worker' => 'vm-parent', 'key' => 'provider-stop', 'input' => ['vm' => ['name' => 'tvm-foreign']]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->postJson($path, ['worker' => 'vm-parent', 'key' => 'provider-stop'])->assertAccepted();
    $child = $parent->children()->sole();
    expect($child->kind)->toBe('task_vm.incus_stop');
    expect($child->node_id)->toBe($parent->node_id);
    expect($child->input['vm']['name'])->toBe(TaskVm::query()->findOrFail($parent->target_id)->name);
    expect(app(OperationChildren::class)->child($parent, 'provider-stop')->id)->toBe($child->id);
});

it('requires every park effect before completing and releases its lease while children run', function (): void {
    $parent = taskVmParkParent();
    $path = "/api/v1/operation-executor/{$parent->id}";
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'vm-parent'])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'vm-parent', 'status' => 'succeeded', 'result' => ['ready' => true]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
    $this->postJson($path.'/children', ['worker' => 'vm-parent', 'key' => 'lifecycle-intent'])->assertAccepted();
    $child = $parent->children()->sole();
    $this->postJson($path.'/wait', ['worker' => 'vm-parent', 'keys' => ['lifecycle-intent']])
        ->assertOk()->assertJsonPath('data.status', 'waiting');
    expect($parent->fresh()->worker)->toBeNull();
    expect($parent->fresh()->lock_key)->toBe('task_vm:'.$parent->target_id);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $child->kind, 'operation_id' => $child->id, 'worker' => 'vm-child'])->assertOk();
    $childPath = "/api/v1/operation-executor/{$child->id}";
    $this->postJson($childPath.'/run', ['worker' => 'vm-child', 'key' => 'lifecycle-intent', 'transport' => 'task_vm_lifecycle', 'name' => 'task_vm.lifecycle',
        'input' => ['task_vm_id' => $parent->target_id, 'action' => 'intent']])->assertOk();
    $this->postJson($childPath.'/step', ['worker' => 'vm-child', 'key' => 'finish', 'name' => 'lifecycle-intent', 'status' => 'succeeded', 'result' => ['ready' => true]])->assertOk();
    $this->postJson($childPath.'/complete', ['worker' => 'vm-child', 'status' => 'succeeded', 'result' => ['ready' => true]])->assertOk();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'vm-parent'])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'vm-parent', 'status' => 'succeeded', 'result' => ['ready' => true]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name, array $input): bool => $node->id === $parent->node_id
        && $name === 'incus.vm' && $input['operation'] === ['kind' => 'stop']
        && $input['__orbit_effect_receipt'] instanceof EffectReceiptMetadata)->andReturn(['ready' => true]);
    app()->instance(AgentCommandRunner::class, $agents);
    $provider = Mockery::mock(TaskVmProvider::class);
    $provider->shouldReceive('observe')->once()->andReturn(new VmObservation(false, null));
    app()->instance(TaskVmProvider::class, $provider);
    foreach (['provider-stop' => ['task_vm.incus_stop', 'incus-stop', 'agent', 'incus.vm'],
        'confirm-stopped' => ['task_vm.confirm_stopped', 'confirm-stopped', 'task_vm_lifecycle', 'task_vm.lifecycle']] as $key => [$kind, $step, $transport, $name]) {
        $this->postJson($path.'/children', ['worker' => 'vm-parent', 'key' => $key])->assertAccepted();
        $next = $parent->children()->where('child_key', $key)->sole();
        $this->postJson('/api/v1/operation-executor/claim', ['kind' => $kind, 'operation_id' => $next->id, 'worker' => 'vm-child'])->assertOk();
        $nextPath = "/api/v1/operation-executor/{$next->id}";
        $input = $key === 'provider-stop' ? ['vm' => $next->input['vm'], 'operation' => ['kind' => 'stop']]
            : ['task_vm_id' => $parent->target_id, 'action' => 'stopped'];
        $this->postJson($nextPath.'/run', ['worker' => 'vm-child', 'key' => $step, 'transport' => $transport, 'name' => $name, 'input' => $input])->assertOk();
        $this->postJson($nextPath.'/step', ['worker' => 'vm-child', 'key' => 'finish', 'name' => $step, 'status' => 'succeeded', 'result' => ['ready' => true]])->assertOk();
        $this->postJson($nextPath.'/complete', ['worker' => 'vm-child', 'status' => 'succeeded', 'result' => ['ready' => true]])->assertOk();
    }
    $this->postJson($path.'/complete', ['worker' => 'vm-parent', 'status' => 'succeeded', 'result' => ['ready' => true]])
        ->assertOk()->assertJsonPath('data.status', 'succeeded');
    expect(TaskVm::query()->findOrFail($parent->target_id)->state)->toBe(TaskVmState::Parked);
});

it('refuses provider deletion before the stamped destruction boundary', function (): void {
    $effect = taskVmEffectFixture('delete');
    taskVmEffectClaim($effect);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson("/api/v1/operation-executor/{$effect->id}/run", taskVmEffectRun($effect))
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
});

it('rechecks workspace preservation before accepting the destruction boundary', function (): void {
    $parent = taskVmParkParent();
    $parent->update(['kind' => 'task_vm.destroy']);
    $vm = TaskVm::query()->findOrFail($parent->target_id);
    $project = Project::query()->create(['name' => 'Preservation', 'slug' => 'preservation', 'repository_url' => 'https://github.com/example/preservation.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Keep workspace', 'brief' => 'Still in use', 'status' => 'running',
        'orchestration' => 'external', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $vm->update(['group_id' => $group->id, 'node_id' => $vm->host_node_id]);
    Instance::query()->create(['project_id' => $project->id, 'node_id' => $vm->node_id, 'name' => 'workspace', 'task_workspace_owner_id' => $group->id,
        'branch' => 'task-work', 'checkout_path' => '/home/orbit/apps/preservation/task-work', 'root' => 'public', 'status' => 'active']);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'vm-parent'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$parent->id}/children", ['worker' => 'vm-parent', 'key' => 'destroy-intent'])->assertAccepted();
    $child = $parent->children()->sole();
    $provider = Mockery::mock(TaskVmProvider::class);
    $provider->shouldReceive('observe')->once()->andReturn(new VmObservation(true, '192.0.2.90'));
    app()->instance(TaskVmProvider::class, $provider);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $child->kind, 'operation_id' => $child->id, 'worker' => 'vm-child'])->assertOk();
    $this->postJson("/api/v1/operation-executor/{$child->id}/run", ['worker' => 'vm-child', 'key' => 'mark-destroying', 'transport' => 'task_vm_lifecycle', 'name' => 'task_vm.lifecycle',
        'input' => ['task_vm_id' => $vm->id, 'action' => 'destroying']])->assertConflict()->assertJsonPath('error.code', 'task_vm.workspace_not_preserved');
    expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
});

it('admits cache preparation for only the ready VM’s Project and retains its selected inventory', function (): void {
    $prior = taskVmEffectFixture();
    $prior->delete();
    $vm = TaskVm::query()->sole();
    $project = Project::query()->create(['name' => 'Cache', 'slug' => 'cache', 'repository_url' => 'https://github.com/example/cache.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Cache workspace', 'brief' => 'Seed selected inventory', 'status' => 'running',
        'orchestration' => 'external', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $vm->update(['group_id' => $group->id, 'state' => 'ready']);
    $snapshot = PackageCacheSnapshot::query()->create(['project_id' => $project->id, 'blobs' => [], 'artifacts' => []]);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    $action = app(StartTaskVmPackageCacheMountAction::class);
    $operation = $action->execute($vm, $project);
    expect($action->execute($vm, $project)->id)->toBe($operation->id);
    expect($operation->input['artifacts_json'])->toBe('{"artifacts":[]}');
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'cache-parent'])->assertOk();
    foreach (['cache-initialize', 'cache-mount', 'cache-seed'] as $key) {
        $this->postJson("/api/v1/operation-executor/{$operation->id}/children", ['worker' => 'cache-parent', 'key' => $key])->assertAccepted();
    }
    $seed = $operation->children()->where('child_key', 'cache-seed')->sole();
    expect($seed->node_id)->toBe($vm->host_node_id);
    expect($seed->input['guest']['name'])->toBe($vm->name);
    $snapshot->update(['artifacts' => [['manager' => 'npm', 'package' => 'left-pad', 'version' => '1.3.0', 'url' => 'https://registry.npmjs.org/left-pad/-/left-pad-1.3.0.tgz', 'integrity' => null, 'key' => 'new-selection']]]);
    expect($seed->fresh()->input['parameters_json'])->toBe('{"artifacts":[]}');
    $foreign = Project::query()->create(['name' => 'Foreign', 'slug' => 'foreign', 'repository_url' => 'https://github.com/example/foreign.git']);
    expect(fn () => $action->execute($vm, $foreign))->toThrow(TaskVmException::class, 'Cache preparation requires this Project');
});

it('retains workspace records until the exact owning Route child succeeds', function (): void {
    $parent = taskVmParkParent();
    $vm = TaskVm::query()->findOrFail($parent->target_id);
    $vm->update(['state' => 'destroying', 'node_id' => $vm->host_node_id]);
    $project = Project::query()->create(['name' => 'Cleanup', 'slug' => 'cleanup', 'repository_url' => 'https://github.com/example/cleanup.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Cleanup workspace', 'brief' => 'Remove the lost workspace', 'status' => 'cancelled',
        'orchestration' => 'external', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $vm->update(['group_id' => $group->id]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $vm->node_id, 'name' => 'workspace', 'task_workspace_owner_id' => $group->id,
        'branch' => 'task-work', 'checkout_path' => '/home/orbit/apps/cleanup/task-work', 'root' => 'public', 'status' => 'reserved']);
    $parent->update(['kind' => 'task_vm.destroy', 'input' => [...$parent->input, 'workspace_routes' => [['route_id' => 321, 'node_id' => $vm->node_id, 'instance_id' => $instance->id]]]]);
    expect(fn () => app(ForgetTaskVmWorkspacesAction::class)->executeProven($vm, $parent))
        ->toThrow(ResourceOperationException::class, 'This parent has no child with that key.');
    $this->assertDatabaseHas('instances', ['id' => $instance->id]);
    $plan = app(TaskVmFamilyChildren::class)->childContext($parent);
    $route = array_find($plan['children'], static fn (array $child): bool => $child['key'] === 'workspace-route-321');
    expect($route)->toBe(['key' => 'workspace-route-321', 'kind' => 'route.destroy', 'node_id' => $vm->node_id, 'input' => ['route_id' => 321, 'offline' => true]]);
});

it('refuses enrollment projection from an unrelated terminal Node Operation', function (): void {
    $effect = taskVmEffectFixture();
    $vm = TaskVm::query()->sole();
    $owner = Operation::query()->create(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $effect->node_id,
        'node_id' => $effect->node_id, 'status' => 'succeeded', 'input' => [], 'result' => ['enrolled' => true]]);
    $effect->update(['kind' => 'task_vm.enroll_node', 'input' => ['task_vm_id' => $vm->id],
        'state' => ['task_vm_owner_operation_id' => $owner->id]]);

    expect(fn () => app(TaskVmLifecycleCommand::class)->run($effect, [], ['task_vm_id' => $vm->id, 'action' => 'enroll']))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.completion_unproven'));
    expect($vm->fresh()->node_id)->toBeNull();
    expect($owner->fresh()->status)->toBe(OperationStatus::Succeeded);
});

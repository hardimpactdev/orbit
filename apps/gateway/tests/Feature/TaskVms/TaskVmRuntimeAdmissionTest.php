<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmRuntimeOperationAction;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmFamilyChildren;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    if (! OperationKinds::supports('children')) {
        $this->markTestSkipped('Requires the matching kit v2 children slice.');
    }
});

function runtimeAdmissionVm(): TaskVm
{
    $definitions = OperationKinds::definitions();
    $definitions['node.add'] = ['executor' => 'swamp', 'runs' => [], 'children' => [['kind' => 'node.bootstrap_probe', 'node_from' => 'operation.node_id']],
        'completion_contract' => ['children_succeeded' => ['node.bootstrap_probe']], 'result_fields' => ['enrolled']];
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $project = Project::query()->create(['name' => 'Runtime proof', 'slug' => 'runtime-proof', 'repository_url' => 'https://github.com/example/runtime.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Runtime', 'brief' => 'Prepare the VM', 'status' => 'reserved',
        'orchestration' => 'external', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $node = Node::query()->create(['name' => 'runtime-vm', 'status' => LifecycleStatus::Active, 'user' => 'orbit', 'public_ssh_host' => '192.0.2.15',
        'wireguard_ip' => '10.44.0.129', 'platform' => 'linux', 'architecture' => 'x86_64']);

    return TaskVm::query()->create(['name' => 'tvm-1', 'provider' => 'incus', 'state' => 'provisioning', 'group_id' => $group->id,
        'node_id' => $node->id, 'wireguard_ip' => $node->wireguard_ip, 'pi_token' => str_repeat('t', 64)]);
}

it('admits runtime once with credential references and no remote execution', function (): void {
    Queue::fake();
    $vm = runtimeAdmissionVm();
    TaskVmSettingsBuilder::bind(['piArtifactPath' => '/artifacts/pi', 'piArtifactSha256' => str_repeat('a', 64), 'piModels' => ['test-model']]);
    $keys = Mockery::mock(ModelKeyIssuer::class);
    $keys->shouldReceive('origin')->twice()->andReturn(TaskVmSettingsBuilder::Origin);
    $keys->shouldReceive('assertReady')->twice();
    $keys->shouldNotReceive('issue');
    app()->instance(ModelKeyIssuer::class, $keys);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);

    $first = app(StartTaskVmRuntimeOperationAction::class)->execute($vm->id);
    $key = $vm->fresh()->model_key;
    $second = app(StartTaskVmRuntimeOperationAction::class)->execute($vm->id);

    expect($second->id)->toBe($first->id);
    expect($first->input['process_id'])->toBe(Process::query()->sole()->id);
    expect(Process::query()->sole()->owner_id)->toBe($vm->node_id);
    expect($first->children()->count())->toBe(0);
    $children = app(OperationChildren::class);
    expect(fn () => $children->admit($first, ['key' => 'pi-process-owner', 'input' => ['process_id' => $first->input['process_id'] + 1]]))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.idempotency_conflict'));
    $child = $children->admit($first, ['key' => 'pi-process-owner'])['operation'];
    expect($child->kind)->toBe('process.create');
    expect($child->target_id)->toBe($first->input['process_id']);
    expect($child->node_id)->toBe($vm->node_id);
    expect($child->parent_operation_id)->toBe($first->id);
    expect($children->admit($first, ['key' => 'pi-process-owner'])['created'])->toBeFalse();
    expect($first->input['model_key'])->toBe(['$secret' => 'task_vm.model_key', 'id' => $vm->id]);
    expect($first->input['token'])->toBe(['$secret' => 'task_vm.pi_token', 'id' => $vm->id]);
    expect(json_encode($first->toArray()))->not->toContain($key)->not->toContain(str_repeat('t', 64));
    expect($vm->fresh()->model_key)->toBe($key);
    Queue::assertNothingPushed();
});

it('refuses an inactive Node or a changed model origin before runtime admission', function (string $cause): void {
    $vm = runtimeAdmissionVm();
    TaskVmSettingsBuilder::bind(['piArtifactPath' => '/artifacts/pi', 'piArtifactSha256' => str_repeat('a', 64), 'piModels' => ['test-model']]);
    $keys = Mockery::mock(ModelKeyIssuer::class);
    if ($cause === 'node') {
        $vm->node->update(['status' => LifecycleStatus::Failed]);
        $keys->shouldNotReceive('origin');
    } else {
        $vm->update(['model_key' => str_repeat('m', 64), 'ai_router_origin' => 'http://10.44.0.9:8317']);
        $keys->shouldReceive('origin')->once()->andReturn(TaskVmSettingsBuilder::Origin);
    }
    $keys->shouldNotReceive('issue');
    app()->instance(ModelKeyIssuer::class, $keys);

    expect(fn () => app(StartTaskVmRuntimeOperationAction::class)->execute($vm->id))
        ->toThrow(fn (TaskVmException $error) => expect($error->errorCode)->toBe($cause === 'node' ? 'task_vm.not_enrolled' : 'task_vm.invalid_config'));
    $this->assertDatabaseCount('operations', 0);
})->with(['node', 'origin']);

it('refuses a conflicting Pi Process before admitting runtime or retaining a new model key', function (): void {
    $vm = runtimeAdmissionVm();
    TaskVmSettingsBuilder::bind(['piArtifactPath' => '/artifacts/pi', 'piArtifactSha256' => str_repeat('a', 64), 'piModels' => ['test-model']]);
    $keys = Mockery::mock(ModelKeyIssuer::class);
    $keys->shouldReceive('origin')->once()->andReturn(TaskVmSettingsBuilder::Origin);
    $keys->shouldReceive('assertReady')->once();
    $keys->shouldNotReceive('issue');
    app()->instance(ModelKeyIssuer::class, $keys);
    Process::query()->create(['owner_type' => Node::class, 'owner_id' => $vm->node_id, 'name' => 'pi-server', 'runtime' => 'systemd',
        'runtime_config' => ['command' => ['/bin/sh']], 'restart_policy' => 'always', 'desired_state' => 'running']);

    expect(fn () => app(StartTaskVmRuntimeOperationAction::class)->execute($vm->id))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('process.name_taken'));
    expect($vm->fresh()->model_key)->toBeNull();
    expect(Process::query()->sole()->runtime_config)->toBe(['command' => ['/bin/sh']]);
    $this->assertDatabaseCount('operations', 0);
});

it('requires the exact owning Process proof without demanding an unused Pi upload', function (): void {
    $vm = runtimeAdmissionVm();
    TaskVmSettingsBuilder::bind(['piArtifactPath' => '/artifacts/pi', 'piArtifactSha256' => str_repeat('a', 64), 'piModels' => ['test-model']]);
    $keys = Mockery::mock(ModelKeyIssuer::class);
    $keys->shouldReceive('origin')->once()->andReturn(TaskVmSettingsBuilder::Origin);
    $keys->shouldReceive('assertReady')->once();
    app()->instance(ModelKeyIssuer::class, $keys);
    $parent = app(StartTaskVmRuntimeOperationAction::class)->execute($vm->id);
    $children = app(OperationChildren::class);
    foreach (['model-key', 'pi-configure', 'pi-process-owner', 'runtime-confirmed'] as $key) {
        $child = $children->admit($parent, ['key' => $key])['operation'];
        $child->update(['status' => 'succeeded', 'result' => ['ready' => true]]);
    }
    app(OperationExecutionContract::class)->assertCompletion($parent, ['ready' => true]);
    app(TaskVmFamilyChildren::class)->assertCompletion($parent, OperationOutcome::succeeded(['ready' => true]));
    expect($parent->children()->pluck('child_key')->all())->not->toContain('pi-upload', 'pi-install');
    $children->child($parent, 'pi-process-owner')->update(['status' => 'failed', 'error_code' => 'process.runtime_failed']);
    expect(fn () => app(OperationExecutionContract::class)->assertCompletion($parent, ['ready' => true]))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.child_unproven'));
});

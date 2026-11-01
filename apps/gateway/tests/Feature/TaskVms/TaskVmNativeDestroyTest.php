<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Actions\TaskVms\StartTaskVmDestroyAction;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskCancelProjection;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Domain\TaskVms\TaskVmDestroyIntent;
use App\Domain\TaskVms\TaskVmDestroyProjection;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Cluster;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\TaskReviewedCommit;
use App\Models\TaskVm;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem', 'swamp.run_token' => 'fixture.fake']);
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    $this->host = Node::query()->create(['name' => 'destroy-host', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'status' => LifecycleStatus::Active,
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)], 'devClusterId' => null]);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 PUBLIC_FIXTURE');
    app()->instance(SshKeyProvider::class, $keys);
    $this->vm = TaskVm::query()->create(['name' => 'pending', 'provider' => 'incus', 'state' => TaskVmState::Failed,
        'host_node_id' => $this->host->id, 'wireguard_ip' => '10.44.0.142', 'pi_token' => 'retained-pi']);
    $this->vm->update(['name' => 'tvm-'.$this->vm->id]);
    $node = Node::query()->create(['name' => $this->vm->name, 'user' => 'orbit', 'public_ssh_host' => '192.0.2.142', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $this->vm->wireguard_ip, 'status' => LifecycleStatus::Failed]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Provisioning]);
    $this->vm->update(['node_id' => $node->id]);
});

it('admits one full-input destroy root without requiring a guest pin or guest worker', function (): void {
    $operation = app(StartTaskVmDestroyAction::class)->execute($this->vm->id);
    expect(app(StartTaskVmDestroyAction::class)->execute($this->vm->id)->id)->toBe($operation->id)
        ->and($operation->input)->not->toHaveKeys(['child_intents', 'private_bindings'])
        ->and($operation->input['removal']['machine_deleted'])->toBeTrue()
        ->and($operation->input['removal']['ssh'])->toBeNull()
        ->and($operation->input['removal']['roles'][0]['ssh'])->toBeNull()
        ->and($operation->input['removal']['roles'][0]['publications'])->toBe([])
        ->and($operation->input['execution_secrets'])->toBe([])
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Destroying)
        ->and($this->vm->fresh()->pi_token)->toBe('retained-pi');
});

it('clears Node records and credentials only after complete native success', function (string $status): void {
    $operation = app(StartTaskVmDestroyAction::class)->execute($this->vm->id);
    $operation->update(['state' => ['swamp_run_id' => '3d8cf201-24cd-4ba5-b0ce-885530dcd327']]);
    $result = ['task_vm_id' => $this->vm->id, 'guest_node_id' => $this->vm->node_id, 'destroyed' => true,
        'cleanup_complete' => true, 'removal' => ['node_id' => $this->vm->node_id, 'removed' => true, 'offline' => true]];
    app(TaskVmDestroyProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'started_at' => '2026-11-01T05:00:00.000Z', 'finished_at' => '2026-11-01T05:00:01.000Z',
        'error_code' => $status === 'succeeded' ? null : 'operation.native_failed', 'result' => $status === 'succeeded' ? $result : []]);
    expect($operation->fresh()->status)->toBe(OperationStatus::from($status));
    if ($status === 'succeeded') {
        expect(Node::query()->find($this->vm->node_id))->toBeNull()
            ->and($this->vm->fresh()->state)->toBe(TaskVmState::Destroyed)
            ->and($this->vm->fresh()->pi_token)->toBeNull()
            ->and($this->vm->fresh()->destroyed_at)->not->toBeNull();
    } else {
        expect(Node::query()->find($this->vm->node_id))->not->toBeNull()
            ->and($this->vm->fresh()->state)->toBe(TaskVmState::Destroying)
            ->and($this->vm->fresh()->pi_token)->toBe('retained-pi')
            ->and($this->vm->fresh()->destroyed_at)->toBeNull();
    }
})->with(['succeeded', 'failed', 'cancelled']);

it('refuses provider absence alone as a complete destroy result', function (): void {
    $operation = app(StartTaskVmDestroyAction::class)->execute($this->vm->id);
    $operation->update(['state' => ['swamp_run_id' => '3d8cf201-24cd-4ba5-b0ce-885530dcd327']]);
    expect(fn () => app(TaskVmDestroyProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'],
        'status' => 'succeeded', 'started_at' => '2026-11-01T05:00:00.000Z', 'finished_at' => now()->toISOString(), 'error_code' => null,
        'result' => ['task_vm_id' => $this->vm->id, 'provider' => ['absent' => true]]]))->toThrow(ResourceOperationException::class);
    expect($this->vm->fresh()->destroyed_at)->toBeNull()->and($this->vm->fresh()->pi_token)->toBe('retained-pi');
});

it('refuses identity drift and a missing UpCloud control Node before effects', function (string $change): void {
    if ($change === 'upcloud') {
        $this->vm->update(['provider' => 'upcloud', 'host_node_id' => null]);
        expect(fn () => app(TaskVmDestroyIntent::class)->capture($this->vm->fresh()))->toThrow(ModelNotFoundException::class);
    } else {
        $operation = app(StartTaskVmDestroyAction::class)->execute($this->vm->id);
        $this->vm->update(['wireguard_ip' => '10.44.0.143']);
        expect(fn () => app(TaskVmDestroyIntent::class)->owned($operation))->toThrow(ResourceOperationException::class);
    }
})->with(['upcloud', 'drift']);

it('refuses protected guest resources before any provider deletion', function (string $resource): void {
    if ($resource === 'schedule') {
        Schedule::query()->create(['target_type' => Node::class, 'target_id' => $this->vm->node_id,
            'host_node_id' => $this->vm->node_id, 'name' => 'retained', 'calendar' => 'daily', 'command' => 'true',
            'timeout_seconds' => 60, 'desired_timer_state' => 'disabled', 'status' => 'active']);
    } else {
        DatabaseServer::query()->create(['node_id' => $this->vm->node_id, 'slug' => 'retained', 'driver' => 'mysql', 'tag' => '8.4', 'root_password' => 'fixture', 'status' => 'active', 'port' => 3306]);
    }
    expect(fn () => app(TaskVmDestroyIntent::class)->capture($this->vm->fresh()))->toThrow(ResourceOperationException::class);
    expect($this->vm->fresh()->state)->toBe(TaskVmState::Failed)->and($this->vm->fresh()->pi_token)->toBe('retained-pi');
})->with(['schedule', 'database']);

it('captures UpCloud destroy through its Gateway and shared API execution copy', function (): void {
    $gateway = Node::query()->create(['name' => 'destroy-gateway', 'public_ssh_host' => '192.0.2.14', 'user' => 'orbit', 'status' => LifecycleStatus::Active]);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->vm->update(['provider' => 'upcloud', 'host_node_id' => null, 'provider_id' => null]);
    app()->instance(UpCloudApi::class, new UpCloudApi(app(TaskVmSettings::class), 'PRIVATE-UPCLOUD-DESTROY'));
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    $input = app(TaskVmDestroyIntent::class)->capture($this->vm->fresh());
    expect($input['node_id'])->toBe($gateway->id)->and($input['provider_input']['provider_id'])->toBeNull()
        ->and($input['execution_secrets']['upcloud'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'task-vm-upcloud-token'])
        ->and(json_encode($input))->not->toContain('PRIVATE-UPCLOUD-DESTROY');
});

function destroyOwnedWorkspace(TaskVm $vm): Instance
{
    $project = Project::query()->create(['name' => 'Destroy workspace', 'slug' => 'destroy-workspace', 'repository_url' => 'https://github.com/acme/workspace']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Cleanup', 'brief' => 'Cleanup',
        'orchestration' => TaskOrchestration::External, 'status' => TaskGroupStatus::Running]);
    $vm->update(['group_id' => $group->id]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $vm->node_id,
        'name' => 'cleanup-workspace', 'task_workspace_owner_id' => $group->id, 'task_workspace_routed' => false,
        'branch_override' => 'task-'.$group->id, 'source_layout' => 'checkout', 'checkout_path' => '/home/orbit/apps/destroy-workspace/workspace',
        'status' => InstanceState::SourceResolved]);
    $group->taskable()->associate($workspace);
    $group->save();

    return $workspace;
}

function destroyCompleteResult(TaskVm $vm): array
{
    return ['task_vm_id' => $vm->id, 'guest_node_id' => $vm->node_id, 'destroyed' => true, 'cleanup_complete' => true,
        'removal' => ['node_id' => $vm->node_id, 'removed' => true, 'offline' => true]];
}

it('captures a Tasks owned workspace without deleting its records or relaxing standalone destruction', function (): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    expect(fn () => app(TaskVmDestroyIntent::class)->capture($this->vm->fresh()))->toThrow(ResourceOperationException::class);
    $captured = DB::transaction(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace));
    expect($captured['task_vm_id'])->toBe($this->vm->id)->and($captured['guest_node_id'])->toBe($workspace->node_id)
        ->and($captured['removal']['machine_deleted'])->toBeTrue()->and($captured['execution_secrets'])->toBe([])
        ->and($workspace->fresh())->not->toBeNull()->and($workspace->fresh()->task_workspace_owner_id)->toBe($this->vm->group_id)
        ->and($this->vm->fresh()->pi_token)->toBe('retained-pi')->and(Operation::query()->count())->toBe(0);
});

it('refuses a foreign workspace before capturing Node removal', function (string $change): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    if ($change === 'project') {
        $other = Project::query()->create(['name' => 'Foreign', 'slug' => 'foreign', 'repository_url' => 'https://github.com/acme/foreign']);
        Instance::query()->whereKey($workspace->id)->update(['project_id' => $other->id]);
    } elseif ($change === 'owner') {
        Instance::query()->whereKey($workspace->id)->update(['task_workspace_owner_id' => null]);
    } elseif ($change === 'node') {
        Instance::query()->whereKey($workspace->id)->update(['node_id' => $this->host->id]);
    } else {
        $copy = $workspace->replicate();
        $copy->name = 'other-workspace';
        $copy->task_workspace_owner_id = null;
        $copy->checkout_path = '/home/orbit/apps/foreign/workspace';
        DB::table('instances')->insert($copy->getAttributes());
    }
    expect(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace))->toThrow(ResourceOperationException::class);
    expect($this->vm->fresh()->node->status)->toBe(LifecycleStatus::Failed)
        ->and($workspace->fresh())->not->toBeNull()->and(Operation::query()->count())->toBe(0);
})->with(['project', 'owner', 'node', 'other-instance']);

it('keeps protected resources strict while capturing the exact workspace', function (string $resource): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    if ($resource === 'schedule') {
        Schedule::query()->create(['target_type' => Node::class, 'target_id' => $this->vm->node_id,
            'host_node_id' => $this->vm->node_id, 'name' => 'retained', 'calendar' => 'daily', 'command' => 'true',
            'timeout_seconds' => 60, 'desired_timer_state' => 'disabled', 'status' => 'active']);
    } else {
        DatabaseServer::query()->create(['node_id' => $this->vm->node_id, 'slug' => 'retained', 'driver' => 'mysql', 'tag' => '8.4', 'root_password' => 'fixture', 'status' => 'active', 'port' => 3306]);
    }
    expect(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace))->toThrow(ResourceOperationException::class);
    expect($workspace->fresh())->not->toBeNull()->and($this->vm->fresh()->pi_token)->toBe('retained-pi');
})->with(['schedule', 'database']);

it('records full child cleanup inside an active Tasks parent without a second Operation', function (): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    $captured = DB::transaction(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace));
    $parent = Operation::query()->create(['kind' => 'tasks.cancel', 'target_type' => 'task_group', 'target_id' => $this->vm->group_id,
        'node_id' => $this->host->id, 'status' => OperationStatus::Running, 'input' => ['vm' => $captured], 'triggered_by' => 'fixture']);
    $result = destroyCompleteResult($this->vm);
    DB::transaction(function () use ($workspace, $captured, $result): void {
        $workspace->delete();
        app(TaskVmDestroyProjection::class)->record($this->vm, $captured, $result);
    });
    expect(Instance::query()->find($workspace->id))->toBeNull()->and(Node::query()->find($result['guest_node_id']))->toBeNull()
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Destroyed)->and($this->vm->fresh()->pi_token)->toBeNull()
        ->and($this->vm->fresh()->destroyed_at)->not->toBeNull()->and(Operation::query()->count())->toBe(1)
        ->and($parent->fresh()->kind)->toBe('tasks.cancel');
});

it('refuses parent recording while its workspace records remain', function (): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    $captured = DB::transaction(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace));
    expect(fn () => app(TaskVmDestroyProjection::class)->record($this->vm, $captured, destroyCompleteResult($this->vm)))->toThrow(ResourceOperationException::class);
    expect($workspace->fresh())->not->toBeNull()->and($this->vm->fresh()->pi_token)->toBe('retained-pi')
        ->and($this->vm->fresh()->destroyed_at)->toBeNull();
});

it('rolls workspace deletion back on changed identity or incomplete child cleanup', function (string $change): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    $captured = DB::transaction(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace));
    $result = destroyCompleteResult($this->vm);
    if ($change === 'identity') {
        $this->vm->update(['provider_id' => 'another-machine']);
    } elseif ($change === 'incomplete') {
        $result['cleanup_complete'] = false;
    } elseif ($change === 'wrong-node') {
        $result['removal']['node_id']++;
    } elseif ($change === 'snapshot') {
        $captured['node_removal']['node_id'] = $this->host->id;
    } else {
        DatabaseServer::query()->create(['node_id' => $this->vm->node_id, 'slug' => 'new-resource', 'driver' => 'mysql', 'tag' => '8.4', 'root_password' => 'fixture', 'status' => 'active', 'port' => 3306]);
    }
    expect(fn () => DB::transaction(function () use ($workspace, $captured, $result): void {
        $workspace->delete();
        app(TaskVmDestroyProjection::class)->record($this->vm, $captured, $result);
    }))->toThrow(ResourceOperationException::class);
    expect(Instance::query()->find($workspace->id))->not->toBeNull()->and(Node::query()->find($this->vm->node_id))->not->toBeNull()
        ->and($this->vm->fresh()->pi_token)->toBe('retained-pi')->and($this->vm->fresh()->destroyed_at)->toBeNull();
})->with(['identity', 'incomplete', 'wrong-node', 'snapshot', 'new-resource']);

it('does not bypass Route guards for the exact owned workspace', function (): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    Route::query()->create(['project_id' => $workspace->project_id, 'node_id' => $workspace->node_id,
        'domain' => 'retained-workspace.orbit', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    expect(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace))->toThrow(ResourceOperationException::class);
    expect($workspace->fresh())->not->toBeNull()->and($this->vm->fresh()->node->status)->toBe(LifecycleStatus::Failed)
        ->and(Route::query()->count())->toBe(1);
});

it('refuses competing VM Node and Tasks roots before workspace capture', function (string $target): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    Operation::query()->create(['kind' => 'fixture.active', 'target_type' => $target,
        'target_id' => match ($target) {
            'task_vm' => $this->vm->id, 'node' => $this->vm->node_id, default => $this->vm->group_id
        },
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'fixture']);
    expect(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    expect($workspace->fresh())->not->toBeNull()->and($this->vm->fresh()->node->status)->toBe(LifecycleStatus::Failed);
})->with(['task_vm', 'node', 'task_group']);

function destroyCleanupParent(TaskVm $vm, array $captured, string $kind = 'tasks.cancel'): Operation
{
    return Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $vm->group_id,
        'node_id' => $captured['node_id'], 'status' => OperationStatus::Running, 'input' => $captured, 'triggered_by' => 'fixture']);
}

it('validates each ending parent at dispatch without recapture or execution copy writes', function (string $kind): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    $gateway = Node::query()->create(['name' => 'dispatch-gateway', 'public_ssh_host' => '192.0.2.14', 'user' => 'orbit', 'status' => LifecycleStatus::Active]);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->vm->update(['provider' => 'upcloud', 'host_node_id' => null]);
    app()->instance(UpCloudApi::class, new UpCloudApi(app(TaskVmSettings::class), 'PRIVATE-DISPATCH-UPCLOUD'));
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    $captured = DB::transaction(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace));
    $parent = destroyCleanupParent($this->vm, $captured, $kind);
    $before = [$this->vm->fresh()->getRawOriginal(), $workspace->fresh()->getRawOriginal(), $this->vm->fresh()->node->getRawOriginal()];
    expect($socket->sent)->toHaveCount(1);
    $owned = app(TaskVmDestroyIntent::class)->ownedForWorkspace($this->vm, $workspace, $captured, $parent->id);
    expect($owned->id)->toBe($this->vm->id)->and($socket->sent)->toHaveCount(1)
        ->and([$this->vm->fresh()->getRawOriginal(), $workspace->fresh()->getRawOriginal(), $this->vm->fresh()->node->getRawOriginal()])->toBe($before)
        ->and($parent->fresh()->input)->toBe($captured);
})->with(['tasks.cancel', 'tasks.maintenance_finish']);

it('refuses VM guest or workspace drift before dispatch even with stale model objects', function (string $change): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    $captured = DB::transaction(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace));
    $parent = destroyCleanupParent($this->vm, $captured);
    if (in_array($change, ['provider_id', 'name', 'wireguard_ip'], true)) {
        TaskVm::query()->whereKey($this->vm->id)->update([$change => $change === 'wireguard_ip' ? '10.44.0.199' : 'changed']);
    } elseif ($change === 'model-key') {
        $this->vm->fresh()->update(['model_key' => 'late-model-key']);
    } elseif (in_array($change, ['provider', 'guest', 'group', 'host-id'], true)) {
        TaskVm::query()->whereKey($this->vm->id)->update(match ($change) {
            'provider' => ['provider' => 'upcloud'], 'guest' => ['node_id' => null], 'group' => ['group_id' => null],
            default => ['host_node_id' => $this->vm->node_id],
        });
    } elseif ($change === 'guest-name' || $change === 'guest-wg') {
        Node::query()->whereKey($this->vm->node_id)->update([$change === 'guest-name' ? 'name' : 'wireguard_ip' => $change === 'guest-name' ? 'changed' : '10.44.0.199']);
    } elseif ($change === 'host') {
        $this->host->update(['status' => LifecycleStatus::Failed]);
    } elseif ($change === 'owner') {
        Instance::query()->whereKey($workspace->id)->update(['task_workspace_owner_id' => null]);
    } elseif ($change === 'path') {
        Instance::query()->whereKey($workspace->id)->update(['checkout_path' => '/another/workspace']);
    } else {
        Instance::query()->whereKey($workspace->id)->update(['task_workspace_routed' => true]);
    }
    expect(fn () => app(TaskVmDestroyIntent::class)->ownedForWorkspace($this->vm, $workspace, $captured, $parent->id))->toThrow(ResourceOperationException::class);
    expect($workspace->fresh())->not->toBeNull()->and($this->vm->fresh()->pi_token)->toBe('retained-pi');
})->with(['provider_id', 'name', 'wireguard_ip', 'provider', 'guest', 'group', 'host-id', 'model-key', 'guest-name', 'guest-wg', 'host', 'owner', 'path', 'routed']);

it('only excludes its verified nonterminal ending parent from busy checks', function (string $change): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    $captured = DB::transaction(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace));
    $parent = destroyCleanupParent($this->vm, $captured);
    if ($change === 'kind') {
        $parent->update(['kind' => 'tasks.check']);
    } elseif ($change === 'group') {
        $parent->update(['target_id' => $this->vm->group_id + 1]);
    } elseif ($change === 'target') {
        $parent->update(['target_type' => 'task_vm']);
    } elseif ($change === 'node') {
        $parent->update(['node_id' => null]);
    } elseif ($change === 'terminal') {
        $parent->update(['status' => OperationStatus::Succeeded]);
    } else {
        Operation::query()->create(['kind' => 'fixture.active', 'target_type' => $change === 'competing-node' ? 'node' : $change,
            'target_id' => match ($change) {
                'competing-node' => $this->vm->node_id, 'task_vm' => $this->vm->id, default => $this->vm->group_id
            },
            'status' => OperationStatus::Queued, 'input' => [], 'triggered_by' => 'fixture']);
    }
    expect(fn () => app(TaskVmDestroyIntent::class)->ownedForWorkspace($this->vm, $workspace, $captured, $parent->id))->toThrow(ResourceOperationException::class);
    expect($workspace->fresh())->not->toBeNull()->and($this->vm->fresh()->destroyed_at)->toBeNull();
})->with(['kind', 'group', 'target', 'node', 'terminal', 'competing-node', 'task_vm', 'task_group']);

it('retains preservation and new foreign resource refusals at dispatch', function (string $resource): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    $captured = DB::transaction(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace));
    $parent = destroyCleanupParent($this->vm, $captured);
    if ($resource === 'database') {
        DatabaseServer::query()->create(['node_id' => $this->vm->node_id, 'slug' => 'late', 'driver' => 'mysql', 'tag' => '8.4', 'root_password' => 'fixture', 'status' => 'active', 'port' => 3306]);
    } elseif ($resource === 'route') {
        Route::query()->create(['project_id' => $workspace->project_id, 'node_id' => $workspace->node_id,
            'domain' => 'late-workspace.orbit', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    } else {
        $group = $this->vm->fresh()->group;
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('c', 40), 'source' => TaskReviewedCommitSource::OrbitPush]);
    }
    expect(fn () => app(TaskVmDestroyIntent::class)->ownedForWorkspace($this->vm, $workspace, $captured, $parent->id))->toThrow(ResourceOperationException::class);
    expect($workspace->fresh())->not->toBeNull()->and($this->vm->fresh()->pi_token)->toBe('retained-pi');
})->with(['database', 'route', 'unpublished-work']);

it('projects the complete Tasks ending parent without deleting records at admission', function (): void {
    enable_operation_queue();
    app(TaskExtensionState::class)->enable();
    $workspace = destroyOwnedWorkspace($this->vm);
    $group = Task::topLevel()->findOrFail($this->vm->group_id);
    $run = '8ba8f6c9-0a5e-44da-9710-4bde40935894';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-cancel', 'runId' => $run]]];
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(TaskCancelIntent::class)->start($group, 'fixture', null);
    expect($workspace->fresh())->not->toBeNull()->and($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($operation->kind)->toBe('tasks.cancel')->and(Operation::query()->count())->toBe(1);
    $vm = destroyCompleteResult($this->vm);
    $data = ['run_id' => $run, 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'error_code' => null,
        'result' => ['group_id' => $group->id, 'instance_id' => $workspace->id, 'cleanup_complete' => true, 'vm' => [...$vm, 'cleanup_complete' => false]]];
    expect(fn () => app(TaskCancelProjection::class)->apply($operation, $data))->toThrow(ResourceOperationException::class);
    expect($workspace->fresh())->not->toBeNull()->and($group->fresh()->taskable_id)->toBe($workspace->id);
    $data['result']['vm'] = $vm;
    app(TaskCancelProjection::class)->apply($operation->fresh(), $data);
    expect(Instance::query()->find($workspace->id))->toBeNull()->and($group->fresh()->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($group->fresh()->taskable_id)->toBeNull()->and($this->vm->fresh()->state)->toBe(TaskVmState::Destroyed);
});

it('captures cancellation of its exclusive routed workspace without touching the stopped guest', function (): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    $workspace->update(['task_workspace_routed' => true]);
    $route = Route::query()->create(['project_id' => $workspace->project_id, 'node_id' => $workspace->node_id,
        'domain' => 'owned-workspace.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $workspace->id, 'position' => 0]);

    $captured = DB::transaction(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace));

    expect($captured['workspace_routes'])->toHaveCount(1)
        ->and($captured['workspace_routes'][0]['route_id'])->toBe($route->id)
        ->and($captured['workspace_routes'][0]['route_mutations'])->toHaveKeys(['artifacts', 'forward'])->not->toHaveKey('restore')
        ->and(array_column($captured['workspace_routes'][0]['route_mutations']['forward']['publications'], 'node_id'))->not->toContain($workspace->node_id)
        ->and($route->fresh()->status->value)->toBe('retiring')
        ->and($workspace->fresh())->not->toBeNull();
});

it('refuses shared and foreign workspace Routes without recording withdrawal', function (string $change): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    $workspace->update(['task_workspace_routed' => true]);
    $foreign = Project::query()->create(['name' => 'Foreign route', 'slug' => 'foreign-route', 'repository_url' => 'https://example.test/foreign']);
    if ($change === 'shared') {
        $cluster = Cluster::query()->create(['name' => 'Shared cleanup', 'tld' => 'shared.test', 'state' => 'active']);
        foreach ([$this->vm->node, $this->host] as $node) {
            $node->update(['cluster_id' => $cluster->id, 'status' => LifecycleStatus::Active]);
            $node->roles()->delete();
            $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
        }
        $route = Route::query()->create(['project_id' => $workspace->project_id, 'cluster_id' => $cluster->id,
            'domain' => 'shared-workspace.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $workspace->id, 'position' => 0]);
        $other = Instance::query()->create(['project_id' => $workspace->project_id, 'node_id' => $this->host->id, 'name' => 'shared-other', 'checkout_path' => '/home/orbit/apps/shared-other', 'status' => InstanceState::Reserved]);
        $route->targets()->create(['instance_id' => $other->id, 'position' => 1]);
    } else {
        $route = Route::query()->create(['project_id' => $foreign->id, 'node_id' => $workspace->node_id,
            'domain' => 'foreign-workspace.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    }
    $priorStatus = $this->vm->fresh()->node->status;

    expect(fn () => DB::transaction(fn () => app(TaskVmDestroyIntent::class)->captureForWorkspace($this->vm->fresh(), $workspace)))->toThrow(ResourceOperationException::class);
    expect($route->fresh()->status->value)->toBe('pending')->and($workspace->fresh())->not->toBeNull()
        ->and($this->vm->fresh()->node->status)->toBe($priorStatus);
})->with(['shared', 'project']);

it('cleans a completed group residual workspace and keeps it completed', function (bool $workspacePresent): void {
    enable_operation_queue();
    app(TaskExtensionState::class)->enable();
    $workspace = destroyOwnedWorkspace($this->vm);
    $group = Task::topLevel()->findOrFail($this->vm->group_id);
    $group->update(['status' => TaskGroupStatus::Completed]);
    if (! $workspacePresent) {
        $group->taskable()->dissociate();
        $group->save();
        $workspace->delete();
    } else {
        $workspace->update(['task_workspace_routed' => true]);
        $route = Route::query()->create(['project_id' => $workspace->project_id, 'node_id' => $workspace->node_id,
            'domain' => 'completed-workspace.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $workspace->id, 'position' => 0]);
    }
    $run = '8ba8f6c9-0a5e-44da-9710-4bde40935894';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-cancel', 'runId' => $run]]];
    app()->instance(WebSocketClient::class, $socket);

    $operation = app(TaskCancelIntent::class)->start($group, 'fixture', null);

    expect(app(TaskCancelIntent::class)->input($operation)['intent']['task_vm_id'])->toBe($this->vm->id);
    app(TaskCancelProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'error_code' => null,
        'result' => ['group_id' => $group->id, 'instance_id' => $workspacePresent ? $workspace->id : null, 'cleanup_complete' => true, 'vm' => destroyCompleteResult($this->vm)]]);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed)->and($group->fresh()->taskable_id)->toBeNull()
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Destroyed)
        ->and(Instance::query()->find($workspace->id))->toBeNull()->and(Route::query()->count())->toBe(0);
})->with(['workspace' => true, 'vm-only' => false]);

it('fails the guest Node of incomplete cleanup and lets a later admission claim it again', function (string $status, ?string $code): void {
    $operation = app(StartTaskVmDestroyAction::class)->execute($this->vm->id);
    $operation->update(['state' => ['swamp_run_id' => '3d8cf201-24cd-4ba5-b0ce-885530dcd327']]);
    $node = Node::query()->findOrFail($this->vm->node_id);
    expect($node->status)->toBe(LifecycleStatus::Removing);

    app(TaskVmDestroyProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'started_at' => '2026-11-01T05:00:00.000Z', 'finished_at' => '2026-11-01T05:00:01.000Z', 'error_code' => $code, 'result' => []]);

    expect($node->fresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($node->fresh()->failed_step)->toBe('remove:operation')
        ->and($node->fresh()->error_code)->toBe($code ?? 'node.remove_failed')
        ->and($node->roles()->sole()->status)->toBe(LifecycleStatus::Failed)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Destroying)
        ->and($this->vm->fresh()->error_code)->toBe($code ?? 'task_vm.destroy_failed');
    expect($operation->fresh()->status)->toBe($status === 'cancelled' ? OperationStatus::Cancelled : OperationStatus::Failed);
    $again = app(StartTaskVmDestroyAction::class)->execute($this->vm->id);
    expect($again->id)->not->toBe($operation->id)->and($node->fresh()->status)->toBe(LifecycleStatus::Removing);
})->with([['failed', 'operation.native_failed'], ['cancelled', null], ['interrupted', 'operation.native_failed']]);

it('holds destroy admission after expiry until exact native terminal settlement', function (): void {
    $this->freezeSecond();
    $operation = app(StartTaskVmDestroyAction::class)->execute($this->vm->id);
    $operation->update(['state' => ['swamp_run_id' => '3d8cf201-24cd-4ba5-b0ce-885530dcd327'], 'status' => OperationStatus::Running, 'started_at' => now()]);
    $this->travel(2700)->seconds();

    expect(app(ExpireOperationAction::class)->execute($operation->fresh())->error_code)->toBe('operation.timed_out');
    $node = Node::query()->findOrFail($this->vm->node_id);
    expect($node->status)->toBe(LifecycleStatus::Failed)->and($node->error_code)->toBe('operation.timed_out')
        ->and($node->failed_step)->toBe('remove:operation')
        ->and($this->vm->fresh()->error_code)->toBe('operation.timed_out');
    expect(fn () => app(StartTaskVmDestroyAction::class)->execute($this->vm->id))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    expect($node->fresh()->status)->toBe(LifecycleStatus::Failed);
    $this->assertDatabaseCount('operations', 1);
    settleExpiredTaskVmOperation($operation->fresh());
    expect($operation->fresh()->lock_key)->toBeNull()->and($operation->fresh()->error_code)->toBe('operation.timed_out');
    expect(app(StartTaskVmDestroyAction::class)->execute($this->vm->id)->id)->not->toBe($operation->id);
});

it('fails the guest Node when Tasks cleanup with a VM fails or expires', function (string $end): void {
    $this->freezeSecond();
    enable_operation_queue();
    app(TaskExtensionState::class)->enable();
    $workspace = destroyOwnedWorkspace($this->vm);
    $group = Task::topLevel()->findOrFail($this->vm->group_id);
    $run = '8ba8f6c9-0a5e-44da-9710-4bde40935894';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-cancel', 'runId' => $run]]];
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(TaskCancelIntent::class)->start($group, 'fixture', null);
    $node = Node::query()->findOrFail($this->vm->node_id);
    expect($node->status)->toBe(LifecycleStatus::Removing);

    if ($end === 'expired') {
        $operation->update(['state' => [...$operation->fresh()->state, 'swamp_run_id' => $run], 'status' => OperationStatus::Running, 'started_at' => now()]);
        $this->travel(3900)->seconds();
        expect(app(ExpireOperationAction::class)->execute($operation->fresh())->error_code)->toBe('operation.timed_out');
    } else {
        app(TaskCancelProjection::class)->apply($operation->fresh(), ['run_id' => $run, 'status' => $end, 'started_at' => now()->toISOString(),
            'finished_at' => now()->toISOString(), 'error_code' => $end === 'cancelled' ? null : 'operation.native_failed', 'result' => []]);
    }

    expect($node->fresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($node->fresh()->error_code)->toBe(match ($end) {
            'expired' => 'operation.timed_out', 'cancelled' => 'node.remove_failed', default => 'operation.native_failed'
        })
        ->and($workspace->fresh())->not->toBeNull()->and($group->fresh()->taskable_id)->toBe($workspace->id);
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running);
    if ($end === 'expired') {
        expect(fn () => app(TaskCancelIntent::class)->start($group, 'fixture', null))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
        expect($node->fresh()->status)->toBe(LifecycleStatus::Failed);
        $this->assertDatabaseCount('operations', 1);
        settleExpiredTaskVmOperation($operation->fresh());
        expect($operation->fresh()->lock_key)->toBeNull()->and($operation->fresh()->error_code)->toBe('operation.timed_out');
    } else {
        expect($operation->fresh()->status)->toBe($end === 'cancelled' ? OperationStatus::Cancelled : OperationStatus::Failed)
            ->and($operation->fresh()->lock_key)->toBeNull();
    }
    expect(app(TaskCancelIntent::class)->start($group->fresh(), 'fixture', null)->id)->not->toBe($operation->id);
})->with(['failed', 'cancelled', 'interrupted', 'expired']);

it('readmits each destroying VM in the sweep even when admission refuses another one', function (): void {
    Log::spy();
    $this->vm->update(['state' => TaskVmState::Destroying]);
    $busy = Operation::query()->create(['kind' => 'task_vm.resume', 'target_type' => 'task_vm', 'target_id' => $this->vm->id, 'node_id' => $this->host->id,
        'status' => OperationStatus::Running, 'sequence' => 1, 'input' => [], 'state' => []]);
    $other = TaskVm::query()->create(['name' => 'pending-other', 'provider' => 'incus', 'state' => TaskVmState::Destroying,
        'host_node_id' => $this->host->id, 'wireguard_ip' => '10.44.0.143']);
    $other->update(['name' => 'tvm-'.$other->id]);

    app()->call([new SweepTaskVmHosts, 'handle']);

    expect(Operation::query()->where('kind', 'task_vm.destroy')->where('target_id', $other->id)->exists())->toBeTrue()
        ->and(Operation::query()->where('kind', 'task_vm.destroy')->where('target_id', $this->vm->id)->exists())->toBeFalse();
    Log::shouldHaveReceived('warning')->with('A destroying task VM was not readmitted.', ['task_vm_id' => $this->vm->id, 'error_code' => 'operation.target_busy', 'details' => ['operation_id' => $busy->id]])->once();
});

function settleExpiredTaskVmOperation(Operation $operation): void
{
    $history = new FakeRoutePublicationSocket;
    $definition = app(DirectOperation::class)->definition($operation->kind);
    $history->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => $definition['workflow'], 'status' => 'interrupted',
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
    ]]]];
    $startSocket = app(WebSocketClient::class);
    app()->instance(WebSocketClient::class, $history);
    config(['swamp.read_token' => 'fixture.read']);

    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $operation->state['swamp_run_id']], $definition['terminal_result']);

    app()->instance(WebSocketClient::class, $startSocket);
}

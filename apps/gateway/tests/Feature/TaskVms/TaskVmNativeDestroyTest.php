<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmDestroyAction;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\TaskVms\TaskVmDestroyIntent;
use App\Domain\TaskVms\TaskVmDestroyProjection;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
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

it('refuses a foreign or routed workspace before capturing Node removal', function (string $change): void {
    $workspace = destroyOwnedWorkspace($this->vm);
    if ($change === 'project') {
        $other = Project::query()->create(['name' => 'Foreign', 'slug' => 'foreign', 'repository_url' => 'https://github.com/acme/foreign']);
        Instance::query()->whereKey($workspace->id)->update(['project_id' => $other->id]);
    } elseif ($change === 'owner') {
        Instance::query()->whereKey($workspace->id)->update(['task_workspace_owner_id' => null]);
    } elseif ($change === 'node') {
        Instance::query()->whereKey($workspace->id)->update(['node_id' => $this->host->id]);
    } elseif ($change === 'routed') {
        Instance::query()->whereKey($workspace->id)->update(['task_workspace_routed' => true]);
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
})->with(['project', 'owner', 'node', 'routed', 'other-instance']);

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

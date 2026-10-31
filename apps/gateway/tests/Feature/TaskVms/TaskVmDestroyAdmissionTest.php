<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmDestroyAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

function taskVmRetiredFixture(): TaskVm
{
    $host = Node::query()->create(['name' => 'host', 'user' => 'orbit', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'status' => LifecycleStatus::Active]);
    $gateway = Node::query()->create(['name' => 'gateway', 'user' => 'orbit', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.11', 'wireguard_ip' => '10.44.0.2', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node = Node::query()->create(['name' => 'retired-guest', 'user' => 'orbit', 'architecture' => 'x86_64', 'public_ssh_host' => '10.251.77.2', 'wireguard_ip' => '10.44.0.129', 'status' => LifecycleStatus::Failed]);
    TaskVmSettingsBuilder::bind(['enabled' => false, 'hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 '.base64_encode(str_repeat('a', 32)));
    app()->instance(SshKeyProvider::class, $keys);
    $provider = Mockery::mock(TaskVmProvider::class);
    $provider->shouldNotReceive('destroy', 'observe', 'stop');
    app()->instance(TaskVmProvider::class, $provider);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);

    $host->update(['ssh_host_fingerprint' => 'SHA256:host']);
    $gateway->update(['ssh_host_fingerprint' => 'SHA256:gateway']);
    config(['swamp_workers.nodes' => [$host->id, $gateway->id], 'dependency_detection.services_node_id' => $gateway->id, 'swamp.run_token' => 'fixture.fake']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-task-vm-destroy', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    $vm = TaskVm::query()->create(['name' => 'retired-guest', 'provider' => 'incus', 'host_node_id' => $host->id, 'node_id' => $node->id,
        'wireguard_ip' => $node->wireguard_ip, 'state' => TaskVmState::Destroying, 'pi_token' => 'test-pi-token']);
    $vm->update(['name' => 'tvm-'.$vm->id]);
    $node->update(['name' => $vm->name]);

    return $vm;
}

it('records one native destruction intent without deleting or waiting', function (): void {
    Queue::fake();
    $vm = taskVmRetiredFixture();

    app(StartTaskVmDestroyAction::class)->execute($vm->id);
    app(StartTaskVmDestroyAction::class)->execute($vm->id);

    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('task_vm.destroy')
        ->and($operation->status)->toBe(OperationStatus::Running)
        ->and($operation->target_id)->toBe($vm->id)
        ->and($operation->node_id)->toBe($vm->host_node_id)
        ->and($operation->input['guest_node_id'])->toBe($vm->node_id)
        ->and($operation->state)->not->toHaveKey('child_intents')
        ->and($vm->fresh()->state)->toBe(TaskVmState::Destroying)
        ->and($vm->fresh()->destroyed_at)->toBeNull()
        ->and($vm->fresh()->pi_token)->toBe('test-pi-token');
    expect(Node::query()->whereKey($vm->node_id)->exists())->toBeTrue();
    Queue::assertNothingPushed();
});

it('ignores an already destroyed or missing row at native admission', function (bool $missing): void {
    $vm = taskVmRetiredFixture();
    if ($missing) {
        $vm->delete();
    } else {
        $vm->update(['state' => TaskVmState::Destroyed]);
    }

    app(StartTaskVmDestroyAction::class)->execute($vm->id);

    expect(Operation::query()->count())->toBe(0);
})->with(['missing' => true, 'destroyed' => false]);

it('admits retired VM cleanup directly from the host sweep without queuing a PHP destroyer', function (): void {
    Queue::fake();
    $vm = taskVmRetiredFixture();

    app()->call([new SweepTaskVmHosts, 'handle']);

    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('task_vm.destroy')
        ->and($operation->target_id)->toBe($vm->id)
        ->and($operation->status)->toBe(OperationStatus::Running)
        ->and($vm->fresh()->state)->toBe(TaskVmState::Destroying);
    Queue::assertNothingPushed();
});

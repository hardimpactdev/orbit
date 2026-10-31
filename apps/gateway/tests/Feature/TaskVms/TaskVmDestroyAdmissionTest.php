<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TaskVmSettingsBuilder;

function taskVmRetiredFixture(): TaskVm
{
    $host = Node::query()->create(['name' => 'host', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'status' => LifecycleStatus::Active]);
    $gateway = Node::query()->create(['name' => 'gateway', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.11', 'wireguard_ip' => '10.44.0.2', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node = Node::query()->create(['name' => 'retired-guest', 'user' => 'orbit', 'public_ssh_host' => '10.251.77.2', 'wireguard_ip' => '10.44.0.129', 'status' => LifecycleStatus::Failed]);
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

    return TaskVm::query()->create(['name' => 'retired-guest', 'provider' => 'incus', 'host_node_id' => $host->id, 'node_id' => $node->id,
        'wireguard_ip' => $node->wireguard_ip, 'state' => TaskVmState::Destroying, 'pi_token' => 'test-pi-token']);
}

it('drains an old destruction job into one intent without deleting or waiting', function (): void {
    Queue::fake();
    $vm = taskVmRetiredFixture();

    app()->call([new DestroyTaskVm($vm->id), 'handle']);
    app()->call([new DestroyTaskVm($vm->id), 'handle']);

    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('task_vm.destroy')
        ->and($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->target_id)->toBe($vm->id)
        ->and($operation->node_id)->toBe($vm->host_node_id)
        ->and($operation->state['child_intents'])->toBe([
            ['key' => 'node-removal', 'kind' => 'node.remove', 'node_id' => $vm->node_id,
                'input' => ['caller_node_id' => Node::query()->where('name', 'gateway')->sole()->id, 'offline' => true, 'force' => true, 'deleted' => true]],
        ])
        ->and($operation->children()->count())->toBe(0)
        ->and($vm->fresh()->state)->toBe(TaskVmState::Destroying)
        ->and($vm->fresh()->destroyed_at)->toBeNull()
        ->and($vm->fresh()->pi_token)->toBe('test-pi-token');
    expect(Node::query()->whereKey($vm->node_id)->exists())->toBeTrue();
    Queue::assertNothingPushed();
});

it('ignores an already destroyed or missing row when draining an old job', function (bool $missing): void {
    $vm = taskVmRetiredFixture();
    if ($missing) {
        $vm->delete();
    } else {
        $vm->update(['state' => TaskVmState::Destroyed]);
    }

    app()->call([new DestroyTaskVm($vm->id), 'handle']);

    expect(Operation::query()->count())->toBe(0);
})->with(['missing' => true, 'destroyed' => false]);

it('admits retired VM cleanup directly from the host sweep without queuing a PHP destroyer', function (): void {
    Queue::fake();
    $vm = taskVmRetiredFixture();

    app()->call([new SweepTaskVmHosts, 'handle']);

    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('task_vm.destroy')
        ->and($operation->target_id)->toBe($vm->id)
        ->and($operation->status)->toBe(OperationStatus::Queued)
        ->and($vm->fresh()->state)->toBe(TaskVmState::Destroying);
    Queue::assertNothingPushed();
});

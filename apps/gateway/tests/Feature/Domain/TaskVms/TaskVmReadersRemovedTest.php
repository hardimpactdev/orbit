<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\Project;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/*
 * The Gateway reads no `task_vms` row any more (docs/reference/old-paths.md#the-task-vm-readers). Each test leaves an old row that names
 * the Node, which a removed guard used to read, and shows that the Node is treated as every other Node.
 */

function readerNode(string $name, string $address, LifecycleStatus $status = LifecycleStatus::Active): Node
{
    return Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => $name, 'public_ssh_host' => $address, 'wireguard_ip' => $address,
        'user' => 'orbit', 'status' => $status, 'platform' => 'linux', 'architecture' => 'x86_64']));
}

function oldVmRow(Node $node, TaskVmState $state = TaskVmState::Ready): TaskVm
{
    return TaskVm::query()->create(['host_node_id' => $node->id, 'node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-'.$node->id,
        'state' => $state, 'wireguard_ip' => $node->wireguard_ip]);
}

it('records an access grant from a Node that an old live task VM row names', function (): void {
    $consumer = readerNode('consumer', '10.44.0.141');
    $serving = readerNode('serving', '10.44.0.142');
    oldVmRow($consumer);

    NodeAccess::query()->create(['consumer_node_id' => $consumer->id, 'serving_node_id' => $serving->id]);

    expect(NodeAccess::query()->where('consumer_node_id', $consumer->id)->count())->toBe(1);
});

it('saves any Instance on a Node that an old live task VM row names', function (): void {
    $node = readerNode('guest', '10.44.0.143');
    oldVmRow($node);
    $project = Project::query()->create(['name' => 'site', 'slug' => 'site', 'repository_url' => 'https://github.com/acme/site.git', 'default_branch' => 'main', 'root' => 'public']);

    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'free', 'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/home/orbit/apps/site/free', 'status' => InstanceState::Reserved]);

    expect($instance->exists)->toBeTrue();
});

it('gives no sandbox reason to a Node that an old live task VM row names', function (): void {
    $node = readerNode('guest', '10.44.0.144', LifecycleStatus::Failed);
    oldVmRow($node);

    expect(app(FleetRolloutMembership::class)->exclusion($node))->toBe('inactive');
});

it('reports no parked task VM in Doctor for a Node that an old parked row names', function (): void {
    $node = readerNode('guest', '10.44.0.145');
    oldVmRow($node, TaskVmState::Parked);

    $report = json_encode(app(FactDoctor::class)->report([$node], [DoctorFamily::Node])->toArray(), JSON_THROW_ON_ERROR);

    expect($report)->not->toContain('node.task_vm_parked');
});

it('plans a Node removal with no task VM, though an old row names the Node', function (): void {
    SwampNodeFixture::ensure()->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $node = readerNode('guest', '10.44.0.146');
    oldVmRow($node, TaskVmState::Destroyed);
    $keys = app(SshKeyProvider::class);
    @mkdir(dirname($keys->privateKeyPath()), 0o700, true);
    file_put_contents($keys->privateKeyPath().'.pub', 'ssh-ed25519 AAAAtest gateway');

    $snapshot = app(NodeRemovalPlan::class)->build($node, offline: true, machineDeleted: true);
    $intent = app(NodeRemovalInput::class)->capture($snapshot);

    expect($snapshot['task_vm_id'])->toBeNull()
        ->and(data_get($intent, 'intent.task_vm_guest'))->toBeFalse();
});

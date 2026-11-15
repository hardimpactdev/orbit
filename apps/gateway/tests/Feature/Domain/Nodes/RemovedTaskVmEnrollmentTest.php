<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationSecretOutputs;
use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Nodes\NodeAgentHashSource;
use App\Infrastructure\Nodes\NodeVpnConfigurationSource;
use App\Infrastructure\Nodes\NodeWorkerEnrollment;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

// Step 5 removed the task VM enrollment arms of `tasks.provision`. The kind stays registered as the request kind of
// task:start, but no Operation of it may enroll a Node, read its VPN or worker material, store an agent hash, or install
// a Process. These cases prove each arm refuses an old provision row, and that a real Node enrollment is unchanged.

uses(LazilyRefreshDatabase::class);

const ENROLL_RUN = '5d7c1f39-8e44-4a3e-9d7b-0b9f1c2a6e10';

function enrollNode(string $name, string $status, string $address): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => $status, 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit']));
}

/** The vpn hub, a guest Node, and the old task VM row of a group on that guest, as a task start left them.
 * @return array{hub:Node,context:Node,group:Task,vm:TaskVm}
 */
function enrollHub(): array
{
    $hub = enrollNode('vpn', 'active', '10.44.0.1');
    NodeRole::query()->create(['node_id' => $hub->id, 'role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $context = enrollNode('guest', 'provisioning', '10.44.0.200');
    $project = Project::query()->create(['name' => 'Trial', 'slug' => 'trial', 'repository_url' => 'https://github.com/example/trial.git', 'default_branch' => 'main']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Old VM group', 'brief' => 'Old.', 'status' => TaskGroupStatus::Running]);
    $vm = TaskVm::query()->create(['host_node_id' => $hub->id, 'node_id' => $context->id, 'group_id' => $group->id, 'provider' => 'incus',
        'name' => 'tvm-1', 'state' => 'ready', 'size' => 'normal', 'wireguard_ip' => '10.44.0.200']);

    return ['hub' => $hub, 'context' => $context, 'group' => $group, 'vm' => $vm];
}

/** An old task provision of the group with the exact enrollment input that the removed arms accepted.
 * @param  array{hub:Node,context:Node,group:Task,vm:TaskVm}  $fleet
 */
function enrollProvision(array $fleet): Operation
{
    return enrollWireGuard('tasks.provision', $fleet['hub'], $fleet['context'], 'task_group', $fleet['group']->id, $fleet['vm']->id);
}

/** A hub participant intent of `$kind` whose snapshot, participants and binding are all exact. */
function enrollWireGuard(string $kind, Node $hub, Node $context, string $targetType, int $targetId, ?int $vmId = null): Operation
{
    $snapshot = ['schema_version' => 1, 'executor_node_id' => $hub->id, 'hub_id' => $hub->id, 'context_node_id' => $context->id,
        'excluded_node_id' => null, 'agent_sha256' => str_repeat('b', 64)];

    return Operation::query()->create(['kind' => $kind, 'target_type' => $targetType, 'target_id' => $targetId, 'node_id' => $hub->id,
        'input' => ['_wireguard' => $snapshot, 'participant_node_ids' => [$context->id, $hub->id], 'task_vm_id' => $vmId,
            'guest_node_id' => $context->id, 'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $context->id],
            'private_bindings' => ['configuration' => ['owner_node_id' => $hub->id, 'read_node_ids' => [$hub->id], 'write_node_ids' => []]]],
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => ENROLL_RUN], 'sequence' => 1, 'attempts' => 1]);
}

it('binds the hub snapshot of a real node.add and none of an old task provision with the same snapshot', function (): void {
    $fleet = enrollHub();
    ['hub' => $hub, 'context' => $context] = $fleet;

    $enrollment = enrollWireGuard('node.add', $hub, $context, 'node', $context->id);
    $provision = enrollProvision($fleet);

    expect(NodeWireGuardInput::bound($enrollment))->toBe($enrollment->input['_wireguard'])
        ->and(NodeWireGuardInput::bound($provision))->toBeNull()
        ->and(app(NodeVpnConfigurationSource::class)->ownerId($provision))->toBeNull()
        ->and(fn () => app(NodeVpnConfigurationSource::class)->resolve($provision, $hub->id, '', 'configuration'))
        ->toThrow(ResourceOperationException::class, 'The VPN material requires its exact admitted hub participant.');
});

it('gives the Node material sources no task VM or task group owner', function (string $source): void {
    $fleet = enrollHub();
    ['hub' => $hub, 'context' => $context] = $fleet;
    $provision = enrollProvision($fleet);

    expect(app($source)->targetTypes())->toBe(['node'])
        ->and(app($source)->ownerId($provision))->toBeNull();
})->with([NodeVpnConfigurationSource::class, NodeWorkerEnrollment::class, NodeAgentHashSource::class]);

it('refuses an old task provision the agent hash and the worker credentials of its guest', function (): void {
    $fleet = enrollHub();
    ['hub' => $hub, 'context' => $context] = $fleet;
    $provision = enrollProvision($fleet);

    expect(fn () => app(NodeAgentHashSource::class)->resolve($provision, $context->id, '', 'secret_hash'))
        ->toThrow(ResourceOperationException::class, 'The agent hash does not belong to this Node.')
        ->and(fn () => app(NodeWorkerEnrollment::class)->resolve($provision, $context->id, '', 'worker_credentials'))
        ->toThrow(ResourceOperationException::class, 'Worker credentials do not belong to this Node intent.');
});

it('registers no secret output store, so no Operation can write an agent hash or worker credentials', function (string $store): void {
    $fleet = enrollHub();
    ['hub' => $hub, 'context' => $context] = $fleet;
    $provision = enrollProvision($fleet);

    $names = array_map(static fn (OperationSecretOutputStore $s): string => $s->name(), iterator_to_array(app()->tagged(OperationSecretOutputStore::class), false));
    expect($names)->not->toContain('node.agent_secret_hash')->not->toContain('node.worker_credentials')
        ->and(fn () => app(OperationSecretOutputs::class)->write($provision, $store, str_repeat('c', 64)))
        ->toThrow(ResourceOperationException::class);
    expect($context->refresh()->agent_secret_hash)->toBeNull();
})->with(['node.agent_secret_hash', 'node.worker_credentials']);

it('refuses to install a Process of a task workspace for an old task provision', function (): void {
    ['hub' => $node, 'group' => $group] = enrollHub();
    $workspace = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => $node->id, 'name' => 'workspace',
        'checkout_path' => '/home/orbit/apps/trial/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Active]);
    $process = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $workspace->id, 'name' => 'queue',
        'runtime' => 'systemd', 'runtime_config' => ['command' => 'php artisan queue:work'], 'desired_state' => 'running', 'status' => 'active']);
    $provision = Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id,
        'input' => ['instance_id' => $workspace->id], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1]);

    expect(fn () => app(ProcessLifecycleInput::class)->forInstanceMember($provision, ['id' => $process->id, 'input' => ['process_id' => $process->id]]))
        ->toThrow(ResourceOperationException::class, 'The runtime member belongs to another owner.');
});

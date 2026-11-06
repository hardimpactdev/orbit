<?php

declare(strict_types=1);

use App\Data\Tasks\StartTaskCheckData;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckIntent;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskVmAwake;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A group with its task VM and workspace. The VM has no Pi credential, so a resume is refused before any run starts. */
function awakeWorld(TaskVmState $state): array
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $guest = $node('tvm-598', '10.44.0.137');
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running, 'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $vm = TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-598',
        'state' => $state, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.137', 'size' => TaskVmSize::Normal, 'pi_token' => null]);
    $vm->update(['name' => 'tvm-'.$vm->id]);
    Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Reserved, 'first_setup_pending' => true]);

    return [$group, $vm];
}

it('lets work go on while the task VM runs', function (): void {
    [$group] = awakeWorld(TaskVmState::Ready);

    TaskVmAwake::require($group);

    expect(Operation::query()->where('kind', 'task_vm.resume')->count())->toBe(0);
});

it('refuses the call, with the reason, when the resume of a parked VM cannot be admitted', function (): void {
    [$group] = awakeWorld(TaskVmState::Parked);

    expect(fn () => TaskVmAwake::require($group))->toThrow(ResourceOperationException::class, 'Resume requires the parked VM')
        ->and(Operation::query()->where('kind', 'task_vm.resume')->count())->toBe(0);
});

it('leaves an ended group and a group that another operation holds to their own refusals', function (): void {
    [$group] = awakeWorld(TaskVmState::Parked);
    $group->update(['status' => TaskGroupStatus::Cancelled]);

    TaskVmAwake::require($group);

    $group->update(['status' => TaskGroupStatus::Running]);
    Operation::query()->create(['kind' => 'tasks.prep_fix', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => 1, 'status' => OperationStatus::Running, 'input' => []]);
    TaskVmAwake::require($group);

    expect(Operation::query()->where('kind', 'task_vm.resume')->count())->toBe(0);
});

it('refuses an admission of a check on a parked VM before it creates anything', function (): void {
    [$group] = awakeWorld(TaskVmState::Parked);
    $subtask = Task::query()->create(['parent_id' => $group->id, 'title' => 'Subtask', 'brief' => 'Brief', 'status' => TaskStatus::Todo, 'position' => 1]);

    $start = fn () => app(TaskCheckIntent::class)->start($group, new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null);

    expect($start)->toThrow(ResourceOperationException::class, 'Resume requires the parked VM')
        ->and(Operation::query()->where('kind', 'tasks.check')->count())->toBe(0)
        ->and($subtask->checks()->count())->toBe(0);
});

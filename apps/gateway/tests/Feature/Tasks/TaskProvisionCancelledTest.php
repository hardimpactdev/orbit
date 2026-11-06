<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\EndCancelledTaskProvision;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/**
 * A provision as the Gateway admitted it on the clone path: its group reserved, its VM row `provisioning` and its workspace reserved.
 *
 * @return array{Operation, Task, TaskVm}
 */
function cancelledProvision(): array
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $guest = $node('tvm-791', '10.44.0.133');
    $project = Project::query()->create(['name' => 'bench', 'slug' => 'bench', 'repository_url' => 'https://github.com/laravel/laravel.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Task', 'brief' => 'Brief', 'status' => TaskGroupStatus::Reserved]);
    $vm = TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-791',
        'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.133', 'size' => TaskVmSize::Normal, 'image_fingerprint' => 'fingerprint', 'recipe_identity' => 'recipe']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/bench/workspace', 'task_workspace_owner_id' => $group->id,
        'status' => InstanceState::Reserved, 'first_setup_pending' => true]);
    $group->taskable()->associate($instance)->save();
    $operation = Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => ['task_vm_id' => $vm->id, 'node_id' => $host->id, 'host_node_id' => $host->id, 'guest_node_id' => $guest->id,
            'name' => $vm->name, 'provider' => 'incus', 'provider_id' => null, 'wireguard_ip' => $vm->wireguard_ip, 'image_fingerprint' => 'fingerprint',
            'recipe_identity' => 'recipe', 'group_id' => $group->id, 'instance_id' => $instance->id, 'enrollment' => [], 'route_id' => null, 'members' => []]]);

    return [$operation, $group, $vm];
}

it('admits the ending of a group whose provision ended cancelled, so the VM it was creating is destroyed', function (): void {
    Queue::fake();
    [$operation, $group, $vm] = cancelledProvision();

    app(TaskProvisionProjection::class)->record($operation, ['status' => 'cancelled'], ['ready' => false, 'failed_step' => 'vm'], fn (): Operation => $operation);

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($vm->refresh()->state)->toBe(TaskVmState::Provisioning);
    Queue::assertPushed(EndCancelledTaskProvision::class, fn (EndCancelledTaskProvision $job): bool => $job->groupId === $group->id && $job->provisionId === $operation->id);
});

it('starts no ending after a failed provision, which marks its VM failed and asks for help', function (): void {
    Queue::fake();
    [$operation, $group, $vm] = cancelledProvision();

    app(TaskProvisionProjection::class)->record($operation, ['status' => 'failed', 'error_code' => 'tasks.provision_failed'], ['ready' => false, 'failed_step' => 'vm'], fn (): Operation => $operation);

    expect($vm->refresh()->state)->toBe(TaskVmState::Failed);
    Queue::assertNotPushed(EndCancelledTaskProvision::class);
});

it('asks for help when the ending of a cancelled provision is refused', function (): void {
    app(TaskExtensionState::class)->enable();
    [$operation, $group] = cancelledProvision();
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/laravel/laravel/pull/1']);

    (new EndCancelledTaskProvision($group->id, $operation->id))->handle(app(OperationIdempotency::class), app(TaskCancelIntent::class));

    expect($group->refresh()->assistance_requested)->toBeTrue()
        ->and($group->assistance_reason)->toContain('tasks.not_cancellable');
});

it('tries again while another operation holds the group', function (): void {
    app(TaskExtensionState::class)->enable();
    [$operation, $group] = cancelledProvision();
    $group->update(['status' => TaskGroupStatus::Cancelled]);
    $operation->forceFill(['lock_key' => OperationStore::lockKey('task_group', $group->id)])->save();

    expect(fn () => (new EndCancelledTaskProvision($group->id, $operation->id))->handle(app(OperationIdempotency::class), app(TaskCancelIntent::class)))
        ->toThrow(ResourceOperationException::class, 'Another Operation owns this target.')
        ->and($group->refresh()->assistance_requested)->toBeFalse();
});

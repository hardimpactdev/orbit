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
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\EndCancelledTaskProvision;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

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
    // A destroy names the VM by its id, as the Gateway does.
    $vm->update(['name' => 'tvm-'.$vm->id]);
    Node::withoutEvents(fn (): bool => $guest->update(['name' => $vm->name]));
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

it('admits one keyed ending for a cancelled provision, which captures the destroy of its VM', function (): void {
    app(TaskExtensionState::class)->enable();
    // The destroy also takes the VM's Node out of the WireGuard hub, through the Swamp Node.
    SwampNodeFixture::ensure();
    @mkdir(config('orbit.home').'/ssh', 0700, true);
    file_put_contents(config('orbit.home').'/ssh/id_ed25519.pub', 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('g', 32))." gateway\n");
    Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:vpn', 'ssh_host_key_type' => 'ssh-ed25519',
        'ssh_host_key' => 'vpn-public-key']))->roles()->create(['role' => 'vpn', 'status' => 'active']);
    [$operation, $group, $vm] = cancelledProvision();
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, Cluster::query()->create(['name' => 'dev', 'tld' => 'dev'])->id, '10.44.0.128/25',
        [new TaskVmHost($vm->host_node_id, 'orbit-tasks', 'orbittask0', '10.252.8.0/24', 6, 4, '8GiB', '20GiB')], 'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
    $group->update(['status' => TaskGroupStatus::Cancelled]);
    $operation->forceFill(['status' => OperationStatus::Cancelled, 'finished_at' => now()])->save();
    $end = fn () => (new EndCancelledTaskProvision($group->id, $operation->id))->handle(app(OperationIdempotency::class), app(TaskCancelIntent::class));

    $end();
    $end();

    $endings = Operation::query()->where('kind', 'tasks.cancel')->where('target_id', $group->id)->get();
    expect($endings)->toHaveCount(1)
        ->and($endings->sole()->idempotency_key)->toBe('provision-cancelled:'.$operation->id)
        ->and($endings->sole()->input['task_vm_id'])->toBe($vm->id)
        ->and($group->refresh()->assistance_requested)->toBeFalse();
});

it('does nothing for a group that is gone', function (): void {
    $job = new EndCancelledTaskProvision(999, 998);

    $job->handle(app(OperationIdempotency::class), app(TaskCancelIntent::class));
    $job->failed(new RuntimeException('gone'));

    expect(Operation::query()->where('kind', 'tasks.cancel')->exists())->toBeFalse();
});

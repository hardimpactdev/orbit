<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrepFixProjection;
use App\Domain\Tasks\TaskPrepProjection;
use App\Domain\Tasks\TaskStatus;
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

/** One prep run as the Gateway records it at admission, with the VM still provisioning. */
function prepRun(): array
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $guest = $node('tvm-598', '10.44.0.137');
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Prepare firewall:deny', 'brief' => 'Brief', 'status' => TaskGroupStatus::Reserved,
        'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $vm = TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-598',
        'state' => TaskVmState::Provisioning, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.137', 'size' => TaskVmSize::Normal,
        'image_fingerprint' => 'fingerprint', 'recipe_identity' => 'recipe']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id,
        'status' => InstanceState::Reserved, 'first_setup_pending' => true]);
    $turn = static fn (int $thread): array => ['thread_id' => $thread, 'role' => 'implementer'];
    $operation = Operation::query()->create(['kind' => 'tasks.prep', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $host->id, 'status' => OperationStatus::Running,
        'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'], 'input' => [
            'command' => 'firewall:deny', 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $host->id, 'execution_secrets' => [],
            'provision' => ['task_vm_id' => $vm->id, 'node_id' => $host->id, 'host_node_id' => $host->id, 'guest_node_id' => $guest->id, 'name' => 'tvm-598', 'provider' => 'incus',
                'provider_id' => null, 'wireguard_ip' => '10.44.0.137', 'image_fingerprint' => 'fingerprint', 'recipe_identity' => 'recipe', 'instance_id' => $instance->id,
                'enrollment' => [], 'route_id' => null, 'members' => []],
            'draft' => $turn(11), 'fix' => $turn(11), 'reviews' => [$turn(12), $turn(13), $turn(14)],
            'publish' => ['branch' => 'task-'.$group->id], 'publish_secrets' => [],
        ]]);

    return [$operation, $group, $vm, $instance];
}

it('fails the run and the workspace records when the VM never became ready', function (): void {
    [$operation, $group, $vm, $instance] = prepRun();

    $projected = app(TaskPrepProjection::class)->apply($operation, [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Create the VM' => ['status' => 'failed']], 'summary' => ['status' => 'failed', 'failed_steps' => ['Create the VM']]],
        'failure' => ['failed_step' => 'Create the VM', 'detail' => 'exec failed', 'stderr_recorded' => true],
    ]);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe('tasks.provision_failed')
        ->and($projected->result)->toMatchArray(['error_code' => 'tasks.provision_failed', 'failed_step' => 'Create the VM'])
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Failed)
        ->and($vm->refresh()->state)->toBe(TaskVmState::Failed);
});

it('keeps the findings of the last review in the result of a run that ended on them', function (): void {
    [$operation] = prepRun();

    $projected = app(TaskPrepProjection::class)->apply($operation, [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed', 'started_at' => now()->subHour()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => [], 'summary' => ['status' => 'failed', 'failed_steps' => ['Review has no findings']], 'findings' => '1. The chart has no end.'],
        'failure' => ['failed_step' => 'Review has no findings', 'detail' => 'tasks.prep_review_unresolved', 'stderr_recorded' => false],
    ]);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe('tasks.prep_review_unresolved')
        ->and($projected->result['findings'])->toBe('1. The chart has no end.');
});

it('completes the open spec subtask of a group when the draft PR is open, and no other', function (): void {
    [, $group] = prepRun();
    $open = Task::query()->create(['parent_id' => $group->id, 'title' => 'Spec', 'brief' => 'Brief', 'status' => TaskStatus::Todo, 'position' => 1]);
    $done = Task::query()->create(['parent_id' => $group->id, 'title' => 'Earlier', 'brief' => 'Brief', 'status' => TaskStatus::Cancelled, 'position' => 2]);

    TaskPrepProjection::completeSubtasks($group->id, 2825);

    expect($open->refresh()->status)->toBe(TaskStatus::Completed)
        ->and($open->completion_summary)->toBe('The spec is in draft PR #2825.')
        ->and($open->settled_at)->not->toBeNull()
        ->and($done->refresh()->status)->toBe(TaskStatus::Cancelled);
});

it('records the VM as ready once its resume step succeeded and keeps the findings of a run that ended on them', function (): void {
    [$operation, $group, $vm, $instance] = prepRun();
    $vm->update(['state' => TaskVmState::Parked]);
    $operation->update(['kind' => 'tasks.prep_fix', 'input' => [
        'command' => 'firewall:allow', 'resume' => ['task_vm_id' => $vm->id], 'publish' => ['branch' => 'task-'.$group->id],
        'author' => ['thread_id' => 11], 'fix' => ['thread_id' => 11], 'reviews' => [['thread_id' => 12], ['thread_id' => 13]],
    ]]);

    $projected = app(TaskPrepFixProjection::class)->apply($operation, [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed', 'started_at' => now()->subHour()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Resume the VM' => ['status' => 'succeeded']], 'summary' => ['status' => 'failed', 'failed_steps' => ['Review has no findings']], 'findings' => '1. Fix the title.'],
        'failure' => ['failed_step' => 'Review has no findings', 'detail' => 'tasks.prep_review_unresolved', 'stderr_recorded' => false],
    ]);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe('tasks.prep_review_unresolved')
        ->and($projected->result['findings'])->toBe('1. Fix the title.')
        ->and($vm->refresh()->state)->toBe(TaskVmState::Ready);
});

it('keeps the VM parked when the run failed before the resume step succeeded', function (): void {
    [$operation, , $vm] = prepRun();
    $vm->update(['state' => TaskVmState::Parked]);
    $operation->update(['kind' => 'tasks.prep_fix', 'input' => [
        'command' => 'firewall:allow', 'resume' => ['task_vm_id' => $vm->id], 'publish' => ['branch' => 'task-1'],
        'author' => ['thread_id' => 11], 'fix' => ['thread_id' => 11], 'reviews' => [['thread_id' => 12], ['thread_id' => 13]],
    ]]);

    $projected = app(TaskPrepFixProjection::class)->apply($operation, [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Resume the VM' => ['status' => 'failed']], 'summary' => ['status' => 'failed', 'failed_steps' => ['Resume the VM']]],
        'failure' => ['failed_step' => 'Resume the VM', 'detail' => 'exec failed', 'stderr_recorded' => true],
    ]);

    expect($projected->error_code)->toBe('task_vm.resume_failed')
        ->and($vm->refresh()->state)->toBe(TaskVmState::Parked);
});

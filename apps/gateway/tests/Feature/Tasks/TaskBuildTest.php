<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskBuildIntent;
use App\Domain\Tasks\TaskBuildProjection;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrepPrompts;
use App\Domain\Tasks\TaskPullRequestEvidence;
use App\Domain\Tasks\TaskReviewMode;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

const BUILD_HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

/**
 * A prep group whose spec is done: its only subtask is completed, a prep run succeeded, and its task VM and workspace are there.
 *
 * @return array{0: Task, 1: TaskVm, 2: Instance}
 */
function buildWorld(TaskReviewMode $mode = TaskReviewMode::Manual, TaskVmState $vmState = TaskVmState::Ready): array
{
    SwampNodeFixture::ensure();
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $guest = $node('tvm-598', '10.44.0.137');
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public',
        'task_check' => 'composer check']);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, null, '10.44.0.128/25', [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 5, 4, '8GiB', '40GiB')],
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Prepare firewall:deny', 'brief' => 'Brief', 'orchestration' => 'external', 'review_mode' => $mode,
        'status' => $mode === TaskReviewMode::Manual ? TaskGroupStatus::WaitingForReview : TaskGroupStatus::Running, 'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $vm = TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-598',
        'state' => $vmState, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.137', 'size' => TaskVmSize::Normal, 'pi_token' => str_repeat('p', 64)]);
    $vm->update(['name' => 'tvm-'.$vm->id]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Reserved, 'first_setup_pending' => true]);
    $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id]);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Spec', 'brief' => 'Draft the spec', 'status' => TaskStatus::Completed,
        'deliverables' => [['id' => 'spec', 'type' => 'review', 'description' => 'The spec is right.']]]);
    Operation::query()->create(['kind' => OperationKinds::TasksPrep, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => $host->id, 'status' => OperationStatus::Succeeded,
        'input' => ['command' => 'firewall:deny']]);

    return [$group, $vm, $instance];
}

it('waits for the build of a parked group in manual mode and of a running group in automatic mode', function (): void {
    [$manual] = buildWorld();
    expect(TaskBuildIntent::waiting($manual))->toBeTrue();
});

it('waits for the build of a running group in automatic mode, after the clean review', function (): void {
    [$automatic] = buildWorld(TaskReviewMode::Automatic);

    expect(TaskBuildIntent::waiting($automatic))->toBeTrue();
});

it('never acts on a group that is running in manual mode, has more than its spec subtask, has no prep run or has built before', function (): void {
    [$group] = buildWorld();

    $group->update(['status' => TaskGroupStatus::Running]);
    expect(TaskBuildIntent::waiting($group))->toBeFalse();

    $group->update(['status' => TaskGroupStatus::WaitingForReview]);
    $extra = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Build', 'brief' => 'Build it', 'status' => TaskStatus::Todo]);
    expect(TaskBuildIntent::waiting($group))->toBeFalse();

    $extra->delete();
    expect(TaskBuildIntent::waiting($group))->toBeTrue();

    $build = Operation::query()->create(['kind' => OperationKinds::TasksBuild, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null, 'status' => OperationStatus::Failed, 'input' => []]);
    expect(TaskBuildIntent::waiting($group))->toBeFalse();

    $build->delete();
    Operation::query()->where('kind', OperationKinds::TasksPrep)->delete();
    expect(TaskBuildIntent::waiting($group))->toBeFalse();
});

it('appends the build subtask, moves the group to running and records the whole intent of one run', function (): void {
    [$group, $vm] = buildWorld();

    // Swamp is not there: the admission is recorded, and the start is refused after it.
    expect(fn () => app(TaskBuildIntent::class)->start($group, BUILD_HEAD, 'test', null))->toThrow(ResourceOperationException::class);

    $operation = Operation::query()->where('kind', OperationKinds::TasksBuild)->sole();
    $subtask = Task::query()->where('parent_id', $group->id)->where('position', 2)->sole();
    $prompts = TaskPrepPrompts::for('firewall:deny');

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($subtask->title)->toBe('Build firewall:deny')
        ->and($subtask->status)->toBe(TaskStatus::Todo)
        ->and($subtask->brief)->toContain(BUILD_HEAD, 'docs/cli/firewall/deny.mmd', 'docs/cli/firewall/deny.mdx', 'swamp/proof/firewall/deny.cases.yaml', 'task-'.$group->id)
        ->and(array_column($subtask->deliverables, 'id'))->toBe(['runner-plan', 'project-check'])
        ->and($subtask->deliverables[0]['command'])->toBe($prompts->plan())
        ->and($subtask->deliverables[1]['command'])->toBe('composer check')
        ->and($subtask->implementer_agent_thread_id)->not->toBeNull()
        ->and($operation->input['approval'])->toBe(['head' => BUILD_HEAD, 'mode' => 'manual', 'reviewer' => TaskPullRequestEvidence::reviewer()])
        ->and($operation->input['subtask'])->toBe($subtask->id)
        ->and($operation->input['resume']['task_vm_id'])->toBe($vm->id)
        ->and($operation->input['sync']['workspace']['branch'])->toBe('task-'.$group->id)
        ->and($operation->input['turn'])->toMatchArray(['subtask' => $subtask->id, 'role' => 'implementer', 'new_session' => true, 'thread_id' => $subtask->implementer_agent_thread_id])
        ->and($operation->input['turn']['prompt'])->toStartWith("Subtask: Build firewall:deny\n\nBrief:\nThe owner approved the spec")->toContain('Deliverables:', 'Prompt of this turn:')
        ->and($operation->input['execution_secrets'])->toBe(['pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id]]);
});

it('builds an automatic group that is running with a running VM, and records no reviewer', function (): void {
    [$group] = buildWorld(TaskReviewMode::Automatic);

    expect(fn () => app(TaskBuildIntent::class)->start($group, BUILD_HEAD, 'test', null))->toThrow(ResourceOperationException::class);

    $operation = Operation::query()->where('kind', OperationKinds::TasksBuild)->sole();

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($operation->input['approval'])->toBe(['head' => BUILD_HEAD, 'mode' => 'automatic', 'reviewer' => null])
        ->and($operation->input['resume']['mode'])->toBe('converge');
});

it('refuses a build that has no workspace of its own and leaves the group as it was', function (): void {
    [$group] = buildWorld();
    $group->update(['taskable_type' => null, 'taskable_id' => null]);

    expect(fn () => app(TaskBuildIntent::class)->start($group, BUILD_HEAD, 'test', null))->toThrow(ResourceOperationException::class, 'owned task VM workspace')
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and(Task::query()->where('parent_id', $group->id)->count())->toBe(1)
        ->and(Operation::query()->where('kind', OperationKinds::TasksBuild)->count())->toBe(0);
});

it('refuses a build for a group that already started one', function (): void {
    [$group] = buildWorld();
    Operation::query()->create(['kind' => OperationKinds::TasksBuild, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null, 'status' => OperationStatus::Succeeded, 'input' => []]);

    expect(fn () => app(TaskBuildIntent::class)->start($group, BUILD_HEAD, 'test', null))->toThrow(ResourceOperationException::class, 'build has not started');
});

/** A build run as the Gateway records it at admission, with the VM parked. */
function buildRun(): array
{
    [$group, $vm] = buildWorld(vmState: TaskVmState::Parked);
    $operation = Operation::query()->create(['kind' => OperationKinds::TasksBuild, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => $vm->host_node_id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'], 'input' => [
            'group_id' => $group->id, 'approval' => ['head' => BUILD_HEAD, 'mode' => 'manual', 'reviewer' => 'nick'],
            'resume' => ['task_vm_id' => $vm->id], 'sync' => [], 'turn' => ['thread_id' => 11],
            'execution_secrets' => ['pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id]], 'resume_secrets' => [],
        ]]);

    return [$operation, $group, $vm];
}

/** @param array<string,mixed> $result */
function buildEnd(string $status, array $result, ?array $failure = null): array
{
    return ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => $status, 'started_at' => now()->subMinutes(30)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => $result, ...($failure === null ? [] : ['failure' => $failure])];
}

it('records the VM as ready and the branch with the approved head when the run succeeded, and leaves the group running', function (): void {
    [$operation, $group, $vm] = buildRun();

    $projected = app(TaskBuildProjection::class)->apply($operation, buildEnd('succeeded', ['steps' => ['Resume the VM' => ['status' => 'succeeded']]]));

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['branch' => 'task-'.$group->id, 'head' => BUILD_HEAD])
        ->and($vm->refresh()->state)->toBe(TaskVmState::Ready)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview);
});

it('names the failed step of a run, and keeps the VM parked when the resume itself failed', function (string $step, string $code, TaskVmState $vm): void {
    [$operation, , $parked] = buildRun();
    $steps = $step === 'Resume the VM' ? [] : ['Resume the VM' => ['status' => 'succeeded']];

    $projected = app(TaskBuildProjection::class)->apply($operation, buildEnd('failed', ['steps' => [...$steps, $step => ['status' => 'failed']], 'summary' => ['status' => 'failed', 'failed_steps' => [$step]]],
        ['failed_step' => $step, 'detail' => 'exec failed', 'stderr_recorded' => true]));

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe($code)
        ->and($projected->result)->toBe(['error_code' => $code, 'failed_step' => $step])
        ->and($parked->refresh()->state)->toBe($vm);
})->with([
    'the resume' => ['Resume the VM', 'task_vm.resume_failed', TaskVmState::Parked],
    'the sync' => ['Sync the refs', 'tasks.sync_failed', TaskVmState::Ready],
    'the first turn' => ['Run the first turn', 'tasks.turn_failed', TaskVmState::Ready],
]);

it('projects the end of a run once, and a replay of the terminal event changes nothing', function (): void {
    [$operation] = buildRun();
    $projection = app(TaskBuildProjection::class);

    $end = buildEnd('succeeded', ['steps' => []]);
    $first = $projection->apply($operation, $end);
    $again = $projection->apply($operation, $end);

    expect($first->status)->toBe(OperationStatus::Succeeded)->and($again->status)->toBe(OperationStatus::Succeeded)->and($again->result)->toBe($first->result);
});

it('reads the Operation without writing the Pi token, writes it once at run start, and sends an empty resume map as an object', function (): void {
    [$operation, , $vm] = buildRun();
    $writes = new class
    {
        /** @var list<array{string, string, string}> */
        public array $calls = [];

        /** @return array{vault: string, key: string} */
        public function executionCopy(string $vault, string $key, string $value): array
        {
            $this->calls[] = [$vault, $key, $value];

            return ['vault' => $vault, 'key' => $key];
        }
    };
    app()->instance(SwampCommandStep::class, $writes);
    $projection = app(TaskBuildProjection::class);

    $read = $projection->input($operation);
    expect($writes->calls)->toBe([])
        ->and(array_keys($read['intent']))->toBe(['resume', 'sync', 'turn'])
        ->and(json_encode($read['resume_secrets']))->toBe('{}');

    $start = $projection->workflowInput($operation);
    expect($writes->calls)->toBe([['orbit-control-inputs', 'task-vm-pi-token-'.$vm->id, str_repeat('p', 64)]])
        ->and($start['execution_secrets'])->toBe(['pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id]]);

    $vm->update(['pi_token' => null]);
    expect(fn () => $projection->workflowInput($operation))->toThrow(ResourceOperationException::class, 'Pi credential');
});

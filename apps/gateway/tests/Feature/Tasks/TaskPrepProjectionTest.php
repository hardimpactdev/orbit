<?php

declare(strict_types=1);

use App\Actions\Tasks\StartTaskParkAction;
use App\Data\Tasks\StartTaskPrepFixData;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskParkIntent;
use App\Domain\Tasks\TaskPrepFixIntent;
use App\Domain\Tasks\TaskPrepFixProjection;
use App\Domain\Tasks\TaskPrepProjection;
use App\Domain\Tasks\TaskPrepThreads;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Jobs\TaskVms\ReleaseTaskVmSlot;
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
            'provision' => ['task_vm_id' => $vm->id, 'node_id' => $host->id, 'group_id' => $group->id, 'instance_id' => $instance->id,
                'workspace' => ['branch' => 'task-'.$group->id, 'start_commit' => str_repeat('a', 40)], 'intent' => ['slot' => ['name' => 'tvm-598']]],
            'draft' => $turn(11), 'fix' => $turn(11), 'reviews' => [$turn(12), $turn(13), $turn(14)],
            'publish' => ['branch' => 'task-'.$group->id], 'publish_secrets' => [],
        ]]);

    return [$operation, $group, $vm, $instance];
}

it('fails the run and the workspace, and releases the slot, when the claim did not pass', function (): void {
    Queue::fake();
    [$operation, $group, $vm, $instance] = prepRun();

    $projected = app(TaskPrepProjection::class)->apply($operation, [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Claim a slot' => ['status' => 'failed']], 'summary' => ['status' => 'failed', 'failed_steps' => ['Claim a slot']]],
        'failure' => ['failed_step' => 'Claim a slot', 'detail' => 'exec failed', 'stderr_recorded' => true],
    ]);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe('tasks.provision_failed')
        ->and($projected->result)->toMatchArray(['error_code' => 'tasks.provision_failed', 'failed_step' => 'Claim a slot'])
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Failed)
        ->and($instance->refresh()->failed_step)->toBe('provision');
    Queue::assertPushed(ReleaseTaskVmSlot::class, fn (ReleaseTaskVmSlot $job): bool => $job->slotId === $vm->id);
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
        'command' => 'firewall:allow', 'task_vm_id' => $vm->id, 'resume' => ['task_vm_id' => $vm->id], 'publish' => ['branch' => 'task-'.$group->id],
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
        'command' => 'firewall:allow', 'task_vm_id' => $vm->id, 'resume' => ['task_vm_id' => $vm->id], 'publish' => ['branch' => 'task-1'],
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

it('refuses the corrections of a group that is busy or has no task VM workspace', function (): void {
    [$operation, $group] = prepRun();
    $start = fn () => app(TaskPrepFixIntent::class)->start($group, new StartTaskPrepFixData('Finding 1.'), 'test', null);

    // The prep run is still running: the group is busy.
    expect($start)->toThrow(ResourceOperationException::class, 'Another operation');

    // The run ended, but the group holds no workspace of its own yet.
    $operation->update(['status' => OperationStatus::Succeeded]);
    expect($start)->toThrow(ResourceOperationException::class, 'owned task VM workspace');
});

it('admits corrections on a ready slot VM without a resume', function (): void {
    [$group, $vm] = prepFixWorld(TaskVmState::Ready);

    expect(fn () => app(TaskPrepFixIntent::class)->start($group, new StartTaskPrepFixData('Finding 1.'), 'test', null))->toThrow(ResourceOperationException::class);

    $operation = Operation::query()->where('kind', 'tasks.prep_fix')->sole();
    expect($operation->input['task_vm_id'])->toBe($vm->id)
        ->and($operation->input['node_id'])->toBe($vm->node_id)
        ->and($operation->input)->not->toHaveKey('resume')
        ->and($operation->input)->not->toHaveKey('resume_secrets');

    $input = app(TaskPrepFixProjection::class)->input($operation);
    expect($input['intent'])->not->toHaveKey('resume')
        ->and($input['intent']['task_vm_id'])->toBe($vm->id)
        ->and(json_encode($input['resume_secrets']))->toBe('{}');
});

it('captures a resume before corrections on a parked slot VM', function (): void {
    [$group, $vm] = prepFixWorld(TaskVmState::Parked);

    expect(fn () => app(TaskPrepFixIntent::class)->start($group, new StartTaskPrepFixData('Finding 1.'), 'test', null))->toThrow(ResourceOperationException::class);

    $operation = Operation::query()->where('kind', 'tasks.prep_fix')->sole();
    expect($operation->input['task_vm_id'])->toBe($vm->id)
        ->and($operation->input['resume']['task_vm_id'])->toBe($vm->id)
        ->and($operation->input['resume']['mode'])->toBe('resume')
        ->and($operation->input['resume']['provider_input']['name'])->toBe('shark-tvm-2')
        ->and($operation->input)->toHaveKey('resume_secrets');
});

/** The owned workspace and author of a succeeded prep run. */
function prepFixWorld(TaskVmState $state): array
{
    [$prep, $group, $vm, $instance] = prepRun();
    SwampNodeFixture::ensure();
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, null, '10.44.0.128/25', [new TaskVmHost($vm->host_node_id, 'orbit-tasks', 'orbittask0', '10.251.78.0/24', 5, 4, '8GiB', '40GiB')],
        '/pi', str_repeat('a', 64), ['gpt-6']));
    $vm->update(['name' => 'shark-tvm-2', 'slot_number' => 2, 'state' => $state, 'pi_token' => str_repeat('p', 64)]);
    $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id, 'status' => TaskGroupStatus::Running,
        'pr_url' => 'https://github.com/hardimpactdev/orbit/pull/3223', 'watched_pr_state' => 'open']);
    $subtask = $group->tasks()->create(['title' => 'Spec', 'brief' => 'Draft the spec', 'status' => TaskStatus::Completed, 'position' => 1]);
    $author = TaskPrepThreads::create($group, $subtask, $instance, 'implementer');
    $prep->update(['status' => OperationStatus::Succeeded, 'input' => [...$prep->input, 'draft' => ['thread_id' => $author->id]]]);

    return [$group, $vm];
}

it('reads the Operation without writing the Pi token, writes it once at run start, and sends an empty resume map as an object', function (): void {
    [$operation, , $vm] = prepRun();
    $vm->update(['pi_token' => str_repeat('p', 64)]);
    $reference = ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id];
    $operation->update(['kind' => 'tasks.prep_fix', 'input' => [
        'command' => 'firewall:allow', 'task_vm_id' => $vm->id, 'publish' => [], 'author' => [], 'fix' => [], 'reviews' => [], 'plan' => [],
        'execution_secrets' => ['pi_token' => $reference], 'resume_secrets' => [], 'publish_secrets' => [],
    ]]);
    // A recording stand-in for the vault write.
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
    $projection = app(TaskPrepFixProjection::class);

    $read = $projection->input($operation);
    expect($writes->calls)->toBe([])
        ->and($read['execution_secrets'])->toBe(['pi_token' => $reference])
        ->and(json_encode($read['resume_secrets']))->toBe('{}');

    $start = $projection->workflowInput($operation);
    expect($writes->calls)->toBe([['orbit-control-inputs', 'task-vm-pi-token-'.$vm->id, str_repeat('p', 64)]])
        ->and($start['execution_secrets'])->toBe(['pi_token' => $reference])
        ->and(json_encode($start['resume_secrets']))->toBe('{}');

    $vm->update(['pi_token' => null]);
    expect(fn () => $projection->workflowInput($operation))->toThrow(ResourceOperationException::class, 'Pi credential');
});

it('records the commit, the rounds and the thread metrics of a run that pushed, parks the group once, and keeps a refused park', function (): void {
    [$operation, $group, $vm, $instance] = prepRun();
    $vm->update(['state' => TaskVmState::Parked]);
    $subtask = Task::query()->create(['parent_id' => $group->id, 'title' => 'Spec', 'brief' => 'Brief', 'status' => TaskStatus::Completed, 'position' => 1]);
    $author = TaskPrepThreads::create($group, $subtask, $instance, 'implementer');
    $reviews = [TaskPrepThreads::create($group, $subtask, $instance, 'reviewer'), TaskPrepThreads::create($group, $subtask, $instance, 'reviewer')];
    $operation->update(['kind' => 'tasks.prep_fix', 'input' => [
        'command' => 'firewall:allow', 'task_vm_id' => $vm->id, 'resume' => ['task_vm_id' => $vm->id], 'publish' => ['branch' => 'task-'.$group->id],
        'author' => ['thread_id' => $author->id], 'fix' => ['thread_id' => $author->id], 'reviews' => [['thread_id' => $reviews[0]->id], ['thread_id' => $reviews[1]->id]],
    ]]);
    $turn = static fn (int $tokens): array => ['shape_result' => ['result_json' => json_encode(['state' => 'done', 'metrics' => ['tokens' => $tokens]])]];
    $end = [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'succeeded', 'started_at' => now()->subMinutes(30)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Resume the VM' => ['status' => 'succeeded']], 'summary' => ['status' => 'succeeded', 'failed_steps' => []], 'head' => str_repeat('a', 40), 'findings' => 'Approved; runner tests pin the old file.',
            'author' => $turn(100), 'review_1' => $turn(20), 'review_2' => $turn(30)],
    ];
    $parks = refusedParks();

    $projected = app(TaskPrepFixProjection::class)->apply($operation, $end);
    $again = app(TaskPrepFixProjection::class)->apply($projected, $end);

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toMatchArray(['branch' => 'task-'.$group->id, 'commit' => str_repeat('a', 40), 'rounds' => 2, 'notes' => 'Approved; runner tests pin the old file.', 'park_refused' => 'tasks.park_refused'])
        ->and($parks->calls)->toBe(1)
        ->and($again->result)->toBe($projected->result)
        ->and([$author->refresh()->tokens, $reviews[0]->refresh()->tokens, $reviews[1]->refresh()->tokens])->toBe([100, 20, 30])
        ->and($vm->refresh()->state)->toBe(TaskVmState::Ready);
});

/** The terminal event of a prep run that opened its draft pull request. */
function prepEnd(TaskVm $vm): array
{

    return [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'succeeded', 'started_at' => now()->subHour()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => [], 'summary' => ['status' => 'succeeded', 'failed_steps' => []],
            // The claim step's outputs, captured only when it succeeded.
            'provision' => ['Check ready' => ['exitCode' => 0]],
            'publish' => ['pull_request_number' => 2825, 'pull_request_url' => 'https://github.com/hardimpactdev/orbit/pull/2825']],
    ];
}

/** A park that counts its calls and is refused, as it is for a group that is not parkable. */
function refusedParks(): object
{
    $parks = new class
    {
        public int $calls = 0;

        public function execute(Task $group, string $caller, ?string $request): never
        {
            $this->calls++;

            throw new ResourceOperationException('tasks.park_refused', 'Refused.', 409);
        }
    };
    app()->instance(StartTaskParkAction::class, $parks);

    return $parks;
}

it('parks the group once after a manual-mode run opened its draft PR, and keeps a refused park', function (): void {
    [$operation, $group, $vm] = prepRun();
    $operation->update(['input' => [...$operation->input, 'mode' => 'manual']]);
    $parks = refusedParks();

    $projected = app(TaskPrepProjection::class)->apply($operation, prepEnd($vm));
    $again = app(TaskPrepProjection::class)->apply($projected, prepEnd($vm));

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toMatchArray(['pull_request_number' => 2825, 'park_refused' => 'tasks.park_refused'])
        ->and($parks->calls)->toBe(1)
        ->and($again->result)->toBe($projected->result);
});

it('parks nothing after an automatic-mode run', function (): void {
    [$operation, , $vm] = prepRun();
    $operation->update(['input' => [...$operation->input, 'mode' => 'automatic']]);
    $parks = refusedParks();

    $projected = app(TaskPrepProjection::class)->apply($operation, prepEnd($vm));

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->not->toHaveKey('park_refused')
        ->and($parks->calls)->toBe(0);
});

it('parks nothing after a manual-mode run that failed', function (): void {
    [$operation] = prepRun();
    $operation->update(['input' => [...$operation->input, 'mode' => 'manual']]);
    $parks = refusedParks();

    app(TaskPrepProjection::class)->apply($operation, [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => [], 'summary' => ['status' => 'failed', 'failed_steps' => ['Review has no findings']]],
        'failure' => ['failed_step' => 'Review has no findings', 'detail' => 'tasks.prep_review_unresolved', 'stderr_recorded' => false],
    ]);

    expect($parks->calls)->toBe(0);
});

it('parks a manual-mode group whose run was admitted before the mode was captured', function (): void {
    [$operation, , $vm] = prepRun();
    $parks = refusedParks();

    app(TaskPrepProjection::class)->apply($operation, prepEnd($vm));

    expect($parks->calls)->toBe(1);
});

it('leaves the group eligible for the park once a run opened its draft PR', function (): void {
    [$operation, $group, $vm, $instance] = prepRun();
    $group->taskable()->associate($instance)->save();
    refusedParks();

    app(TaskPrepProjection::class)->apply($operation, prepEnd($vm));

    expect(TaskParkIntent::requireEligible($group->refresh())->is($vm))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Actions\Tasks\RunTaskPullRequestGateAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskBuildIntent;
use App\Domain\Tasks\TaskBuildProjection;
use App\Domain\Tasks\TaskExtensionState;
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
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
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
        '/pi', str_repeat('a', 64), ['gpt-6']));
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Prepare firewall:deny', 'brief' => 'Brief', 'orchestration' => 'external', 'review_mode' => $mode,
        'status' => $mode === TaskReviewMode::Manual ? TaskGroupStatus::WaitingForReview : TaskGroupStatus::Running, 'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $vm = TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-598',
        'state' => $vmState, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.137', 'size' => TaskVmSize::Normal, 'pi_token' => str_repeat('p', 64)]);
    $vm->update(['name' => 'shark-tvm-2', 'slot_number' => 2]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Reserved, 'first_setup_pending' => true]);
    $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id]);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Spec', 'brief' => 'Draft the spec', 'status' => TaskStatus::Completed,
        'deliverables' => [['id' => 'spec', 'type' => 'review', 'description' => 'The spec is right.']]]);
    Operation::query()->create(['kind' => OperationKinds::TasksPrep, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => $host->id, 'status' => OperationStatus::Succeeded,
        'input' => ['command' => 'firewall:deny']]);

    return [$group, $vm, $instance];
}

it('waits for the build of a parked group after a manual prep', function (): void {
    [$group] = buildWorld();

    expect(TaskBuildIntent::waiting($group))->toBeTrue();
});

it('waits for the build of a running group after an automatic prep, and of a group whose park was refused', function (TaskReviewMode $mode): void {
    [$group] = buildWorld($mode);
    $group->update(['status' => TaskGroupStatus::Running]);

    expect(TaskBuildIntent::waiting($group))->toBeTrue();
})->with([TaskReviewMode::Automatic, TaskReviewMode::Manual]);

it('never acts on a group that has more than its spec subtask, no prep run, or a build before', function (): void {
    [$group] = buildWorld();

    $extra = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Build', 'brief' => 'Build it', 'status' => TaskStatus::Todo]);
    $group->update(['status' => TaskGroupStatus::Running]);
    expect(TaskBuildIntent::waiting($group))->toBeFalse();

    $extra->delete();
    expect(TaskBuildIntent::waiting($group))->toBeTrue();

    $build = Operation::query()->create(['kind' => OperationKinds::TasksBuild, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null, 'status' => OperationStatus::Succeeded, 'input' => []]);
    expect(TaskBuildIntent::waiting($group))->toBeFalse();

    $build->delete();
    Operation::query()->where('kind', OperationKinds::TasksPrep)->delete();
    expect(TaskBuildIntent::waiting($group))->toBeFalse();
});

it('appends the build subtask, moves the group to running and records the whole intent of one run', function (): void {
    [$group, $vm] = buildWorld(vmState: TaskVmState::Parked);

    // Swamp is not there: the admission is recorded, and the start is refused after it.
    expect(fn () => app(TaskBuildIntent::class)->start($group, BUILD_HEAD, 'test', null))->toThrow(ResourceOperationException::class);

    $operation = Operation::query()->where('kind', OperationKinds::TasksBuild)->sole();
    $subtask = Task::query()->where('parent_id', $group->id)->where('position', 2)->sole();
    $prompts = TaskPrepPrompts::for('firewall:deny');

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($subtask->title)->toBe('Build firewall:deny')
        ->and($subtask->status)->toBe(TaskStatus::Todo)
        ->and($subtask->brief)->toContain(BUILD_HEAD, 'docs/cli/firewall/deny.mmd', 'docs/cli/firewall/deny.mdx', 'swamp/proof/firewall/deny.cases.yaml', 'task-'.$group->id)
        ->and(array_column($subtask->deliverables, 'id'))->toBe(['runner-plan'])
        ->and($subtask->deliverables[0]['command'])->toBe($prompts->plan())
        ->and($subtask->implementer_agent_thread_id)->not->toBeNull()
        ->and($operation->input['approval'])->toBe(['head' => BUILD_HEAD, 'mode' => 'manual', 'reviewer' => TaskPullRequestEvidence::reviewer()])
        ->and($operation->input['subtask'])->toBe($subtask->id)
        ->and($operation->input['resume']['task_vm_id'])->toBe($vm->id)
        ->and($operation->input['task_vm_id'])->toBe($vm->id)
        ->and($operation->input['resume']['provider_input']['name'])->toBe('shark-tvm-2')
        ->and($operation->input['resume']['mode'])->toBe('resume')
        ->and($operation->input['sync']['workspace']['branch'])->toBe('task-'.$group->id)
        ->and($operation->input['turn'])->toMatchArray(['subtask' => $subtask->id, 'role' => 'implementer', 'new_session' => true, 'thread_id' => $subtask->implementer_agent_thread_id])
        ->and($operation->input['turn']['prompt'])->toStartWith("Subtask: Build firewall:deny\n\nBrief:\nThe owner approved the spec")->toContain('Deliverables:', 'Prompt of this turn:')
        ->and($operation->input['execution_secrets'])->toBe(['pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id]]);
});

it('keeps a Project-check review deliverable when the Project has no check command', function (): void {
    [$group] = buildWorld();
    $group->project->update(['task_check' => null]);

    expect(fn () => app(TaskBuildIntent::class)->start($group, BUILD_HEAD, 'test', null))->toThrow(ResourceOperationException::class);

    $subtask = Task::query()->where('parent_id', $group->id)->where('position', 2)->sole();

    expect(array_column($subtask->deliverables, 'id'))->toBe(['runner-plan', 'project-check'])
        ->and($subtask->deliverables[1]['type'])->toBe('review')
        ->and($subtask->deliverables[1])->not->toHaveKey('command');
});

it('builds a group with a ready slot VM without capturing a resume', function (TaskReviewMode $mode): void {
    [$group, $vm] = buildWorld($mode);

    expect(fn () => app(TaskBuildIntent::class)->start($group, BUILD_HEAD, 'test', null))->toThrow(ResourceOperationException::class);

    $operation = Operation::query()->where('kind', OperationKinds::TasksBuild)->sole();

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($operation->input['approval'])->toBe(['head' => BUILD_HEAD, 'mode' => $mode->value, 'reviewer' => $mode === TaskReviewMode::Manual ? TaskPullRequestEvidence::reviewer() : null])
        ->and($operation->input['task_vm_id'])->toBe($vm->id)
        ->and($operation->input)->not->toHaveKey('resume')
        ->and($operation->input)->not->toHaveKey('resume_secrets');

    $input = app(TaskBuildProjection::class)->input($operation);
    expect($input['intent'])->not->toHaveKey('resume')
        ->and($input['intent']['task_vm_id'])->toBe($vm->id)
        ->and(json_encode($input['resume_secrets']))->toBe('{}');
})->with([TaskReviewMode::Automatic, TaskReviewMode::Manual]);

it('refuses a build that has no workspace of its own and leaves the group as it was', function (): void {
    [$group] = buildWorld();
    $group->update(['taskable_type' => null, 'taskable_id' => null]);

    expect(fn () => app(TaskBuildIntent::class)->start($group, BUILD_HEAD, 'test', null))->toThrow(ResourceOperationException::class, 'owned task VM workspace')
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and(Task::query()->where('parent_id', $group->id)->count())->toBe(1)
        ->and(Operation::query()->where('kind', OperationKinds::TasksBuild)->count())->toBe(0);
});

it('waits for deletion before a build can claim its next VM', function (): void {
    Queue::fake();
    [$group] = buildWorld(vmState: TaskVmState::Releasing);
    $operation = app(TaskBuildIntent::class)->start($group, BUILD_HEAD, 'test', null);
    expect($operation->status)->toBe(OperationStatus::Waiting)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and(Task::query()->where('parent_id', $group->id)->count())->toBe(1)
        ->and(AgentThread::query()->count())->toBe(0);
});

it('clears the settled time of the group when the build starts', function (): void {
    [$group] = buildWorld();
    $group->update(['settled_at' => now()->subHour()]);

    expect(fn () => app(TaskBuildIntent::class)->start($group, BUILD_HEAD, 'test', null))->toThrow(ResourceOperationException::class);

    expect($group->fresh()->settled_at)->toBeNull();
});

it('refuses a build for a group that already started one', function (): void {
    [$group] = buildWorld();
    Operation::query()->create(['kind' => OperationKinds::TasksBuild, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null, 'status' => OperationStatus::Succeeded, 'input' => []]);

    expect(fn () => app(TaskBuildIntent::class)->start($group, BUILD_HEAD, 'test', null))->toThrow(ResourceOperationException::class, 'build has not started');
});

/** A Swamp socket that accepts and records every vault put, and a GitHub that serves one read token. */
function buildSwampSocket(): object
{
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'push', 'push', 'hardimpactdev', 'organization', 'https://github.com/apps/push', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReadToken')->andReturn('read-token-value');
    }));
    $socket = new class implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        /** The vault put of a key with this prefix is refused. */
        public ?string $refuse = null;

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            if ($this->refuse !== null && str_starts_with((string) ($frame['payload']['key'] ?? ''), $this->refuse)) {
                return [['id' => $frame['id'], 'type' => 'error', 'error' => ['code' => 'vault_put_failed']]];
            }

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    return $socket;
}

/** A build run as the Gateway records it at admission. */
function buildRun(TaskVmState $state = TaskVmState::Parked): array
{
    [$group, $vm] = buildWorld(vmState: $state);
    $operation = Operation::query()->create(['kind' => OperationKinds::TasksBuild, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => $vm->host_node_id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'], 'input' => [
            'group_id' => $group->id, 'task_vm_id' => $vm->id, 'approval' => ['head' => BUILD_HEAD, 'mode' => 'manual', 'reviewer' => 'nick'],
            ...($state === TaskVmState::Parked ? ['resume' => ['task_vm_id' => $vm->id]] : []),
            'sync' => ['git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-sync-token-6b69badf-4f06-4f2e-82d7-21b62c1f587a']], 'turn' => ['thread_id' => 11],
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

it('records the VM as ready and the branch with the approved head when the run succeeded, and leaves the group as admission set it', function (): void {
    [$operation, $group, $vm] = buildRun();

    $projected = app(TaskBuildProjection::class)->apply($operation, buildEnd('succeeded', ['steps' => ['Resume the VM' => ['status' => 'succeeded']]]));

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['branch' => 'task-'.$group->id, 'head' => BUILD_HEAD])
        ->and($vm->refresh()->state)->toBe(TaskVmState::Ready)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview);
});

it('projects a build that skipped resume and keeps the running slot VM ready', function (): void {
    [$operation, $group, $vm] = buildRun(TaskVmState::Ready);

    $projected = app(TaskBuildProjection::class)->apply($operation, buildEnd('succeeded', ['steps' => ['Resume the VM' => ['status' => 'skipped']]]));

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['branch' => 'task-'.$group->id, 'head' => BUILD_HEAD])
        ->and($vm->refresh()->state)->toBe(TaskVmState::Ready);
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

it('reads the Operation without writing a credential, and writes the Pi token and the read token of the sync once at run start', function (TaskVmState $state): void {
    [$operation, , $vm] = buildRun($state);
    Project::query()->update(['source_access' => 'github_app']);
    $socket = buildSwampSocket();
    $projection = app(TaskBuildProjection::class);

    $read = $projection->input($operation);
    expect($socket->frames)->toBe([])
        ->and(array_keys($read['intent']))->toBe($state === TaskVmState::Parked ? ['task_vm_id', 'resume', 'sync', 'turn'] : ['task_vm_id', 'sync', 'turn'])
        ->and(json_encode($read['resume_secrets']))->toBe('{}');

    $start = app(DirectOperation::class)->workflowInputs($operation);
    $puts = array_map(static fn (array $frame): array => [$frame['payload']['key'], $frame['payload']['value']], $socket->frames);

    expect($puts)->toBe([
        ['task-vm-pi-token-'.$vm->id, str_repeat('p', 64)],
        ['task-sync-token-6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'Authorization: Basic '.base64_encode('x-access-token:read-token-value')],
    ])
        ->and($start['execution_secrets'])->toBe(['pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id]]);

    $vm->update(['pi_token' => null]);
    expect(fn () => $projection->workflowInput($operation))->toThrow(ResourceOperationException::class, 'Pi credential');
})->with([TaskVmState::Parked, TaskVmState::Ready]);

it('fails a sync and a build run closed when the read-token vault write fails, and starts no run', function (string $kind): void {
    [$operation, , $vm] = buildRun();
    Project::query()->update(['source_access' => 'github_app']);
    $socket = buildSwampSocket();
    $socket->refuse = 'task-sync-token-';
    $reference = ['vault' => 'orbit-control-inputs', 'key' => 'task-sync-token-6b69badf-4f06-4f2e-82d7-21b62c1f587a'];
    $operation->forceFill(['kind' => $kind, 'status' => OperationStatus::Queued, 'state' => [], 'cancel_requested_at' => null,
        'input' => $kind === OperationKinds::TasksSync ? ['git_authorization' => $reference] : $operation->input])->save();

    expect(fn () => app(StartSwampRun::class)->start($operation))->toThrow(ResourceOperationException::class, 'could not be stored');

    $failed = $operation->fresh();

    expect($failed->status)->toBe(OperationStatus::Failed)
        ->and($failed->error_code)->toBe('swamp.vault_sync_unavailable')
        ->and($failed->result['error']['code'] ?? null)->toBe('swamp.vault_sync_unavailable')
        ->and($failed->result['error']['message'] ?? '')->toContain('could not be stored')
        // Every Operation read shows the refusal as its error detail.
        ->and($failed->state['native_failure']['detail'] ?? '')->toContain('could not be stored')
        ->and($failed->dispatched_at)->toBeNull()
        ->and($failed->finished_at)->not->toBeNull()
        // The Pi token goes first in a build run; the read token is the write that fails.
        ->and(array_column(array_column($socket->frames, 'payload'), 'key'))->toBe($kind === OperationKinds::TasksBuild ? ['task-vm-pi-token-'.$vm->id, $reference['key']] : [$reference['key']])
        ->and(array_column($socket->frames, 'type'))->not->toContain('workflow.run');
})->with([OperationKinds::TasksSync, OperationKinds::TasksBuild]);

it('starts the build from the gate on an approved head, appends the subtask once and admits nothing on the next tick', function (): void {
    config(['task_pull_requests.reviewer' => 'nick']);
    [$group] = buildWorld();
    app(TaskExtensionState::class)->enable();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'gate', 'gate', 'hardimpactdev', 'organization', 'https://github.com/apps/gate', 'unused'));
    $pull = new GitHubPullRequest(GitHubPullRequestState::Open, true, 'clean', BUILD_HEAD, 'main', draft: true, authorLogin: 'orbit-app[bot]');
    $review = new GitHubReview(1, 1, 'nick', GitHubReviewState::Approved, BUILD_HEAD, new DateTimeImmutable('2026-11-06T10:00:00Z'), 'https://example.test', '');
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($pull, $review): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReviewsToken')->andReturn('reviews-token');
        $github->shouldReceive('pullRequest')->andReturn($pull);
        $github->shouldReceive('reviews')->andReturn([$review]);
    }));
    $group->project->update(['source_access' => 'github_app', 'review_and_merge' => true, 'merge_check' => 'Required checks']);
    $group->update(['watched_pr_number' => 7, 'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/7']);

    // Swamp is not there: the start is refused after the admission was recorded.
    $first = app(RunTaskPullRequestGateAction::class)->execute();
    $operation = Operation::query()->where('kind', OperationKinds::TasksBuild)->sole();

    expect($first)->toHaveCount(1)->and($first[0])->toMatchArray(['group' => $group->id, 'step' => 'build'])
        ->and($operation->idempotency_key)->toBe('pr-gate:build:'.$group->id.':'.BUILD_HEAD)
        ->and($operation->input['approval'])->toMatchArray(['head' => BUILD_HEAD, 'mode' => 'manual', 'reviewer' => 'nick'])
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and(Task::query()->where('parent_id', $group->id)->count())->toBe(2);

    $again = app(RunTaskPullRequestGateAction::class)->execute();

    expect($again[0]['step'])->not->toBe('build')
        ->and(Task::query()->where('parent_id', $group->id)->count())->toBe(2)
        ->and(Operation::query()->where('kind', OperationKinds::TasksBuild)->count())->toBe(1);
});

it('lets a build retry after an expired or cancelled admission but blocks a live or succeeded build', function (OperationStatus $status, bool $waiting): void {
    [$group] = buildWorld();
    Operation::query()->create(['kind' => OperationKinds::TasksBuild, 'target_type' => OperationKinds::TaskGroupTarget,
        'target_id' => $group->id, 'status' => $status, 'input' => ['capacity_request' => ['head' => str_repeat('a', 40)]]]);

    expect(TaskBuildIntent::waiting($group))->toBe($waiting);
})->with([[OperationStatus::Failed, true], [OperationStatus::Cancelled, true], [OperationStatus::Interrupted, true],
    [OperationStatus::Waiting, false], [OperationStatus::Queued, false], [OperationStatus::Running, false], [OperationStatus::Succeeded, false]]);

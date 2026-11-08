<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckProjection;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPrepThreads;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\Tasks\TaskReviewMode;
use App\Domain\Tasks\TaskReviewRoundIntent;
use App\Domain\Tasks\TaskReviewRoundProjection;
use App\Domain\Tasks\TaskReviewRoundVerdict;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskReviewRound;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

const ROUND_HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

/**
 * A group in automatic mode with a parked-or-running task VM, its workspace, a subtask with an author thread, a passed handoff candidate
 * on the head, and a GitHub that serves an open pull request out of draft with a green merge check.
 *
 * @return array{0: Task, 1: Task, 2: TaskVm, 3: Instance}
 */
function roundWorld(TaskVmState $state = TaskVmState::Ready): array
{
    SwampNodeFixture::ensure();
    app(TaskExtensionState::class)->enable();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'gate', 'gate', 'hardimpactdev', 'organization', 'https://github.com/apps/gate', 'unused'));
    $pull = new GitHubPullRequest(GitHubPullRequestState::Open, true, 'clean', ROUND_HEAD, 'main', draft: false, authorLogin: 'orbit-app[bot]');
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($pull): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReviewsToken')->andReturn('reviews-token');
        $github->shouldReceive('repositoryChecksToken')->andReturn('checks-token');
        $github->shouldReceive('pullRequest')->andReturn($pull);
        $github->shouldReceive('checkRuns')->andReturn([new GitHubCheckRun('Required checks', 'success', null, status: 'completed', headSha: ROUND_HEAD, appSlug: 'github-actions', outputSummary: 'private raw output')]);
    }));
    $host = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'tvm-host', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.11', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.11', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'tvm-598', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.137', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.137', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, null, '10.44.0.128/25', [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 5, 4, '8GiB', '40GiB')],
        '/pi', str_repeat('a', 64), ['gpt-6']));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public',
        'source_access' => 'github_app', 'merge_check' => 'Required checks', 'review_and_merge' => true]);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Round', 'brief' => 'Round it', 'status' => TaskGroupStatus::WaitingForReview,
        'orchestration' => TaskOrchestration::External, 'watched_pr_number' => 7, 'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/7',
        'review_mode' => TaskReviewMode::Automatic, 'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $vm = TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-598',
        'state' => TaskVmState::Ready, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.137', 'size' => TaskVmSize::Normal, 'pi_token' => str_repeat('p', 64)]);
    $vm->update(['state' => $state, 'name' => $state === TaskVmState::Parked ? 'tvm-'.$vm->id : 'shark-tvm-1', 'slot_number' => $state === TaskVmState::Ready ? 1 : null]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Active]);
    $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id]);
    $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Build it', 'brief' => 'Build it as the chart shows.', 'status' => 'running',
        'deliverables' => [['id' => 'plan', 'type' => 'command', 'description' => 'The plan passes.', 'command' => 'make plan', 'directory' => '.']]]);
    $subtask->update(['implementer_agent_thread_id' => TaskPrepThreads::create($group, $subtask, $instance, 'implementer')->id]);

    return [$group, $subtask, $vm, $instance];
}

/** A passed handoff check of the subtask on the head, with the check operation that records it. */
function roundCandidate(Task $group, Task $subtask, string $head = ROUND_HEAD): TaskCheck
{
    $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'pid' => 1, 'process_started' => 'x',
        'head_before' => str_repeat('1', 40), 'tree_before' => str_repeat('2', 40), 'head_after' => str_repeat('3', 40), 'tree_after' => str_repeat('4', 40),
        'commit_sha' => $head, 'exit_code' => 0, 'output' => 'private check output', 'started_at' => now(), 'finished_at' => now()]);
    Operation::query()->create(['kind' => OperationKinds::TasksCheck, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'input' => ['subtask' => $subtask->id, 'phase' => 'handoff', 'check_id' => $check->id], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
        'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $head, 'head' => $check->head_after, 'tree' => $check->tree_after,
            'ci' => ['state' => 'passed', 'fault' => null, 'commit' => $head, 'branch' => TaskRemoteBranch::handoff($group), 'check_name' => 'Required checks']]]);

    return $check;
}

/** Admits a round; the Swamp start is not configured in a test, so the admission is recorded and then refuses at the start. */
function admitRound(Task $group, string $head = ROUND_HEAD): Operation
{
    expect(fn () => app(TaskReviewRoundIntent::class)->start($group, $head, 'test', null))->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('swamp.start_unavailable'));

    return Operation::query()->where('kind', OperationKinds::TasksReviewRound)->latest('id')->firstOrFail();
}

it('admits one round with the review, fix and push, resuming only a parked VM', function (TaskVmState $state): void {
    [$group, $subtask, $vm, $instance] = roundWorld($state);
    roundCandidate($group, $subtask);

    $operation = admitRound($group);
    $input = $operation->input;
    $round = TaskReviewRound::query()->sole();

    expect($operation->kind)->toBe('tasks.review_round')
        ->and($operation->node_id)->toBe($instance->node_id)
        ->and($input)->toMatchArray(['round' => 1, 'head' => ROUND_HEAD, 'group_id' => $group->id, 'repository' => 'hardimpactdev/orbit', 'pr_number' => 7])
        ->and($input['task_vm_id'])->toBe($vm->id)
        ->and(isset($input['resume']))->toBe($state === TaskVmState::Parked)
        ->and($input['resume']['task_vm_id'] ?? null)->toBe($state === TaskVmState::Parked ? $vm->id : null)
        ->and([$input['review']['role'], $input['review']['new_session'], $input['review']['candidate']])->toBe(['reviewer', true, ROUND_HEAD])
        ->and([$input['fix']['role'], $input['fix']['new_session'], $input['fix']['thread_id']])->toBe(['implementer', false, $subtask->fresh()->implementer_agent_thread_id])
        ->and($input['fix']['prompt'])->toContain('round 1')
        ->and([$input['check']['phase'], $input['check']['check_name'], $input['check']['execution_secrets']])->toBe(['handoff', null, []])
        ->and($input['publish'])->toMatchArray(['branch' => 'task-'.$group->id, 'pull_head' => 'hardimpactdev:task-'.$group->id, 'checkout_path' => $instance->checkout_path])
        ->and($input['execution_secrets']['pi_token'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id])
        ->and($input['execution_secrets']['write_token']['key'])->toStartWith('pr-comment-write-')
        ->and($input['publish_secrets']['github_token']['key'])->toStartWith('task-publish-token-')
        ->and(json_encode($input))->not->toContain(str_repeat('p', 64))
        ->and($input['review'])->not->toHaveKey('execution_secrets')
        ->and([$round->head_sha, $round->round, $round->operation_id, $round->check_id])->toBe([ROUND_HEAD, 1, $operation->id, $input['check']['check_id']])
        // A test has no Swamp, so the start is refused, and the refusal ends the round and closes its check row.
        ->and([$round->verdict, TaskCheck::query()->findOrFail($round->check_id)->status])->toBe([TaskReviewRoundVerdict::Failed, TaskCheckStatus::Cancelled])
        ->and(AgentThread::query()->where('role', 'reviewer')->count())->toBe(1);
})->with([TaskVmState::Ready, TaskVmState::Parked]);

it('captures only public check results bound to the exact reviewed head and handoff tree', function (): void {
    [$group, $subtask] = roundWorld();
    $check = roundCandidate($group, $subtask);

    $input = admitRound($group)->input;

    expect($input['review_evidence'])->toBe([
        'head' => ROUND_HEAD, 'tree' => str_repeat('4', 40),
        'handoff' => ['check_id' => $check->id, 'outcome' => 'passed', 'commit' => ROUND_HEAD, 'tree' => str_repeat('4', 40), 'exit_code' => 0],
        'hosted_checks' => [['name' => 'Required checks', 'conclusion' => 'success', 'status' => 'completed', 'head' => ROUND_HEAD, 'app' => 'github-actions']],
    ]);
    expect(json_encode($input['review_evidence']))->not->toContain('private raw output', 'private check output', 'checks-token', 'reviews-token');
});

it('captures no fix, check or check row in round 3, because findings there end the run', function (): void {
    [$group, $subtask] = roundWorld();
    roundCandidate($group, $subtask);
    TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => str_repeat('1', 40), 'round' => 1, 'verdict' => TaskReviewRoundVerdict::Findings, 'pushed_commit' => str_repeat('2', 40)]);
    TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => str_repeat('2', 40), 'round' => 2, 'verdict' => TaskReviewRoundVerdict::Findings, 'pushed_commit' => ROUND_HEAD]);
    $checks = TaskCheck::query()->count();

    $operation = admitRound($group);

    expect($operation->input['round'])->toBe(3)
        ->and($operation->input)->not->toHaveKeys(['fix', 'check'])
        ->and(TaskReviewRound::query()->where('round', 3)->sole()->check_id)->toBeNull()
        ->and(TaskCheck::query()->count())->toBe($checks);
});

it('admits an earlier pushed fix with its passed local check and refuses a missing receipt', function (): void {
    [$group, $subtask] = roundWorld();

    expect(fn () => app(TaskReviewRoundIntent::class)->start($group, ROUND_HEAD, 'test', null))
        ->toThrow(ResourceOperationException::class, 'passed handoff candidate');

    $round = TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => str_repeat('1', 40), 'round' => 1, 'verdict' => TaskReviewRoundVerdict::Findings, 'pushed_commit' => ROUND_HEAD]);
    expect(fn () => app(TaskReviewRoundIntent::class)->start($group, ROUND_HEAD, 'test', null))
        ->toThrow(ResourceOperationException::class, 'passed handoff candidate');
    $check = roundCandidate($group, $subtask);
    Operation::query()->where('kind', OperationKinds::TasksCheck)->delete();
    $round->update(['check_id' => $check->id]);

    $input = admitRound($group)->input;
    expect($input['round'])->toBe(2)
        ->and($input['review_evidence']['handoff']['check_id'])->toBe($check->id);
});

it('refuses a fourth round and a group whose task VM is neither parked nor running', function (): void {
    [$group, $subtask, $vm] = roundWorld();
    roundCandidate($group, $subtask);
    $vm->update(['state' => TaskVmState::Failed]);

    expect(fn () => app(TaskReviewRoundIntent::class)->start($group, ROUND_HEAD, 'test', null))->toThrow(ResourceOperationException::class, 'parked or running');

    $vm->update(['state' => TaskVmState::Ready]);
    foreach ([1, 2, 3] as $round) {
        TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => str_repeat((string) $round, 40), 'round' => $round, 'verdict' => TaskReviewRoundVerdict::Findings]);
    }

    expect(fn () => app(TaskReviewRoundIntent::class)->start($group, ROUND_HEAD, 'test', null))->toThrow(ResourceOperationException::class, 'used its review rounds');
});

/** An admitted round whose run is going on, with its operation set as the stored run. */
function runningRound(TaskVmState $state = TaskVmState::Parked): array
{
    [$group, $subtask, $vm, $instance] = roundWorld($state);
    roundCandidate($group, $subtask);
    $operation = admitRound($group);
    $operation->update(['status' => OperationStatus::Running, 'error_code' => null, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a']]);

    return [$operation->refresh(), $group, $vm, $subtask, TaskReviewRound::query()->sole()];
}

/** The terminal event of a round run, with the captured steps of the workflow. */
function roundEnd(string $status, array $result, array $failure = []): array
{
    return ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => $status, 'started_at' => now()->subMinutes(20)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['summary' => ['status' => $status, 'failed_steps' => $failure === [] ? [] : [$failure['failed_step']]], ...$result], ...($failure === [] ? [] : ['failure' => $failure])];
}

/** The shape of a finished turn as the parent run keeps it. */
function roundTurn(string $outcome, string $summary, int $tokens): array
{
    return ['shape_result' => ['result_json' => json_encode(['state' => 'done', 'receipt' => ['outcome' => $outcome, 'summary' => $summary], 'metrics' => ['tokens' => $tokens]])]];
}

it('reads the Operation without writing the Pi token, writes it once at run start, and gives the check workflows their inputs', function (TaskVmState $state): void {
    [$operation, , $vm] = runningRound($state);
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
    $projection = app(TaskReviewRoundProjection::class);

    $read = $projection->input($operation);

    expect($writes->calls)->toBe([])
        ->and(array_keys($read['intent']))->toEqualCanonicalizing(['project_id', 'group_id', 'repository', 'pr_number', 'round', 'head', 'task_vm_id', 'review_evidence', ...($state === TaskVmState::Parked ? ['resume'] : []), 'review', 'fix', 'check', 'publish'])
        ->and($read['intent']['check'])->toHaveKeys(['command_deliverables', 'execution_references_json'])->not->toHaveKey('execution_secrets')
        ->and($read['intent']['check']['execution_references_json'])->toBe('{}')
        ->and(json_encode($read['resume_secrets']))->toBe('{}');

    $start = $projection->workflowInput($operation);

    expect($writes->calls)->toBe([['orbit-control-inputs', 'task-vm-pi-token-'.$vm->id, str_repeat('p', 64)]])
        ->and($start['execution_secrets']['pi_token'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id])
        ->and($start['execution_secrets']['write_token']['key'])->toStartWith('pr-comment-write-');

    $vm->update(['pi_token' => null]);
    expect(fn () => $projection->workflowInput($operation))->toThrow(ResourceOperationException::class, 'Pi credential');
})->with([TaskVmState::Ready, TaskVmState::Parked]);

it('records a pass: the verdict, the thread metrics, the VM as releasing, and the check row as cancelled', function (TaskVmState $state): void {
    [$operation, , $vm, , $round] = runningRound($state);
    $thread = AgentThread::query()->findOrFail($operation->input['review']['thread_id']);

    $projected = app(TaskReviewRoundProjection::class)->apply($operation, roundEnd('succeeded', [
        'steps' => ['Resume the VM' => ['status' => $state === TaskVmState::Parked ? 'succeeded' : 'skipped'], 'Push the fix' => ['status' => 'skipped']],
        'review' => roundTurn('approved', 'Nothing to fix.', 40), 'verdict' => 'pass', 'findings' => '',
    ]));

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['round' => 1, 'verdict' => 'pass', 'commit' => null])
        ->and($round->refresh()->verdict)->toBe(TaskReviewRoundVerdict::Pass)
        ->and([$round->summary, $round->pushed_commit])->toBe(['Nothing to fix.', null])
        ->and($thread->refresh()->tokens)->toBe(40)
        ->and($vm->refresh()->state)->toBe(TaskVmState::Releasing)
        ->and(TaskCheck::query()->findOrFail($round->check_id)->status)->toBe(TaskCheckStatus::Cancelled);
})->with([TaskVmState::Ready, TaskVmState::Parked]);

it('records the findings, the local check of the fix and the pushed commit of a round that fixed them', function (): void {
    [$operation, , , , $round] = runningRound();
    $commit = str_repeat('c', 40);
    $checked = ['outcome' => 'passed', 'check_id' => $round->check_id, 'commit' => $commit, 'head' => str_repeat('d', 40), 'tree' => str_repeat('e', 40), 'head_before' => ROUND_HEAD, 'tree_before' => str_repeat('f', 40)];

    $projected = app(TaskReviewRoundProjection::class)->apply($operation, roundEnd('succeeded', [
        'steps' => ['Resume the VM' => ['status' => 'succeeded'], 'Check the fix' => ['status' => 'succeeded'], 'Push the fix' => ['status' => 'succeeded']],
        'review' => roundTurn('changes_requested', 'docs/a.md: missing case', 40), 'fix' => roundTurn('ready_for_review', 'Fixed.', 60),
        'verdict' => 'findings', 'findings' => 'docs/a.md: missing case', 'checked' => json_encode($checked), 'commit' => $commit,
    ]));
    $check = TaskCheck::query()->findOrFail($round->check_id);

    expect($projected->result)->toBe(['round' => 1, 'verdict' => 'findings', 'commit' => $commit])
        ->and($round->refresh()->verdict)->toBe(TaskReviewRoundVerdict::Findings)
        ->and([$round->pushed_commit, $round->summary])->toBe([$commit, 'docs/a.md: missing case'])
        ->and([$check->status, $check->commit_sha, $check->head_after, $check->tree_after])->toBe([TaskCheckStatus::Passed, $commit, str_repeat('d', 40), str_repeat('e', 40)])
        ->and(AgentThread::query()->findOrFail($operation->input['fix']['thread_id'])->tokens)->toBe(60);
});

it('keeps findings when the fix failed, never a pass when the run ended in error, and names the failed step', function (string $step, string $code, array $steps, array $result, string $verdict, string $check): void {
    [$operation, , , , $round] = runningRound();

    $projected = app(TaskReviewRoundProjection::class)->apply($operation, roundEnd('failed', ['steps' => ['Resume the VM' => ['status' => 'succeeded'], ...$steps], ...$result],
        ['failed_step' => $step, 'detail' => $step, 'stderr_recorded' => false]));

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe($code)
        ->and($projected->result)->toMatchArray(['error_code' => $code, 'failed_step' => $step, 'verdict' => $verdict])
        ->and($round->refresh()->verdict->value)->toBe($verdict)
        ->and($round->pushed_commit)->toBeNull()
        ->and(TaskCheck::query()->findOrFail($round->check_id)->status->value)->toBe($check);
})->with([
    'the fix turn did not end ready' => ['Check the fix turn', 'tasks.review_fix_not_ready', [], ['verdict' => 'findings', 'findings' => 'x: y'], 'findings', 'cancelled'],
    'the check did not pass' => ['Check the fix passed', 'tasks.review_fix_not_passed', ['Check the fix' => ['status' => 'succeeded']], ['verdict' => 'findings', 'findings' => 'x: y', 'checked' => json_encode(['outcome' => 'failed', 'commit' => null])], 'findings', 'failed'],
    'a pass followed by a failed run' => ['Clean up the token', 'tasks.review_round_failed', [], ['verdict' => 'pass', 'findings' => ''], 'failed', 'cancelled'],
    'no verdict at all' => ['Review the head', 'tasks.turn_failed', [], [], 'failed', 'cancelled'],
    'a check that left no result' => ['Check the fix', 'tasks.review_fix_not_passed', ['Check the fix' => ['status' => 'failed']], ['verdict' => 'findings', 'findings' => 'x: y'], 'findings', 'failed'],
]);

it('projects the terminal event of a round once', function (): void {
    [$operation, , , , $round] = runningRound();
    $end = roundEnd('succeeded', ['steps' => [], 'review' => roundTurn('approved', 'Fine.', 5), 'verdict' => 'pass', 'findings' => '']);

    $projected = app(TaskReviewRoundProjection::class)->apply($operation, $end);
    $round->update(['summary' => 'edited']);
    $again = app(TaskReviewRoundProjection::class)->apply($projected, $end);

    expect($again->result)->toBe($projected->result)
        ->and($round->refresh()->summary)->toBe('edited');
});

it('writes the result of a check onto its row the way the check operation does', function (): void {
    [, $subtask] = roundWorld();
    $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running, 'pid' => 0, 'process_started' => '', 'head_before' => '', 'tree_before' => '', 'started_at' => now()]);

    TaskCheckProjection::record($check, 'changed', ['head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40), 'output' => 'x', 'commit' => null]);

    expect($check->refresh()->status)->toBe(TaskCheckStatus::Changed)
        ->and([$check->head_after, $check->output])->toBe([str_repeat('a', 40), 'x'])
        ->and($check->finished_at)->not->toBeNull();
});

it('replays the admission of the same key and records one round', function (): void {
    [$group, $subtask] = roundWorld();
    roundCandidate($group, $subtask);
    $admit = fn (): Operation => app(OperationIdempotency::class)->under(OperationKinds::TasksReviewRound, 'pr-gate:review:'.$group->id.':'.ROUND_HEAD, 'fingerprint', fn (): Operation => app(TaskReviewRoundIntent::class)->start($group, ROUND_HEAD, 'test', null));
    $first = null;
    try {
        $admit();
    } catch (ResourceOperationException) {
        $first = Operation::query()->where('kind', OperationKinds::TasksReviewRound)->sole();
    }

    expect($admit()->id)->toBe($first->id)
        ->and(Operation::query()->where('kind', OperationKinds::TasksReviewRound)->count())->toBe(1)
        ->and(TaskReviewRound::query()->count())->toBe(1);
});

it('refuses a round that fixes when the current subtask has no author thread', function (): void {
    [$group, $subtask] = roundWorld();
    roundCandidate($group, $subtask);
    $subtask->update(['implementer_agent_thread_id' => null]);

    expect(fn () => app(TaskReviewRoundIntent::class)->start($group, ROUND_HEAD, 'test', null))->toThrow(ResourceOperationException::class, 'no author thread')
        ->and(TaskReviewRound::query()->count())->toBe(0);
});

it('names the failed push by its detail and keeps the findings', function (): void {
    [$operation, , , , $round] = runningRound();

    $pushed = app(TaskReviewRoundProjection::class)->apply($operation, roundEnd('failed', ['steps' => ['Resume the VM' => ['status' => 'succeeded'], 'Push the fix' => ['status' => 'failed']],
        'verdict' => 'findings', 'findings' => 'x: y'], ['failed_step' => 'Push the fix', 'detail' => 'tasks.not_fast_forward', 'stderr_recorded' => false]));

    expect($pushed->error_code)->toBe('tasks.not_fast_forward')
        ->and($round->refresh()->verdict)->toBe(TaskReviewRoundVerdict::Findings)
        ->and($round->pushed_commit)->toBeNull();
});

it('leaves the VM parked when the resume failed', function (): void {
    [$operation, , $vm] = runningRound();
    $resumed = app(TaskReviewRoundProjection::class)->apply($operation, roundEnd('failed', ['steps' => ['Resume the VM' => ['status' => 'failed']]],
        ['failed_step' => 'Resume the VM', 'detail' => 'x', 'stderr_recorded' => false]));

    expect($resumed->error_code)->toBe('task_vm.resume_failed')
        ->and($vm->refresh()->state)->toBe(TaskVmState::Parked);
});

it('ends a cancelled round as failed, so the gate check falls back instead of waiting', function (): void {
    [$operation, , , , $round] = runningRound();

    $projected = app(TaskReviewRoundProjection::class)->apply($operation, roundEnd('cancelled', ['steps' => []]));

    expect($projected->status)->toBe(OperationStatus::Cancelled)
        ->and($round->refresh()->verdict)->toBe(TaskReviewRoundVerdict::Failed)
        ->and(TaskCheck::query()->findOrFail($round->check_id)->status)->toBe(TaskCheckStatus::Cancelled);
});

it('ends the round as failed when the budget runs out or Swamp refuses the start, and a late event changes nothing', function (string $end): void {
    [$operation, , , , $round] = runningRound();
    $round->update(['verdict' => null]);

    app(TaskReviewRoundProjection::class)->{$end}($operation);

    expect($round->refresh()->verdict)->toBe(TaskReviewRoundVerdict::Failed)
        ->and(TaskCheck::query()->findOrFail($round->check_id)->status)->toBe(TaskCheckStatus::Cancelled);

    $round->update(['verdict' => TaskReviewRoundVerdict::Pass]);
    app(TaskReviewRoundProjection::class)->{$end}($operation);

    expect($round->refresh()->verdict)->toBe(TaskReviewRoundVerdict::Pass);
})->with(['timedOut', 'refused']);

it('sends the workflow an intent that its input schema accepts', function (): void {
    [$operation] = runningRound();
    $schema = Yaml::parseFile(base_path('../../swamp/workflows/workflow-operation-task-review-round.yaml'))['inputs']['properties'];
    $input = app(TaskReviewRoundProjection::class)->input($operation);

    expect(array_keys($input['intent']))->each->toBeIn(array_keys($schema['intent']['properties']))
        ->and(array_diff($schema['intent']['required'], array_keys($input['intent'])))->toBe([])
        ->and(array_keys($input['execution_secrets']))->toEqualCanonicalizing(array_keys($schema['execution_secrets']['properties']))
        ->and(array_keys($input['publish_secrets']))->toEqualCanonicalizing(array_keys($schema['publish_secrets']['properties']));
});

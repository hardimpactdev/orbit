<?php

declare(strict_types=1);

use App\Actions\Tasks\RunTaskPullRequestGateAction;
use App\Actions\Tasks\StartTaskPullRequestStepAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\Tasks\TaskReviewMode;
use App\Domain\Tasks\TaskReviewRoundVerdict;
use App\Domain\Tasks\TaskStatus;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskReviewRound;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

const GATE_HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

/** The Tasks extension on, the GitHub App stored, and a GitHub that serves one pull request and no check runs or reviews. */
function gateWorld(GitHubPullRequest $pull, bool $reviewAndMerge = true, bool $green = false, TaskReviewMode $review = TaskReviewMode::Manual, array $reviews = []): Task
{
    app(TaskExtensionState::class)->enable();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'gate', 'gate', 'hardimpactdev', 'organization', 'https://github.com/apps/gate', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($pull, $green, $reviews): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReviewsToken')->andReturn('reviews-token');
        $github->shouldReceive('repositoryChecksToken')->andReturn('checks-token');
        $github->shouldReceive('pullRequest')->andReturn($pull);
        $github->shouldReceive('checkRuns')->andReturn($green ? [new GitHubCheckRun('Required checks', 'success', null, status: 'completed', headSha: GATE_HEAD, appSlug: 'github-actions')] : []);
        $github->shouldReceive('reviews')->andReturn($reviews);
    }));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.',
        'source_access' => 'github_app', 'merge_check' => 'Required checks', 'review_and_merge' => $reviewAndMerge]);

    return Task::query()->create(['project_id' => $project->id, 'title' => 'Gate', 'brief' => 'Gate it', 'status' => TaskGroupStatus::WaitingForReview,
        'orchestration' => TaskOrchestration::External, 'watched_pr_number' => 7, 'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/7', 'review_mode' => $review]);
}

function gatePull(bool $draft, GitHubPullRequestState $state = GitHubPullRequestState::Open): GitHubPullRequest
{
    return new GitHubPullRequest($state, true, 'clean', GATE_HEAD, 'main', draft: $draft, authorLogin: 'orbit-app[bot]');
}

it('looks only at Projects with review and merge enabled', function (): void {
    gateWorld(gatePull(true), reviewAndMerge: false);

    expect(app(RunTaskPullRequestGateAction::class)->execute())->toBe([]);
});

it('looks at no group whose group or pull request has ended', function (): void {
    $group = gateWorld(gatePull(true));
    $group->forceFill(['watched_pr_completion' => 'merged'])->save();

    expect(app(RunTaskPullRequestGateAction::class)->execute())->toBe([]);
});

it('skips a pull request that is no longer open', function (): void {
    $group = gateWorld(gatePull(false, GitHubPullRequestState::Closed));

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => null, 'result' => 'skipped', 'code' => 'tasks_pr.no_pull_request', 'operation' => null]]);
});

/** A passed handoff check of the group on the head, with the check operation that records it. */
function gateChecked(Task $group, string $head = GATE_HEAD): TaskCheck
{
    $subtask = $group->tasks()->create(['title' => 'S', 'brief' => 'B']);
    $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'pid' => 1, 'process_started' => 'x',
        'head_before' => str_repeat('1', 40), 'tree_before' => str_repeat('2', 40), 'head_after' => str_repeat('3', 40), 'tree_after' => str_repeat('4', 40),
        'commit_sha' => $head, 'started_at' => now(), 'finished_at' => now()]);
    Operation::query()->create(['kind' => OperationKinds::TasksCheck, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'input' => ['subtask' => $subtask->id, 'phase' => 'handoff', 'check_id' => $check->id], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
        'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $head, 'head' => $check->head_after, 'tree' => $check->tree_after, 'ci' => ['state' => 'passed', 'fault' => null, 'commit' => $head, 'branch' => TaskRemoteBranch::handoff($group), 'check_name' => 'Required checks']]]);

    return $check;
}

it('leaves a draft alone while no passed handoff check is on its head, as for a spec-only pull request', function (): void {
    $group = gateWorld(gatePull(true), green: true);

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'ready', 'result' => 'skipped', 'code' => 'tasks_pr.no_checked_head', 'operation' => null]])
        ->and(Operation::query()->count())->toBe(0);
});

it('leaves a draft alone when the passed handoff check is on another commit', function (): void {
    $group = gateWorld(gatePull(true), green: true);
    gateChecked($group, str_repeat('b', 40));

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready', 'result' => 'skipped', 'code' => 'tasks_pr.no_checked_head']);
});

it('admits no ready step while the merge check has not passed on the head of a checked draft', function (): void {
    $group = gateWorld(gatePull(true));
    gateChecked($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'ready', 'result' => 'skipped', 'code' => 'tasks_pr.checks_not_passed', 'operation' => null]]);
});

it('tries the ready step for a checked draft with a green merge check', function (): void {
    $group = gateWorld(gatePull(true), green: true);
    gateChecked($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready', 'result' => 'skipped', 'code' => 'swamp.start_unavailable']);
});

it('admits no merge step while the check has not passed or the reviewer has not approved', function (): void {
    $group = gateWorld(gatePull(false));

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'merge', 'result' => 'skipped', 'code' => 'tasks_pr.checks_not_passed', 'operation' => null]])
        ->and(Operation::query()->count())->toBe(0);
});

it('replays the step that the key of the same head already recorded and leaves the identity of the request alone', function (): void {
    $group = gateWorld(gatePull(true));
    gateChecked($group);
    $existing = Operation::query()->count();
    $recorded = Operation::query()->create(['kind' => OperationKinds::TasksPrReady, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'idempotency_key' => 'pr-gate:ready:'.$group->id.':'.GATE_HEAD, 'idempotency_fingerprint' => hash('sha256', OperationKinds::TasksPrReady.'|'.$group->id.'|'.GATE_HEAD),
        'input' => ['head_sha' => GATE_HEAD], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1]);

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'ready', 'result' => 'replayed', 'code' => null, 'operation' => $recorded->id]])
        ->and(Operation::query()->count())->toBe($existing + 1)
        ->and(app(OperationIdempotency::class)->key)->toBeNull();
});

it('registers the gate check on the Gateway schedule every minute', function (): void {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan tasks:pr-gate/');
});

/** The comment of the round on the head already ended, as the key of the head records it. */
function gateCommented(Task $group, string $head = GATE_HEAD): void
{
    // An earlier gate pass may have admitted the comment under the same key and failed to start it; the comment ended on this head now.
    Operation::query()->updateOrCreate(['kind' => OperationKinds::TasksPrComment, 'idempotency_key' => 'pr-gate:comment:'.$group->id.':'.$head],
        ['target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
            'idempotency_fingerprint' => hash('sha256', OperationKinds::TasksPrComment.'|'.$group->id.'|'.$head),
            'input' => ['head_sha' => $head], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1]);
}

function gateRound(Task $group, int $round, ?TaskReviewRoundVerdict $verdict, string $head = GATE_HEAD): TaskReviewRound
{
    return TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => $head, 'round' => $round, 'verdict' => $verdict]);
}

it('tries a review round on a head out of draft in automatic mode, and the round needs a passed handoff candidate', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'review', 'result' => 'skipped', 'code' => 'tasks_pr.no_checked_candidate', 'operation' => null]]);
});

it('admits no review round while the merge check has not passed on the head', function (): void {
    $group = gateWorld(gatePull(false), review: TaskReviewMode::Automatic);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['group' => $group->id, 'step' => 'review', 'code' => 'tasks_pr.checks_not_passed']);
});

it('waits for a round that runs and starts the fix for findings that are not the third', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    $round = gateRound($group, 1, null);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'review', 'result' => 'skipped', 'code' => 'tasks_pr.round_running']);

    $round->update(['verdict' => TaskReviewRoundVerdict::Findings]);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'comment', 'result' => 'skipped', 'code' => 'swamp.start_unavailable']);

    gateCommented($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'fix-turn', 'result' => 'skipped', 'code' => 'tasks_pr.no_current_subtask'])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Automatic);
});

it('tries the merge step once the round on the exact head passed', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Pass);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'comment', 'result' => 'skipped', 'code' => 'swamp.start_unavailable']);

    gateCommented($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'merge', 'result' => 'skipped', 'code' => 'swamp.start_unavailable']);
});

it('refuses the automatic merge when no round passed the head', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Findings);
    gateRound($group, 2, TaskReviewRoundVerdict::Pass, str_repeat('b', 40));

    expect(fn () => app(StartTaskPullRequestStepAction::class)->merge($group, GATE_HEAD, 'test', (string) Str::uuid()))
        ->toThrow(ResourceOperationException::class, 'No review round passed this head.');
});

it('falls back to manual mode after round 3 with findings and runs the ready step again', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Findings, str_repeat('1', 40));
    gateRound($group, 2, TaskReviewRoundVerdict::Findings, str_repeat('2', 40));
    gateRound($group, 3, TaskReviewRoundVerdict::Findings);
    gateCommented($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready-manual', 'code' => 'swamp.start_unavailable'])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Manual);
});

it('falls back to manual mode when a round failed', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    gateRound($group, 1, TaskReviewRoundVerdict::Failed);
    gateCommented($group);

    app(RunTaskPullRequestGateAction::class)->execute();

    expect($group->fresh()->review_mode)->toBe(TaskReviewMode::Manual);
});

it('falls back to manual mode when a new head finds the three rounds used', function (): void {
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    foreach ([1, 2, 3] as $round) {
        gateRound($group, $round, TaskReviewRoundVerdict::Findings, str_repeat((string) $round, 40));
    }

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready-manual'])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Manual);
});

/** A task operation that ended with the given status and result, recorded on the fix of a round. */
function gateFixOperation(Task $group, string $kind, OperationStatus $status, array $result = []): Operation
{
    return Operation::query()->create(['kind' => $kind, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'input' => [], 'status' => $status, 'result' => $result, 'sequence' => 1, 'attempts' => 1]);
}

/** A group in automatic mode whose first round found something and whose comment ended. */
function gateFixWorld(array $fix = []): array
{
    $group = gateWorld(gatePull(false), green: true, review: TaskReviewMode::Automatic);
    $round = gateRound($group, 1, TaskReviewRoundVerdict::Findings);
    gateCommented($group);
    $round->update($fix);

    return [$group, $round];
}

it('waits while a step of the fix runs', function (): void {
    [$group, $round] = gateFixWorld();
    $running = gateFixOperation($group, OperationKinds::TasksTurn, OperationStatus::Running);
    $round->update(['fix_turn_operation_id' => $running->id]);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'fix-turn', 'result' => 'skipped', 'code' => 'tasks_pr.fix_running', 'operation' => $running->id]);
});

it('goes on to the handoff check when the author turn is ready for review', function (): void {
    [$group, $round] = gateFixWorld();
    $turn = gateFixOperation($group, OperationKinds::TasksTurn, OperationStatus::Succeeded, ['receipt' => ['outcome' => 'ready_for_review', 'summary' => 'Fixed.']]);
    $round->update(['fix_turn_operation_id' => $turn->id]);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'fix-check', 'result' => 'skipped', 'code' => 'tasks_pr.no_current_subtask']);
});

it('pushes the checked fix when the handoff check passed', function (): void {
    [$group, $round] = gateFixWorld();
    $turn = gateFixOperation($group, OperationKinds::TasksTurn, OperationStatus::Succeeded, ['receipt' => ['outcome' => 'ready_for_review', 'summary' => 'Fixed.']]);
    $check = gateFixOperation($group, OperationKinds::TasksCheck, OperationStatus::Succeeded, ['outcome' => 'passed', 'check_id' => 41]);
    $round->update(['fix_turn_operation_id' => $turn->id, 'fix_check_operation_id' => $check->id]);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'fix-publish', 'result' => 'skipped', 'code' => 'tasks.no_workspace']);
});

it('waits for the new head after the push', function (): void {
    [$group, $round] = gateFixWorld();
    $round->update([
        'fix_turn_operation_id' => gateFixOperation($group, OperationKinds::TasksTurn, OperationStatus::Succeeded, ['receipt' => ['outcome' => 'ready_for_review']])->id,
        'fix_check_operation_id' => gateFixOperation($group, OperationKinds::TasksCheck, OperationStatus::Succeeded, ['outcome' => 'passed', 'check_id' => 41])->id,
        'fix_publish_operation_id' => gateFixOperation($group, OperationKinds::TasksPublish, OperationStatus::Succeeded)->id,
    ]);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'fix-publish', 'result' => 'skipped', 'code' => 'tasks_pr.fix_pushed'])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Automatic);
});

it('falls back to manual mode when a step of the fix does not end well', function (OperationStatus $status, string $kind, array $result, string $column): void {
    [$group, $round] = gateFixWorld();
    $before = $column === 'fix_turn_operation_id' ? [] : ['fix_turn_operation_id' => gateFixOperation($group, OperationKinds::TasksTurn, OperationStatus::Succeeded, ['receipt' => ['outcome' => 'ready_for_review']])->id];
    if ($column === 'fix_publish_operation_id') {
        $before['fix_check_operation_id'] = gateFixOperation($group, OperationKinds::TasksCheck, OperationStatus::Succeeded, ['outcome' => 'passed', 'check_id' => 41])->id;
    }
    $round->update([...$before, $column => gateFixOperation($group, $kind, $status, $result)->id]);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready-manual'])
        ->and($group->fresh()->review_mode)->toBe(TaskReviewMode::Manual);
})->with([
    'turn failed' => [OperationStatus::Failed, OperationKinds::TasksTurn, [], 'fix_turn_operation_id'],
    'turn blocked' => [OperationStatus::Succeeded, OperationKinds::TasksTurn, ['receipt' => ['outcome' => 'blocked', 'summary' => 'x', 'question' => 'y']], 'fix_turn_operation_id'],
    'check failed' => [OperationStatus::Succeeded, OperationKinds::TasksCheck, ['outcome' => 'failed'], 'fix_check_operation_id'],
    'publish failed' => [OperationStatus::Failed, OperationKinds::TasksPublish, [], 'fix_publish_operation_id'],
]);

/** What a prep run leaves: its completed spec subtask and its succeeded run. */
function gateSpecDone(Task $group): void
{
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Spec', 'brief' => 'Draft the spec', 'status' => TaskStatus::Completed]);
    Operation::query()->create(['kind' => OperationKinds::TasksPrep, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'status' => OperationStatus::Succeeded, 'input' => ['command' => 'firewall:deny']]);
}

function gateReview(string $commit, GitHubReviewState $state = GitHubReviewState::Approved): GitHubReview
{
    return new GitHubReview(1, 1, 'nick', $state, $commit, new DateTimeImmutable('2026-11-06T10:00:00Z'), 'https://example.test', '');
}

it('waits for the approval of the head of a parked spec before it admits the build', function (): void {
    config(['task_pull_requests.reviewer' => 'nick']);
    $group = gateWorld(gatePull(true));
    gateSpecDone($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute())
        ->toBe([['group' => $group->id, 'pull_request' => 7, 'step' => 'build', 'result' => 'skipped', 'code' => 'tasks_pr.approval_missing', 'operation' => null]])
        ->and(Operation::query()->where('kind', OperationKinds::TasksBuild)->count())->toBe(0);
});

it('does not count the approval of another head or a request for changes', function (GitHubReview $review): void {
    config(['task_pull_requests.reviewer' => 'nick']);
    $group = gateWorld(gatePull(true), reviews: [$review]);
    gateSpecDone($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'build', 'result' => 'skipped', 'code' => 'tasks_pr.approval_missing']);
})->with([
    'an older head' => [fn () => gateReview(str_repeat('b', 40))],
    'changes requested' => [fn () => gateReview(GATE_HEAD, GitHubReviewState::ChangesRequested)],
]);

it('reaches the admission of the build once the reviewer approved the exact head, and a refusal there leaves the group parked', function (): void {
    config(['task_pull_requests.reviewer' => 'nick']);
    $group = gateWorld(gatePull(true), reviews: [gateReview(GATE_HEAD)]);
    gateSpecDone($group);

    // The group has no task VM workspace here, so the admission refuses after the approval was read.
    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'build', 'result' => 'skipped', 'code' => 'tasks.build_refused'])
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and(Task::query()->where('parent_id', $group->id)->count())->toBe(1);
});

it('reaches the admission of the build for an automatic group with no review read', function (): void {
    $group = gateWorld(gatePull(true), review: TaskReviewMode::Automatic);
    $group->update(['status' => TaskGroupStatus::Running]);
    gateSpecDone($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'build', 'result' => 'skipped', 'code' => 'tasks.build_refused']);
});

it('leaves a draft alone when its group built before: a failed build is not admitted again', function (): void {
    $running = gateWorld(gatePull(true));
    gateSpecDone($running);
    Operation::query()->create(['kind' => OperationKinds::TasksBuild, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $running->id, 'node_id' => null, 'status' => OperationStatus::Failed, 'input' => []]);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'ready', 'code' => 'tasks_pr.no_checked_head']);
});

it('never builds from a pull request that is out of draft', function (): void {
    $group = gateWorld(gatePull(false));
    gateSpecDone($group);

    expect(app(RunTaskPullRequestGateAction::class)->execute()[0])->toMatchArray(['step' => 'merge']);
});

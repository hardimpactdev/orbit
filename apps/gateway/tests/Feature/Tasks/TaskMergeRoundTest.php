<?php

declare(strict_types=1);

use App\Actions\Tasks\RunTaskMergeRoundAction;
use App\Actions\Tasks\StartTaskPublishAction;
use App\Data\Tasks\StartTaskPublishData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupDone;
use App\Domain\Tasks\TaskPrMode;
use App\Domain\Tasks\TaskPublishProjection;
use App\Domain\Tasks\TaskStatus;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Support\ValidatedData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskDoneFixture;

uses(LazilyRefreshDatabase::class);

const MergeRoundHead = 'cccccccccccccccccccccccccccccccccccccccc';
const MergeRoundMerged = 'dddddddddddddddddddddddddddddddddddddddd';

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    Queue::fake([StartAdmittedSwampRun::class]);
    app(TaskExtensionState::class)->enable();
});

/**
 * A done task copy whose publish of its done check conflicted with main: the release of a `review_before_pr` group, or the automatic
 * publish of a `pr_when_done` group. With `$done` false, its subtask is open and a person published the named check, with `$text` as
 * their own title and body or as a push only.
 *
 * @param  array{title: string, body: string}|null  $text
 * @return array{TaskDoneFixture, Operation}
 */
function mergeRoundWorld(TaskPrMode $mode = TaskPrMode::ReviewBeforePr, bool $done = true, ?array $text = null): array
{
    $world = TaskDoneFixture::make($mode);
    $world->group->update(['pr_mode' => $mode]);
    if (! $done) {
        $world->subtask->update(['status' => TaskStatus::Running]);
    }

    return mergeRoundWorldFrom($world, fn (): Operation => match (true) {
        ! $done => app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData($world->check->id, $text), 'person', null),
        $mode === TaskPrMode::PrWhenDone => Artisan::call('task:pr-when-done') === 0 ? mergeRoundSole($world->group, OperationKinds::TasksPublish) : throw new LogicException('No tick.'),
        default => app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null),
    });
}

/**
 * The copy's T3 runtime and the implementer thread of the world's subtask, then the publish that `$admit` starts, ended as a conflict.
 *
 * @param  Closure(): Operation  $admit
 * @return array{TaskDoneFixture, Operation}
 */
function mergeRoundWorldFrom(TaskDoneFixture $world, Closure $admit): array
{
    // A turn runs through the copy's T3, which a runtime records only as it is made.
    $runtime = InstanceRuntime::query()->where('instance_id', $world->instance->id)->sole();
    $runtime->delete();
    InstanceRuntime::query()->create([...$runtime->only(['node_id', 'power_state', 'runtime_version', 'agent_version', 'instance_id', 'incus_project', 'container_name', 'bridge_address', 'seed_commit']),
        't3_port' => 3800, 'container_server' => 't3']);
    $implementer = AgentThread::query()->create(['task_group_id' => $world->group->id, 'task_id' => $world->subtask->id, 'node_id' => $world->instance->node_id, 'driver' => 't3',
        'runtime_key' => 'instance:'.$world->instance->id, 'external_id' => 'implementer-thread', 'role' => 'implementer', 'model' => 'claude', 'effort' => 'high']);
    $world->subtask->update(['implementer_agent_thread_id' => $implementer->id]);

    return [$world, mergeRoundConflict($admit())];
}

/** @return list<array<string, mixed>> */
function mergeRoundTick(): array
{
    return app(RunTaskMergeRoundAction::class)->execute();
}

function mergeRoundSole(Task $group, string $kind): Operation
{
    return Operation::query()->where('kind', $kind)->where('target_id', $group->id)->orderByDesc('id')->firstOrFail();
}

/** The round's step, by its key. */
function mergeRoundStep(Task $group, Operation $publish, string $step): Operation
{
    return Operation::query()->where('idempotency_key', "merge-round:{$group->id}:{$publish->id}:{$step}")->sole();
}

/** Ends the publish as a conflict with main, as its run and projection record it. */
function mergeRoundConflict(Operation $publish): Operation
{
    return mergeRoundEnd($publish, OperationStatus::Failed, ['error_code' => RunTaskMergeRoundAction::ConflictCode, 'conflicting_paths' => ['app/Order.php', 'tests/ci-units.json'], 'conflicting_more' => 2]);
}

/** @param  array<string, mixed>  $result */
function mergeRoundEnd(Operation $operation, OperationStatus $status = OperationStatus::Succeeded, array $result = []): Operation
{
    $operation->update(['status' => $status, 'result' => $result, 'error_code' => $status === OperationStatus::Failed ? ($result['error_code'] ?? 'tasks.step_failed') : null,
        'lock_key' => null, 'finished_at' => now()]);

    return $operation->refresh();
}

function mergeRoundSynced(Task $group, Operation $publish): Operation
{
    return mergeRoundEnd(mergeRoundStep($group, $publish, 'sync'), result: ['default_branch' => 'main', 'default_commit' => MergeRoundHead, 'branch' => 'task-'.$group->id,
        'branch_commit' => TaskDoneFixture::Commit, 'behind_by' => 3, 'start' => str_repeat('a', 40)]);
}

function mergeRoundTurnEnded(Operation $turn, string $state = 'done', OperationStatus $status = OperationStatus::Succeeded): Operation
{
    return mergeRoundEnd($turn, $status, ['state' => $state, 'ended' => true]);
}

/** Ends the round's handoff check as passed on the merge commit, as the check's projection records it: C1. */
function mergeRoundChecked(Task $group, Operation $publish, TaskCheckStatus $status = TaskCheckStatus::Passed): TaskCheck
{
    $operation = mergeRoundStep($group, $publish, 'check');
    $check = TaskCheck::query()->findOrFail(ValidatedData::integer($operation->input['check_id']));
    $check->update(['status' => $status, 'pid' => 1, 'process_started' => 'x', 'head_before' => TaskDoneFixture::Commit, 'tree_before' => str_repeat('4', 40),
        'head_after' => MergeRoundMerged, 'tree_after' => str_repeat('5', 40), 'commit_sha' => MergeRoundMerged, 'exit_code' => $status === TaskCheckStatus::Passed ? 0 : 1,
        'finished_at' => now(), 'pr_mode' => $group->pr_mode]);
    mergeRoundEnd($operation, result: ['outcome' => $status === TaskCheckStatus::Passed ? 'passed' : 'failed', 'check_id' => $check->id, 'commit' => MergeRoundMerged,
        'head' => MergeRoundMerged, 'tree' => str_repeat('5', 40), 'ci' => null]);

    return $check->refresh();
}

/** Ends the round's reviewer turn done; with `$text`, it sent the pull request text on C1 during the turn. */
function mergeRoundReviewed(Task $group, Operation $publish, TaskCheck $c1, bool $text = true): Operation
{
    $review = mergeRoundStep($group, $publish, 'review');
    if ($text) {
        $c1->update(['pr_title' => 'Export the board as CSV, merged with main', 'pr_body' => 'Adds a CSV export of the board.', 'pr_text_operation_id' => $review->id]);
    }

    return mergeRoundTurnEnded($review);
}

/**
 * Drives the round through its steps, each admitted by a tick and ended well, until `$until` is admitted and still open.
 *
 * @return array{Operation, TaskCheck|null}
 */
function mergeRoundDrive(Task $group, Operation $publish, string $until): array
{
    $c1 = null;
    foreach (['sync', 'turn', 'check', 'review', 'publish'] as $step) {
        mergeRoundTick();
        $operation = mergeRoundStep($group, $publish, $step);
        if ($step === $until) {
            return [$operation, $c1];
        }
        match ($step) {
            'sync' => mergeRoundSynced($group, $publish),
            'turn' => mergeRoundTurnEnded($operation),
            'check' => $c1 = mergeRoundChecked($group, $publish),
            'review' => mergeRoundReviewed($group, $publish, $c1 ?? throw new LogicException('No C1.')),
            'publish' => null,
        };
    }
    throw new LogicException("No step {$until}.");
}

function mergeRoundOperations(Task $group): int
{
    return Operation::query()->where('target_id', $group->id)->where('target_type', OperationKinds::TaskGroupTarget)->count();
}

describe('a done review_before_pr group whose release conflicted', function (): void {
    it('a. starts the sync on the first tick and nothing on a repeat tick', function (): void {
        [$world, $publish] = mergeRoundWorld();

        $first = mergeRoundTick();
        $sync = mergeRoundStep($world->group, $publish, 'sync');
        $count = mergeRoundOperations($world->group);
        $second = mergeRoundTick();

        expect($first)->toBe([['group' => $world->group->id, 'publish' => $publish->id, 'step' => 'sync', 'result' => 'admitted', 'code' => null, 'operation' => $sync->id]])
            ->and($sync->kind)->toBe(OperationKinds::TasksSync)
            ->and($sync->triggered_by)->toBe(RunTaskMergeRoundAction::Caller)
            ->and($second)->toBe([['group' => $world->group->id, 'publish' => $publish->id, 'step' => null, 'result' => 'busy', 'code' => null, 'operation' => null]])
            ->and(mergeRoundOperations($world->group))->toBe($count);
    });

    it('b. reopens the completed last subtask after the sync and starts the implementer turn with the paths and the default head', function (): void {
        [$world, $publish] = mergeRoundWorld();
        mergeRoundTick();
        mergeRoundSynced($world->group, $publish);

        $results = mergeRoundTick();

        $turn = mergeRoundStep($world->group, $publish, 'turn');
        expect($results[0])->toMatchArray(['step' => 'turn', 'result' => 'admitted', 'operation' => $turn->id])
            ->and($world->subtask->refresh()->status)->toBe(TaskStatus::Running)
            ->and(Activity::query()->where('description', 'subtask reopened')->where('subject_id', $world->subtask->id)->count())->toBe(1)
            ->and($turn->kind)->toBe(OperationKinds::TasksCopyTurn)
            ->and($turn->input['role'])->toBe('implementer')
            ->and($turn->input['subtask'])->toBe($world->subtask->id)
            ->and($turn->input['thread_id'])->toBe($world->subtask->refresh()->implementer_agent_thread_id)
            ->and($turn->input['prompt'])->toContain('app/Order.php, tests/ci-units.json and 2 more')
            ->and($turn->input['prompt'])->toContain('default head '.MergeRoundHead)
            ->and($turn->input['prompt'])->toContain('Merge origin/main into the task branch')
            ->and($turn->input['prompt'])->toContain('bin/ci-units collect --file')
            ->and($turn->input['prompt'])->toContain('Run only the tests that cover the conflicting paths')
            ->and($turn->input['prompt'])->toContain('If a conflict needs a decision, ask.');
    });

    it('c. starts a handoff check of the subtask once the turn ended done', function (): void {
        [$world, $publish] = mergeRoundWorld();
        [$turn] = mergeRoundDrive($world->group, $publish, 'turn');
        mergeRoundTurnEnded($turn);

        $results = mergeRoundTick();

        $check = mergeRoundStep($world->group, $publish, 'check');
        expect($results[0])->toMatchArray(['step' => 'check', 'result' => 'admitted', 'operation' => $check->id])
            ->and($check->kind)->toBe(OperationKinds::TasksCheck)
            ->and($check->input['subtask'])->toBe($world->subtask->id)
            ->and($check->input['phase'])->toBe('handoff');
    });

    it('d. reviews only the merge on C1, then completes the subtask and publishes C1 with the reviewer\'s text as the done check', function (): void {
        [$world, $publish] = mergeRoundWorld();
        mergeRoundDrive($world->group, $publish, 'check');
        $c1 = mergeRoundChecked($world->group, $publish);

        mergeRoundTick();

        $review = mergeRoundStep($world->group, $publish, 'review');
        expect($review->input['role'])->toBe('reviewer')
            ->and($review->input['check'])->toBe($c1->id)
            ->and($review->input['candidate'])->toBe(MergeRoundMerged)
            ->and($review->input['thread_id'])->toBe($world->reviewer->id)
            ->and($review->input['prompt'])->toContain('git log --merges '.TaskDoneFixture::Commit.'..'.MergeRoundMerged)
            ->and($review->input['prompt'])->toContain('git show --remerge-diff <merge>')
            ->and($review->input['prompt'])->toContain('Review ONLY the merge\'s own change')
            ->and($review->input['prompt'])->toContain('send the pull request text again');

        mergeRoundReviewed($world->group, $publish, $c1);
        $results = mergeRoundTick();

        $republish = mergeRoundStep($world->group, $publish, 'publish');
        expect($results[0])->toMatchArray(['step' => 'publish', 'result' => 'admitted', 'operation' => $republish->id])
            ->and($world->subtask->refresh()->status)->toBe(TaskStatus::Completed)
            ->and($republish->kind)->toBe(OperationKinds::TasksPublish)
            ->and($republish->input['check'])->toBe($c1->id)
            ->and($republish->input['commit'])->toBe(MergeRoundMerged)
            ->and(data_get($republish->input, 'pull_request.title'))->toBe('Export the board as CSV, merged with main')
            ->and(data_get($republish->input, 'pull_request.draft'))->toBeFalse();

        mergeRoundEnd($republish);
        expect(app(TaskGroupDone::class)->check($world->group->refresh())?->id)->toBe($c1->id)
            ->and(mergeRoundTick())->toBe([]);
    });
});

it('e. publishes C1 of a pr_when_done group once, whichever tick runs first', function (): void {
    [$world, $publish] = mergeRoundWorld(TaskPrMode::PrWhenDone);
    expect($publish->triggered_by)->toBe('gateway:pr-when-done');
    [, $c1] = mergeRoundDrive($world->group, $publish, 'review');
    mergeRoundReviewed($world->group, $publish, $c1 ?? throw new LogicException('No C1.'));

    // The subtask is still reopened, so the automatic publish finds no done check.
    Artisan::call('task:pr-when-done');
    mergeRoundTick();
    Artisan::call('task:pr-when-done');
    mergeRoundTick();

    $publishes = Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_id', $world->group->id)->where('input->check', $c1->id)->get();
    expect($publishes)->toHaveCount(1)
        ->and($publishes->sole()->idempotency_key)->toBe("merge-round:{$world->group->id}:{$publish->id}:publish")
        ->and($world->subtask->refresh()->status)->toBe(TaskStatus::Completed);
});

it('e. yields C1 to the automatic publish that started it first, and stops when that publish conflicts again', function (): void {
    [$world, $publish] = mergeRoundWorld(TaskPrMode::PrWhenDone);
    [, $c1] = mergeRoundDrive($world->group, $publish, 'review');
    mergeRoundReviewed($world->group, $publish, $c1 ?? throw new LogicException('No C1.'));
    // The subtask completed, and the automatic publish admitted C1 before the round's publish.
    $world->subtask->refresh()->update(['status' => TaskStatus::Completed]);
    Artisan::call('task:pr-when-done');
    $automatic = mergeRoundSole($world->group, OperationKinds::TasksPublish);

    expect($automatic->idempotency_key)->toBe("pr-when-done:{$world->group->id}:{$c1->id}")
        ->and(mergeRoundTick())->toBe([]);

    mergeRoundConflict($automatic);
    $results = mergeRoundTick();

    expect($results[0])->toMatchArray(['publish' => $automatic->id, 'step' => 'publish', 'result' => 'stopped'])
        ->and(Operation::query()->where('kind', OperationKinds::TasksPublish)->where('input->check', $c1->id)->count())->toBe(1)
        ->and(Operation::query()->where('idempotency_key', 'like', "merge-round:{$world->group->id}:{$automatic->id}:%")->exists())->toBeFalse();
});

it('f. merges an open subtask whose push-only publish conflicted, and pushes C1 only, without a reopen or a completion', function (): void {
    [$world, $publish] = mergeRoundWorld(done: false);
    expect($publish->input['pull_request'] ?? null)->toBeNull();
    [, $c1] = mergeRoundDrive($world->group, $publish, 'review');
    mergeRoundReviewed($world->group, $publish, $c1 ?? throw new LogicException('No C1.'));

    mergeRoundTick();

    $push = mergeRoundStep($world->group, $publish, 'publish');
    expect($push->input['check'])->toBe($c1->id)
        ->and($push->input['pull_request'] ?? null)->toBeNull()
        ->and($world->subtask->refresh()->status)->toBe(TaskStatus::Running)
        ->and(Activity::query()->where('description', 'subtask reopened')->exists())->toBeFalse();
});

it('f. stops and asks, without a reopen, when the subtask of a push-only publish is completed', function (bool $synced): void {
    [$world, $publish] = mergeRoundWorld(done: false);
    if ($synced) {
        mergeRoundTick();
        mergeRoundSynced($world->group, $publish);
    }
    $world->subtask->refresh()->update(['status' => TaskStatus::Completed]);
    $count = mergeRoundOperations($world->group);

    $results = mergeRoundTick();

    $group = $world->group->refresh();
    expect($results)->toBe([['group' => $group->id, 'publish' => $publish->id, 'step' => $synced ? 'turn' : 'sync', 'result' => 'stopped', 'code' => null, 'operation' => null]])
        ->and($group->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->assistance_reason)->toContain("subtask {$world->subtask->id} is completed, and publish Operation {$publish->id} was no done-check publish")
        ->and($group->assistance_reason)->toContain('app/Order.php, tests/ci-units.json and 2 more')
        ->and($world->subtask->refresh()->status)->toBe(TaskStatus::Completed)
        ->and(Activity::query()->where('description', 'subtask reopened')->exists())->toBeFalse()
        ->and(mergeRoundOperations($group))->toBe($count)
        ->and(mergeRoundTick())->toBe([]);
})->with(['before the sync' => [false], 'after the sync' => [true]]);

it('f. treats a person\'s publish of an open subtask\'s check with their own text as no done-check publish', function (): void {
    [$world, $publish] = mergeRoundWorld(done: false, text: ['title' => 'My title', 'body' => 'My body.']);
    expect(data_get($publish->input, 'pull_request.title'))->toBe('My title');
    [, $c1] = mergeRoundDrive($world->group, $publish, 'review');
    mergeRoundReviewed($world->group, $publish, $c1 ?? throw new LogicException('No C1.'));

    mergeRoundTick();

    $push = mergeRoundStep($world->group, $publish, 'publish');
    expect($world->subtask->refresh()->status)->toBe(TaskStatus::Running)
        ->and($push->input['check'])->toBe($c1->id)
        ->and($push->input['pull_request'] ?? null)->toBeNull()
        ->and($publish->input)->not->toHaveKey(TaskPublishProjection::DoneCheck)
        ->and($push->input)->not->toHaveKey(TaskPublishProjection::DoneCheck);
});

it('d. runs a done-check republish without the reviewer\'s text on a group with its pull request as a done-check round', function (): void {
    $world = TaskDoneFixture::make(TaskPrMode::ReviewBeforePr);
    $world->check->update(['pr_title' => null, 'pr_body' => null, 'pr_text_operation_id' => null]);
    $world->group->update(['pr_url' => 'https://github.com/example/trial/pull/7']);
    [, $publish] = mergeRoundWorldFrom($world, fn (): Operation => app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null));
    expect($publish->input['pull_request'] ?? null)->toBeNull()
        ->and(mergeRoundTick()[0])->toMatchArray(['step' => 'sync', 'result' => 'admitted'])
        ->and($publish->input[TaskPublishProjection::DoneCheck])->toBe($world->check->id)
        ->and(app(TaskPublishProjection::class)->input($publish)['intent'])->not->toHaveKey(TaskPublishProjection::DoneCheck);
    mergeRoundSynced($world->group, $publish);
    mergeRoundTick();
    expect($world->subtask->refresh()->status)->toBe(TaskStatus::Running);
    mergeRoundTurnEnded(mergeRoundStep($world->group, $publish, 'turn'));
    mergeRoundTick();
    $c1 = mergeRoundChecked($world->group, $publish);
    mergeRoundTick();
    mergeRoundReviewed($world->group, $publish, $c1);
    mergeRoundTick();

    $republish = mergeRoundStep($world->group, $publish, 'publish');
    expect($world->subtask->refresh()->status)->toBe(TaskStatus::Completed)
        ->and($republish->input[TaskPublishProjection::DoneCheck])->toBe($c1->id)
        ->and(data_get($republish->input, 'pull_request.title'))->toBe('Export the board as CSV, merged with main');
});

it('g. asks once for P: a person who resolves the request without acting ends the round', function (): void {
    [$world, $publish] = mergeRoundWorld();
    [$sync] = mergeRoundDrive($world->group, $publish, 'sync');
    mergeRoundEnd($sync, OperationStatus::Failed, ['error_code' => 'tasks.sync_failed']);
    expect(mergeRoundTick()[0]['result'])->toBe('stopped');
    TaskAssistance::apply($world->group->refresh());
    $count = mergeRoundOperations($world->group);

    $results = mergeRoundTick();

    $group = $world->group->refresh();
    expect($results)->toBe([['group' => $group->id, 'publish' => $publish->id, 'step' => null, 'result' => 'stopped', 'code' => null, 'operation' => null]])
        ->and($group->assistance_requested)->toBeFalse()
        ->and(Activity::query()->where('description', 'assistance requested')->where('subject_id', $group->id)->count())->toBe(1)
        ->and(mergeRoundOperations($group))->toBe($count);
});

it('g. stops and asks when its publish of C1 is refused because a todo subtask was added after the approval', function (): void {
    [$world, $publish] = mergeRoundWorld();
    [, $c1] = mergeRoundDrive($world->group, $publish, 'review');
    mergeRoundReviewed($world->group, $publish, $c1 ?? throw new LogicException('No C1.'));
    Task::query()->create(['parent_id' => $world->group->id, 'position' => 2, 'title' => 'Add the header row', 'brief' => 'Add it.', 'status' => TaskStatus::Todo]);

    $results = mergeRoundTick();

    $group = $world->group->refresh();
    expect($results[0])->toMatchArray(['step' => 'publish', 'result' => 'stopped'])
        ->and(Operation::query()->where('kind', OperationKinds::TasksPublish)->where('input->check', $c1->id)->exists())->toBeFalse()
        ->and($group->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->assistance_reason)->toContain('the publish step was refused with tasks.not_done');
});

describe('g. a stop', function (): void {
    it('asks a person once, with the step, the Operation and the paths, and starts no next step', function (string $at, Closure $break, string $what): void {
        [$world, $publish] = mergeRoundWorld();
        [$operation, $c1] = mergeRoundDrive($world->group, $publish, $at);
        $ended = $break($world->group, $publish, $operation, $c1);

        $results = mergeRoundTick();
        $count = mergeRoundOperations($world->group);
        $again = mergeRoundTick();

        $group = $world->group->refresh();
        expect($results)->toHaveCount(1)
            ->and($results[0])->toMatchArray(['step' => $at, 'result' => 'stopped', 'operation' => $ended->id])
            ->and($group->assistance_requested)->toBeTrue()
            ->and($group->assistance_kind)->toBe(AssistanceKind::Failure)
            ->and($group->assistance_reason)->toContain("the {$at} step, Operation {$ended->id}, {$what}")
            ->and($group->assistance_reason)->toContain('app/Order.php, tests/ci-units.json and 2 more')
            ->and($again)->toBe([])
            ->and(mergeRoundOperations($world->group))->toBe($count)
            ->and(Activity::query()->where('description', 'assistance requested')->where('subject_id', $group->id)->count())->toBe(1);
    })->with([
        'the sync failed' => ['sync', fn (Task $group, Operation $publish, Operation $sync): Operation => mergeRoundEnd($sync, OperationStatus::Failed, ['error_code' => 'tasks.sync_failed']), 'failed'],
        'the turn asked' => ['turn', fn (Task $group, Operation $publish, Operation $turn): Operation => mergeRoundTurnEnded($turn, 'asking_for_input'), 'asked'],
        'the turn failed' => ['turn', fn (Task $group, Operation $publish, Operation $turn): Operation => mergeRoundTurnEnded($turn, 'failed', OperationStatus::Failed), 'failed'],
        'the turn failed and may still run' => ['turn', function (Task $group, Operation $publish, Operation $turn): Operation {
            $turn->update(['state' => ['swamp_run_id' => 'run-1']]);

            return mergeRoundEnd($turn, OperationStatus::Failed, ['state' => 'failed', 'error_code' => 'operation.timed_out']);
        }, 'failed'],
        'the check failed' => ['check', function (Task $group, Operation $publish, Operation $check): Operation {
            mergeRoundChecked($group, $publish, TaskCheckStatus::Failed);

            return $check->refresh();
        }, 'failed'],
        'the reviewer sent no text' => ['review', fn (Task $group, Operation $publish, Operation $review, TaskCheck $c1): Operation => mergeRoundReviewed($group, $publish, $c1, text: false), 'sent no pull request text'],
        'the round\'s publish conflicted again' => ['publish', fn (Task $group, Operation $publish, Operation $republish): Operation => mergeRoundConflict($republish), 'conflicted again'],
    ]);
});

it('h. yields once a driver acted after the sync', function (): void {
    [$world, $publish] = mergeRoundWorld();
    mergeRoundTick();
    mergeRoundSynced($world->group, $publish);
    $driver = Operation::query()->create(['kind' => OperationKinds::TasksCopyTurn, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $world->group->id,
        'input' => ['subtask' => $world->subtask->id, 'role' => 'implementer'], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
        'result' => ['state' => 'done', 'ended' => true], 'triggered_by' => 'person', 'finished_at' => now()]);
    $count = mergeRoundOperations($world->group);

    expect(mergeRoundTick())->toBe([['group' => $world->group->id, 'publish' => $publish->id, 'step' => null, 'result' => 'yielded', 'code' => null, 'operation' => $driver->id]])
        ->and(mergeRoundOperations($world->group))->toBe($count)
        ->and($world->group->refresh()->assistance_requested)->toBeFalse();
});

describe('i. a group the round skips', function (): void {
    it('skips a group with an assistance request', function (): void {
        [$world] = mergeRoundWorld();
        TaskAssistance::apply($world->group, AssistanceKind::Direction, 'Which side wins?');
        $count = mergeRoundOperations($world->group);

        expect(mergeRoundTick())->toBe([])->and(mergeRoundOperations($world->group))->toBe($count);
    });

    it('skips a busy group: an open Operation', function (): void {
        [$world, $publish] = mergeRoundWorld();
        Operation::query()->create(['kind' => OperationKinds::TasksCopyTurn, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $world->group->id,
            'input' => ['subtask' => $world->subtask->id, 'role' => 'implementer', 'turn' => ['title' => 'Orbit task']], 'status' => OperationStatus::Running,
            'sequence' => 1, 'attempts' => 1, 'state' => ['swamp_run_id' => 'run-1'], 'triggered_by' => 'person']);
        $count = mergeRoundOperations($world->group);

        expect(mergeRoundTick())->toBe([['group' => $world->group->id, 'publish' => $publish->id, 'step' => null, 'result' => 'busy', 'code' => null, 'operation' => null]])
            ->and(mergeRoundOperations($world->group))->toBe($count);
    });

    it('starts no step while a turn may still run, and starts it once the turn settled', function (): void {
        [$world, $publish] = mergeRoundWorld();
        $world->turn->update(['result' => ['state' => 'done', 'ended' => false]]);
        $count = mergeRoundOperations($world->group);

        expect(mergeRoundTick())->toBe([['group' => $world->group->id, 'publish' => $publish->id, 'step' => 'sync', 'result' => 'busy', 'code' => null, 'operation' => null]])
            ->and(mergeRoundOperations($world->group))->toBe($count);

        $world->turn->update(['result' => ['state' => 'done', 'ended' => true]]);
        expect(mergeRoundTick()[0])->toMatchArray(['step' => 'sync', 'result' => 'admitted']);
    });
});

it('j. runs task:merge-round every minute beside task:pr-when-done, only while the Tasks extension is on', function (): void {
    Artisan::call('schedule:list');
    expect(Artisan::output())->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan task:merge-round/');

    $event = collect(app(Schedule::class)->events())->sole(fn ($event): bool => str_contains((string) $event->command, 'task:merge-round'));
    expect($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(5)
        ->and($event->filtersPass(app()))->toBeTrue();

    [$world] = mergeRoundWorld();
    app(TaskExtensionState::class)->disable();
    expect($event->filtersPass(app()))->toBeFalse()
        ->and(Artisan::call('task:merge-round'))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('')
        ->and(Operation::query()->where('kind', OperationKinds::TasksSync)->exists())->toBeFalse();

    app(TaskExtensionState::class)->enable();
    Artisan::call('task:merge-round');
    expect(data_get(json_decode(Artisan::output(), true), 'groups.0.step'))->toBe('sync');
});

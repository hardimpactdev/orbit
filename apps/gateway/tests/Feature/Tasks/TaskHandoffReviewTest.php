<?php

declare(strict_types=1);

use App\Actions\Tasks\StartTaskPublishAction;
use App\Actions\Tasks\StartTaskTurnAction;
use App\Actions\Tasks\UpdateTaskAction;
use App\Data\Tasks\StartTaskPublishData;
use App\Data\Tasks\TaskTurnData;
use App\Data\Tasks\UpdateTaskData;
use App\Domain\Instances\ContainerServer;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskHandoffs;
use App\Domain\Tasks\TaskHandoffType;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnProjection;
use App\Domain\Tasks\TaskTurnPrompt;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Models\TaskCheck;
use App\Models\TaskHandoff;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskDoneFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    Queue::fake([StartAdmittedSwampRun::class]);
    app(TaskExtensionState::class)->enable();
});

/** A gated group whose subtask runs in a copy whose container runs T3, with an accepted delivery of its passed check. */
function reviewWorld(bool $gated = true): TaskDoneFixture
{
    $world = TaskDoneFixture::make(text: false);
    $world->group->update(['handoffs_gated' => $gated]);
    $world->subtask->update(['status' => TaskStatus::Running]);
    $runtime = InstanceRuntime::query()->where('instance_id', $world->instance->id)->sole();
    $runtime->delete();
    InstanceRuntime::query()->create([...$runtime->only(['node_id', 'power_state', 'runtime_version', 'agent_version', 'instance_id', 'incus_project', 'container_name',
        'bridge_address', 'seed_commit']), 't3_port' => 3800, 'container_server' => ContainerServer::T3]);
    reviewDelivery($world, false);

    return $world;
}

/** A delivery row of the world's check, as the delivery gate writes it. */
function reviewDelivery(TaskDoneFixture $world, bool $rejected): TaskHandoff
{
    return TaskHandoff::query()->create(['task_id' => $world->subtask->id, 'type' => TaskHandoffType::Delivery, 'task_check_id' => $world->check->id,
        'rejected' => $rejected, 'rejection_reason' => $rejected ? TaskHandoffs::FailedPart : '']);
}

/** Admits a turn of ROLE on the world's subtask, or returns the code and status of its refusal. */
function reviewStart(TaskDoneFixture $world, string $role): Operation|string
{
    try {
        return app(StartTaskTurnAction::class)->execute($world->group->refresh(), TaskTurnData::from(['subtask' => $world->subtask->id, 'role' => $role,
            ...($role === 'reviewer' ? ['check' => $world->check->id] : []), 'prompt' => 'Go.']), 'test', null);
    } catch (ResourceOperationException $error) {
        return $error->errorCode.' '.$error->status;
    }
}

/**
 * The turn's run ended in STATE with the review receipt REVIEW as the copy turn returns it, and asked QUESTIONS.
 *
 * @param  list<array<string, mixed>>  $questions
 */
function reviewEnded(Operation $turn, string $state, ?string $review = null, array $questions = [], string $status = 'succeeded'): Operation
{
    $turn->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-turn']])->save();

    return app(TaskTurnProjection::class)->apply($turn->refresh(), ['status' => $status, 'run_id' => 'run-turn', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['turn' => ['state' => $state, 'error' => null, 'ended' => true, 'run_id' => 'run:1', 'launched' => true,
            'questions' => $questions, 'transcript' => [], 'receipts' => ['delivery' => null, 'review' => $review]]]]);
}

/** A reviewer turn ends done and leaves the review receipt RECEIPT; returns its row. */
function reviewed(TaskDoneFixture $world, ?string $receipt): TaskHandoff
{
    $turn = reviewStart($world, 'reviewer');
    expect($turn)->toBeInstanceOf(Operation::class);
    reviewEnded($turn, 'done', $receipt);

    return TaskHandoff::query()->where('operation_id', $turn->id)->sole();
}

/** @param list<string> $findings */
function reviewReceipt(string $verdict, array $findings = []): string
{
    return json_encode(['verdict' => $verdict, 'findings' => array_map(static fn (string $summary): array => ['summary' => $summary], $findings)], JSON_THROW_ON_ERROR);
}

it('accepts a review receipt whose verdict matches its findings, and keeps the verdict and the findings readable', function (string $receipt, string $verdict, int $findings): void {
    $world = reviewWorld();

    $row = reviewed($world, $receipt);

    expect($row->type)->toBe(TaskHandoffType::Review)
        ->and($row->rejected)->toBeFalse()
        ->and($row->rejection_reason)->toBe('')
        ->and($row->task_id)->toBe($world->subtask->id)
        ->and($row->task_check_id)->toBeNull()
        ->and($row->receipt['verdict'])->toBe($verdict)
        ->and(count($row->receipt['findings']))->toBe($findings)
        // The turn's stored result keeps the receipt reduced to its schema fields, never the raw text.
        ->and(Operation::query()->findOrFail($row->operation_id)->result['receipts']['review'])->toBe(json_encode($row->receipt, JSON_THROW_ON_ERROR));
})->with([
    'approve with no finding' => [reviewReceipt('approve'), 'approve', 0],
    'changes with two findings' => [reviewReceipt('changes', ['The export drops the header row.', 'No test covers an empty board.']), 'changes', 2],
    'fields in another order' => ['{"findings":[],"verdict":"approve"}', 'approve', 0],
    'an ordinary finding beside a summary that holds a secret' => [reviewReceipt('changes', ['The export drops the header row.', 'The proxy drops the Authorization: Bearer ghp_'.str_repeat('a1B2', 9).' header.']), 'changes', 2],
]);

it('redacts each summary that a valid receipt keeps', function (): void {
    $secret = 'ghp_'.str_repeat('a1B2', 9);

    $row = reviewed(reviewWorld(), reviewReceipt('changes', ["The proxy drops the Authorization: Bearer {$secret} header."]));

    expect($row->rejected)->toBeFalse()
        ->and(json_encode($row->receipt))->not->toContain($secret)
        ->and(json_encode(Operation::query()->findOrFail($row->operation_id)->result))->not->toContain($secret);
});

it('rejects a review receipt with one reason', function (?string $receipt, string $reason): void {
    $world = reviewWorld();

    $row = reviewed($world, $receipt);

    expect($row->only(['type', 'rejected', 'rejection_reason']))->toBe(['type' => TaskHandoffType::Review, 'rejected' => true, 'rejection_reason' => $reason])
        // A rejected receipt never fails the turn: its Operation ends.
        ->and(Operation::query()->findOrFail($row->operation_id)->status)->toBe(OperationStatus::Succeeded)
        // A rejected row keeps no receipt content, nor does the turn's stored result: it keeps the reason alone.
        ->and($row->receipt)->toBeNull()
        ->and(Operation::query()->findOrFail($row->operation_id)->result['receipts']['review'] ?? null)
        ->toBe($receipt === null ? null : ($reason === TaskHandoffs::InvalidReceipt ? 'invalid_receipt' : $reason));
})->with([
    'no receipt' => [null, TaskHandoffs::NoReceipt],
    'text that is not JSON' => ['VERDICT: APPROVE', TaskHandoffs::InvalidReceipt],
    'a JSON list' => ['[]', TaskHandoffs::InvalidReceipt],
    'an unknown verdict' => ['{"verdict":"maybe","findings":[]}', TaskHandoffs::InvalidReceipt],
    'no findings field' => ['{"verdict":"approve"}', TaskHandoffs::InvalidReceipt],
    'findings that are no list' => ['{"verdict":"changes","findings":{"a":{"summary":"x"}}}', TaskHandoffs::InvalidReceipt],
    'a finding without a summary' => ['{"verdict":"changes","findings":[{"file":"a.php"}]}', TaskHandoffs::InvalidReceipt],
    'a finding with an empty summary' => ['{"verdict":"changes","findings":[{"summary":" "}]}', TaskHandoffs::InvalidReceipt],
    'a field outside the schema' => ['{"verdict":"approve","findings":[],"note":"x"}', TaskHandoffs::InvalidReceipt],
    'an empty object for findings' => ['{"verdict":"approve","findings":{}}', TaskHandoffs::InvalidReceipt],
    'an object with list keys for findings' => ['{"verdict":"changes","findings":{"0":{"summary":"Missing column"}}}', TaskHandoffs::InvalidReceipt],
    'text that names a reason' => ['approve_with_finding', TaskHandoffs::InvalidReceipt],
    'a file Swamp found too large' => ['too large', TaskHandoffs::InvalidReceipt],
    'an empty file' => ['', TaskHandoffs::InvalidReceipt],
    'a newline-only file' => ["\n", TaskHandoffs::InvalidReceipt],
    'a file Swamp found not UTF-8' => ['not utf-8', TaskHandoffs::InvalidReceipt],
    'a summary of terminal formatting only' => [reviewReceipt('changes', ["\u{1b}[31m\u{1b}[0m"]), TaskHandoffs::InvalidReceipt],
    'terminal formatting around JSON' => ["\u{1b}[31m".reviewReceipt('approve'), TaskHandoffs::InvalidReceipt],
    'a secret under a field named key' => ['{"verdict":"approve","findings":[],"key":"hunter2"}', TaskHandoffs::InvalidReceipt],
    'a secret under a nested field named keys' => ['{"verdict":"approve","findings":[{"summary":"x","keys":["hunter2"]}]}', TaskHandoffs::InvalidReceipt],
    'nesting deeper than the decoder allows' => ['{"verdict":"approve","findings":[],"x":'.str_repeat('[', 3000).str_repeat(']', 3000).'}', TaskHandoffs::InvalidReceipt],
    'a blank summary beside an ordinary one' => [reviewReceipt('changes', ['The export drops the header row.', " \t"]), TaskHandoffs::InvalidReceipt],
    'a NUL-only summary beside an ordinary one' => [reviewReceipt('changes', ['The export drops the header row.', "\0"]), TaskHandoffs::InvalidReceipt],
    'a summary that ends in half a surrogate pair' => ['{"verdict":"changes","findings":[{"summary":"The export drops the header row."},{"summary":"Missing \\ud83d"}]}', TaskHandoffs::InvalidReceipt],
    'a verdict in terminal formatting' => [json_encode(['verdict' => "\u{1b}[31mapprove\u{1b}[0m", 'findings' => []], JSON_THROW_ON_ERROR), TaskHandoffs::InvalidReceipt],
    'a secret under a field of another name' => ["{\"verdict\":\"approve\",\"findings\":[],\"password\":\n\"hunter2\"}", TaskHandoffs::InvalidReceipt],
    'a number that does not encode again' => ['{"verdict":"approve","findings":[],"size":1e400}', TaskHandoffs::InvalidReceipt],
    'changes with no finding' => [reviewReceipt('changes'), TaskHandoffs::ChangesWithoutFinding],
    'approve with a finding' => [reviewReceipt('approve', ['The export drops the header row.']), TaskHandoffs::ApproveWithFinding],
]);

it('writes an accepted question row that holds the question, for any role, and no row for a failed turn or an ungated group', function (): void {
    $question = ['request_id' => 'req-1', 'run_id' => 'run:1', 'header' => 'Scope', 'question' => 'Does the export include archived cards?',
        'options' => [['label' => 'Yes', 'description' => '']]];
    $world = reviewWorld();

    $asked = reviewStart($world, 'reviewer');
    reviewEnded($asked, 'asking_for_input', reviewReceipt('approve'), [$question]);

    $row = TaskHandoff::query()->where('operation_id', $asked->id)->sole();
    expect($row->only(['type', 'rejected', 'rejection_reason']))->toBe(['type' => TaskHandoffType::Question, 'rejected' => false, 'rejection_reason' => ''])
        ->and($row->receipt)->toBe(['questions' => [$question]]);

    // The answer turn continues the reviewer's thread; an implementer that asks leaves a question row too.
    reviewEnded(reviewStart($world, 'reviewer'), 'done', reviewReceipt('changes', ['The export drops the header row.']));
    $implementer = reviewStart($world, 'implementer');
    reviewEnded($implementer, 'asking_for_input', null, [$question]);
    expect(TaskHandoff::query()->where('operation_id', $implementer->id)->sole()->type)->toBe(TaskHandoffType::Question);

    $failed = reviewStart($world, 'implementer');
    reviewEnded($failed, 'failed', null, [], 'failed');
    $ungated = reviewWorld(gated: false);
    reviewEnded(reviewStart($ungated, 'reviewer'), 'done', reviewReceipt('approve'));
    expect(TaskHandoff::query()->where('operation_id', $failed->id)->exists())->toBeFalse()
        ->and(TaskHandoff::query()->where('task_id', $ungated->subtask->id)->where('type', '!=', 'delivery')->count())->toBe(0);
});

it('refuses a reviewer turn unless the latest delivery is accepted', function (): void {
    $world = reviewWorld();
    reviewDelivery($world, true);

    expect(reviewStart($world, 'reviewer'))->toBe('tasks.handoff_required 409');
});

it('refuses an implementer turn after an accepted delivery until an accepted review of it exists', function (): void {
    $world = reviewWorld();

    expect(reviewStart($world, 'implementer'))->toBe('tasks.handoff_required 409');

    // A rejected review keeps the gate shut: the reviewer hands off again.
    reviewed($world, reviewReceipt('changes'));
    expect(reviewStart($world, 'implementer'))->toBe('tasks.handoff_required 409');

    // An accepted review with changes lets the implementer fix them.
    reviewed($world, reviewReceipt('changes', ['The export drops the header row.']));
    $fix = reviewStart($world, 'implementer');
    expect($fix)->toBeInstanceOf(Operation::class);
    reviewEnded($fix, 'done');

    // The fix's accepted delivery shuts the gate again; the older review does not count for it.
    reviewDelivery($world, false);
    expect(reviewStart($world, 'implementer'))->toBe('tasks.handoff_required 409');

    // A newer handoff check that failed, was cancelled or was lost is no review.
    $recheck = fn (string $status, string $code): bool => Operation::query()->where('kind', 'tasks.check')
        ->where('input->check_id', TaskDoneFixture::check($world->group, $world->subtask, TaskDoneFixture::Commit, status: TaskCheckStatus::Failed)->id)
        ->sole()->forceFill(['status' => $status, 'error_code' => $code])->save();
    foreach ([['failed', 'tasks.check_failed'], ['cancelled', 'operation.cancelled'], ['interrupted', 'operation.interrupted']] as [$status, $code]) {
        $recheck($status, $code);
        expect(reviewStart($world, 'implementer'))->toBe('tasks.handoff_required 409');
    }

    // A newer handoff check that ended with tasks.check_conflict lets the merge turn run.
    $recheck('failed', 'tasks.check_conflict');
    expect(reviewStart($world, 'implementer'))->toBeInstanceOf(Operation::class);

    // An ungated group keeps the old rule.
    $ungated = reviewWorld(gated: false);
    expect(reviewStart($ungated, 'implementer'))->toBeInstanceOf(Operation::class);
});

it('refuses to complete a subtask or publish unless its latest review is an accepted approve of its latest delivery', function (): void {
    $world = reviewWorld();
    $complete = function () use ($world): string {
        try {
            app(UpdateTaskAction::class)->execute($world->group->refresh(), $world->subtask->refresh(),
                new UpdateTaskData(title: null, brief: null, position: null, status: TaskStatus::Completed));
        } catch (ResourceOperationException $error) {
            return $error->errorCode.' '.$error->status;
        }

        return 'completed';
    };
    $publish = function (?TaskCheck $check = null) use ($world): string {
        try {
            app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData(($check ?? $world->check)->id), 'test', null);
        } catch (ResourceOperationException $error) {
            return $error->errorCode.' '.$error->status;
        }

        return 'admitted';
    };

    // No review yet.
    expect($complete())->toBe('tasks.handoff_required 409')
        ->and($publish())->toBe('tasks.handoff_required 409');

    // An accepted review that asks for changes, then an approve whose receipt the gate rejected.
    reviewed($world, reviewReceipt('changes', ['The export drops the header row.']));
    expect($complete())->toBe('tasks.handoff_required 409');
    reviewed($world, reviewReceipt('approve', ['The export drops the header row.']));
    expect($complete())->toBe('tasks.handoff_required 409')
        ->and($publish())->toBe('tasks.handoff_required 409');

    // An accepted approve, then a newer delivery that it did not review.
    reviewed($world, reviewReceipt('approve'));
    $delivery = reviewDelivery($world, false);
    expect($complete())->toBe('tasks.handoff_required 409');

    $delivery->delete();
    expect($complete())->toBe('completed')
        ->and($world->subtask->refresh()->status)->toBe(TaskStatus::Completed);

    // The approve covers the commit it reviewed: a check of another commit does not publish, another check of the same commit does.
    $other = TaskDoneFixture::check($world->group, $world->subtask, str_repeat('d', 40));
    expect($publish($other))->toBe('tasks.handoff_required 409');
    $same = TaskDoneFixture::check($world->group, $world->subtask, TaskDoneFixture::Commit);
    expect($publish($same))->toBe('admitted');
});

it('refuses to complete a subtask of a gated group that has no task copy yet', function (): void {
    $world = reviewWorld();
    $world->group->update(['taskable_type' => null, 'taskable_id' => null]);

    try {
        app(UpdateTaskAction::class)->execute($world->group->refresh(), $world->subtask->refresh(),
            new UpdateTaskData(title: null, brief: null, position: null, status: TaskStatus::Completed));
        $outcome = 'completed';
    } catch (ResourceOperationException $error) {
        $outcome = $error->errorCode.' '.$error->status;
    }

    expect($outcome)->toBe('tasks.handoff_required 409')
        ->and($world->subtask->refresh()->status)->toBe(TaskStatus::Running);
});

it('counts only an accepted review for the round of the next review in a gated group', function (): void {
    $world = reviewWorld();

    reviewed($world, null);
    $retry = reviewStart($world, 'reviewer');
    expect($retry->input['turn']['prompt'])->toContain('This is the first review of this subtask');
    reviewEnded($retry, 'done', reviewReceipt('changes', ['The export drops the header row.']));

    expect(reviewStart($world, 'reviewer')->input['turn']['prompt'])->toContain('This is review round 2 of this subtask');
});

it('names the review receipt path and its schema in the prompt of a gated group only', function (bool $gated): void {
    $world = reviewWorld($gated);

    $turn = reviewStart($world, 'reviewer');

    $prompt = $turn->input['turn']['prompt'];
    expect(str_contains($prompt, '`$(git rev-parse --git-path orbit-handoff/review.json)`'))->toBe($gated)
        ->and(str_contains($prompt, '{"verdict": "approve" or "changes", "findings": [{"summary": "<one line>"}]}'))->toBe($gated)
        ->and(strlen($prompt) < TaskTurnPrompt::Limit)->toBeTrue();
})->with(['gated' => [true], 'ungated' => [false]]);

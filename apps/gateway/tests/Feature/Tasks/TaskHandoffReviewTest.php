<?php

declare(strict_types=1);

use App\Actions\Tasks\StartTaskPublishAction;
use App\Actions\Tasks\StartTaskTurnAction;
use App\Actions\Tasks\UpdateTaskAction;
use App\Data\Tasks\StartTaskPublishData;
use App\Data\Tasks\TaskTurnData;
use App\Data\Tasks\UpdateTaskData;
use App\Domain\Instances\ContainerServer;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskFindingCategory;
use App\Domain\Tasks\TaskHandoffs;
use App\Domain\Tasks\TaskHandoffType;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurn;
use App\Domain\Tasks\TaskTurnProjection;
use App\Domain\Tasks\TaskTurnPrompt;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Models\TaskCheck;
use App\Models\TaskHandoff;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
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
 * The reviewer posts BODY from copy INSTANCE, as its prompt shows: with the copy key its model environment carries, from its own Node. A
 * string BODY is sent as the raw JSON text, so a float such as `2.0` reaches the Gateway as written.
 *
 * A request that is no JSON body sends FORM fields as a form, and QUERY as the query string.
 *
 * @param  array<string, mixed>|string  $body
 * @param  array<string, mixed>  $query
 * @param  array<string, mixed>|null  $form
 */
function reviewPosts(int|TaskDoneFixture $instance, array|string $body, array $query = [], ?array $form = null): TestResponse
{
    $instance = Instance::query()->with('node')->findOrFail($instance instanceof TaskDoneFixture ? $instance->instance->id : $instance);
    $environment = app(InstanceContainer::class)->modelEnvironment(InstanceRuntime::query()->where('instance_id', $instance->id)->sole(), 'placeholder');
    preg_match('/^ORBIT_COPY_KEY=([0-9a-f]{64})$/m', $environment, $match);

    $caller = test()->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])->withToken($match[1] ?? '');
    $uri = '/api/v1/copy/handoff'.($query === [] ? '' : '?'.http_build_query($query));
    $server = ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.($match[1] ?? '')];
    if ($form !== null) {
        return $caller->call('POST', $uri, $form, [], [], [...$server, 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
    }

    return is_string($body) ? $caller->call('POST', $uri, [], [], [], [...$server, 'CONTENT_TYPE' => 'application/json'], $body)
        : $caller->postJson($uri, $body);
}

/**
 * The turn posts the review receipt REVIEW while it runs, then its run ends in STATE and asked QUESTIONS.
 *
 * @param  array{verdict: string, findings: list<string>}|null  $review
 * @param  list<array<string, mixed>>  $questions
 */
function reviewEnded(Operation $turn, string $state, ?array $review = null, array $questions = [], string $status = 'succeeded'): Operation
{
    $turn->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-turn']])->save();
    if ($review !== null) {
        reviewPosts(ValidatedData::integer($turn->input['instance_id']), $review)->assertStatus(202);
    }

    return app(TaskTurnProjection::class)->apply($turn->refresh(), ['status' => $status, 'run_id' => 'run-turn', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['turn' => ['state' => $state, 'error' => null, 'ended' => true, 'run_id' => 'run:1', 'launched' => true,
            'questions' => $questions, 'transcript' => [], 'receipts' => ['delivery' => null]]]]);
}

/** A reviewer turn posts RECEIPT, or nothing, and ends done; returns its row. */
function reviewed(TaskDoneFixture $world, ?array $receipt): TaskHandoff
{
    $turn = reviewStart($world, 'reviewer');
    expect($turn)->toBeInstanceOf(Operation::class);
    reviewEnded($turn, 'done', $receipt);

    return TaskHandoff::query()->where('operation_id', $turn->id)->sole();
}

/**
 * The receipt a reviewer posts for VERDICT and the categories FINDINGS, or null where the endpoint would refuse it: the turn then posts
 * nothing.
 *
 * @param  list<string>  $findings
 * @return array{verdict: string, findings: list<string>}|null
 */
function reviewReceipt(string $verdict, array $findings = []): ?array
{
    return ($verdict === 'approve') === ($findings === []) ? ['verdict' => $verdict, 'findings' => $findings] : null;
}

it('accepts the review receipt the reviewer posted, with its verdict and the category of each finding', function (string $verdict, array $findings): void {
    $world = reviewWorld();

    $row = reviewed($world, ['verdict' => $verdict, 'findings' => $findings]);

    expect($row->type)->toBe(TaskHandoffType::Review)
        ->and($row->rejected)->toBeFalse()
        ->and($row->rejection_reason)->toBe('')
        ->and($row->task_id)->toBe($world->subtask->id)
        ->and($row->task_check_id)->toBeNull()
        ->and($row->receipt)->toBe(['verdict' => $verdict, 'findings' => $findings])
        ->and($world->check->refresh()->review_receipt_operation_id)->toBe($row->operation_id);
})->with([
    'approve with no finding' => ['approve', []],
    'changes with one finding' => ['changes', ['race']],
    'changes with a finding of each category, in order' => ['changes', array_column(TaskFindingCategory::cases(), 'value')],
    'changes with one category twice' => ['changes', ['regression', 'docs', 'regression']],
    'changes with the most findings' => ['changes', array_fill(0, 100, 'other')],
]);

it('rejects a review whose turn posted no receipt, and the turn still succeeds', function (): void {
    $row = reviewed(reviewWorld(), null);

    expect($row->only(['type', 'rejected', 'rejection_reason', 'receipt']))
        ->toBe(['type' => TaskHandoffType::Review, 'rejected' => true, 'rejection_reason' => TaskHandoffs::NoReceipt, 'receipt' => null])
        ->and(Operation::query()->findOrFail($row->operation_id)->status)->toBe(OperationStatus::Succeeded);
});

it('refuses a receipt that does not fit the schema with validation.failed, and the reviewer can post again', function (array|string $body, string $field): void {
    $world = reviewWorld();
    $turn = reviewStart($world, 'reviewer');

    reviewPosts($world, $body)->assertStatus(422)->assertJsonPath('error.code', 'validation.failed')->assertJsonValidationErrors([$field], 'error.details');
    reviewPosts($world, ['verdict' => 'approve', 'findings' => []])->assertStatus(202);
    reviewEnded($turn, 'done');

    expect(TaskHandoff::query()->where('operation_id', $turn->id)->sole()->receipt)->toBe(['verdict' => 'approve', 'findings' => []]);
})->with([
    'no verdict' => [['findings' => []], 'verdict'],
    'another verdict' => [['verdict' => 'maybe', 'findings' => []], 'verdict'],
    'no findings' => [['verdict' => 'approve'], 'findings'],
    'null findings' => ['{"verdict":"approve","findings":null}', 'findings'],
    'an integer count for changes' => [['verdict' => 'changes', 'findings' => 2], 'findings'],
    'an integer count of zero for approve' => [['verdict' => 'approve', 'findings' => 0], 'findings'],
    'a category as a bare string' => [['verdict' => 'changes', 'findings' => 'race'], 'findings'],
    'a boolean' => [['verdict' => 'changes', 'findings' => true], 'findings'],
    'an object of categories' => ['{"verdict":"changes","findings":{"a":"race"}}', 'findings'],
    'an unknown category' => [['verdict' => 'changes', 'findings' => ['race', 'style']], 'findings.1'],
    'a category in another case' => [['verdict' => 'changes', 'findings' => ['Race']], 'findings.0'],
    'free text as a category' => [['verdict' => 'changes', 'findings' => ['The export drops the header row.']], 'findings.0'],
    'an integer as a category' => [['verdict' => 'changes', 'findings' => [1]], 'findings.0'],
    'a null category' => ['{"verdict":"changes","findings":[null]}', 'findings.0'],
    'a nested list as a category' => [['verdict' => 'changes', 'findings' => [['race']]], 'findings.0'],
    'an object as a category' => ['{"verdict":"changes","findings":[{"category":"race","note":"free text"}]}', 'findings.0'],
    'more than 100 findings' => [['verdict' => 'changes', 'findings' => array_fill(0, 101, 'other')], 'findings'],
    'approve with a finding' => [['verdict' => 'approve', 'findings' => ['race']], 'findings'],
    'changes with no finding' => [['verdict' => 'changes', 'findings' => []], 'findings'],
]);

it('keeps the last valid post when a malformed one is refused', function (array|string $malformed): void {
    $world = reviewWorld();
    $turn = reviewStart($world, 'reviewer');

    reviewPosts($world, ['verdict' => 'changes', 'findings' => ['contract', 'docs', 'contract']])->assertStatus(202);
    reviewPosts($world, $malformed)->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    reviewEnded($turn, 'done');

    expect(TaskHandoff::query()->where('operation_id', $turn->id)->sole()->receipt)->toBe(['verdict' => 'changes', 'findings' => ['contract', 'docs', 'contract']]);
})->with([
    'an integer count' => [['verdict' => 'changes', 'findings' => 3]],
    'an unknown category' => [['verdict' => 'changes', 'findings' => ['style']]],
    'approve with a finding' => [['verdict' => 'approve', 'findings' => ['race']]],
    'changes with no finding' => [['verdict' => 'changes', 'findings' => []]],
]);

it('refuses a receipt that is not exactly the two fields of the JSON body as sent, and keeps the last valid post', function (string $body, array $query, ?array $form): void {
    $world = reviewWorld();
    $turn = reviewStart($world, 'reviewer');

    reviewPosts($world, ['verdict' => 'changes', 'findings' => ['contract', 'docs', 'contract']])->assertStatus(202);
    reviewPosts($world, $body, $query, $form)->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    reviewEnded($turn, 'done');

    expect(TaskHandoff::query()->where('operation_id', $turn->id)->sole()->receipt)->toBe(['verdict' => 'changes', 'findings' => ['contract', 'docs', 'contract']]);
})->with([
    'an empty object as the findings of an approve' => ['{"verdict":"approve","findings":{}}', [], null],
    'an object with list keys as the findings' => ['{"verdict":"changes","findings":{"0":"race","1":"docs"}}', [], null],
    'a padded verdict' => ['{"verdict":" approve ","findings":[]}', [], null],
    'a padded category' => ['{"verdict":"changes","findings":[" race "]}', [], null],
    'an empty category' => ['{"verdict":"changes","findings":[""]}', [], null],
    'an extra free-text note' => ['{"verdict":"changes","findings":["race"],"note":"The export drops the header row."}', [], null],
    'a key twice' => ['{"verdict":"changes","findings":["race"],"findings":["docs"]}', [], null],
    'form fields' => ['', [], ['verdict' => 'changes', 'findings' => ['race']]],
    'a partial body completed by the query' => ['{"verdict":"changes"}', ['findings' => ['race']], null],
    'an empty body with the query holding both fields' => ['', ['verdict' => 'changes', 'findings' => ['race']], null],
    'a malformed body with the query holding both fields' => ['{"verdict":', ['verdict' => 'changes', 'findings' => ['race']], null],
    'a JSON list as the body' => ['["changes",["race"]]', [], null],
]);

it('counts the last post of a turn, and admits a post only during a running reviewer turn', function (): void {
    $world = reviewWorld();
    reviewPosts($world, ['verdict' => 'approve', 'findings' => []])->assertStatus(409)->assertJsonPath('error.code', 'tasks.pr_text_not_reviewer_turn');

    $turn = reviewStart($world, 'reviewer');
    reviewPosts($world, ['verdict' => 'changes', 'findings' => ['race', 'docs']])->assertStatus(202)->assertJsonPath('data.operation_id', $turn->id);
    reviewEnded($turn, 'done', ['verdict' => 'approve', 'findings' => []]);

    expect(TaskHandoff::query()->where('operation_id', $turn->id)->sole()->receipt)->toBe(['verdict' => 'approve', 'findings' => []]);
    reviewPosts($world, ['verdict' => 'approve', 'findings' => []])->assertStatus(409)->assertJsonPath('error.code', 'tasks.pr_text_not_reviewer_turn');
});

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
    reviewEnded(reviewStart($world, 'reviewer'), 'done', reviewReceipt('changes', ['regression']));
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
    reviewed($world, reviewReceipt('changes', ['regression']));
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
    reviewed($world, reviewReceipt('changes', ['regression']));
    expect($complete())->toBe('tasks.handoff_required 409');
    reviewed($world, reviewReceipt('approve', ['regression']));
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
    reviewEnded($retry, 'done', reviewReceipt('changes', ['regression']));

    expect(reviewStart($world, 'reviewer')->input['turn']['prompt'])->toContain('This is review round 2 of this subtask');
});

it('shows the exact call that posts the review receipt in the prompt of a gated group only', function (bool $gated): void {
    $world = reviewWorld($gated);

    $turn = reviewStart($world, 'reviewer');

    $prompt = $turn->input['turn']['prompt'];
    expect(str_contains($prompt, TaskTurn::reviewReceiptRule()))->toBe($gated)
        ->and(TaskTurn::reviewReceiptRule())->toContain('"$ORBIT_COPY_API/handoff"')->toContain('{"verdict": "changes", "findings": ["regression", "docs"]}')
        ->and(strlen($prompt) < TaskTurnPrompt::Limit)->toBeTrue();
})->with(['gated' => [true], 'ungated' => [false]]);

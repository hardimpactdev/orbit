<?php

declare(strict_types=1);

use App\Actions\Tasks\RequestEndedPullRequestAssistanceAction;
use App\Actions\Tasks\ResumeDeliverableCorrectionAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskConsultation;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnMode;
use App\Domain\Tasks\TaskTurnReceipt;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskTurnReceipts;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Classification;
use Symfony\Component\Process\Process;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\NullTaskReviewDiff;
use Tests\Support\NullTaskWorkspaceDiffReader;

beforeEach(function (): void {
    tick_bind_defaults();
});

/**
 * A task in review whose reviewer has finished the turn after the handoff. Unless it is the last,
 * a later subtask waits behind it.
 *
 * @param  list<string|null>  $receipts
 * @return array{Task, Task, FakeTaskTurnReceipts, object, object}
 */
it('does not apply a receipt from the thread named by a stale turn file', function (): void {
    [$group, $task] = tick_review([null]);
    $acting = (int) $group->reviewer_agent_thread_id;
    app()->instance(TaskTurnReceipts::class, new class($acting) implements TaskTurnReceipts
    {
        public function __construct(private int $acting) {}

        public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?TaskTurnMode $mode = null, ?string $context = null): void {}

        public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt
        {
            expect($actingThreadId)->toBe($this->acting);

            return TaskTurnReceipt::parse((string) json_encode([
                'outcome' => 'approved', 'summary' => 'Approved the earlier subtask.', 'thread' => $this->acting + 1,
            ], JSON_THROW_ON_ERROR));
        }

        public function clear(Instance $instance, TaskTurnReceipt $receipt): void {}

        public function hasLegacyTurn(Instance $instance): bool
        {
            return false;
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(0)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('does not apply an unbound receipt and uses the normal missing-receipt reminder', function (): void {
    [$group, $task] = tick_review([null]);
    $acting = (int) $group->reviewer_agent_thread_id;
    $receipts = new class implements TaskTurnReceipts
    {
        public bool $legacy = true;

        /** @var list<int|null> */
        public array $preparedThreads = [];

        public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?TaskTurnMode $mode = null, ?string $context = null): void
        {
            $this->preparedThreads[] = $threadId;
            $this->legacy = false;
        }

        public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt
        {
            return TaskTurnReceipt::parse((string) json_encode([
                'outcome' => 'approved', 'summary' => 'Approved the earlier subtask.',
            ], JSON_THROW_ON_ERROR));
        }

        public function clear(Instance $instance, TaskTurnReceipt $receipt): void {}

        public function hasLegacyTurn(Instance $instance): bool
        {
            return $this->legacy;
        }
    };
    app()->instance(TaskTurnReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();

    $text = (string) data_get(app(AgentCommandDispatcher::class), 'commands.0.message.text');

    expect($task->comments()->count())->toBe(0)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($receipts->preparedThreads)->toBe([$acting])
        ->and($text)->toContain('No turn receipt was found.')
        ->and($text)->toContain('"$(git rev-parse --git-path orbit)/turn" --thread='.$acting.' --outcome=');
});

it('applies a receipt that names the acting reviewer', function (): void {
    [$group, $task] = tick_review([null], last: true);
    $acting = (int) $group->reviewer_agent_thread_id;
    app()->instance(TaskTurnReceipts::class, new class($acting) implements TaskTurnReceipts
    {
        public function __construct(private int $acting) {}

        public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?TaskTurnMode $mode = null, ?string $context = null): void {}

        public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt
        {
            return TaskTurnReceipt::parse((string) json_encode([
                'outcome' => 'approved', 'summary' => 'Checked this subtask.', 'thread' => $actingThreadId,
            ], JSON_THROW_ON_ERROR));
        }

        public function clear(Instance $instance, TaskTurnReceipt $receipt): void {}

        public function hasLegacyTurn(Instance $instance): bool
        {
            return false;
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->comments()->sole()->getRawOriginal('type'))->toBe('approved')
        ->and($task->comments()->sole()->body)->toBe('Checked this subtask.');
});

it('commits an approved subtask with the title and the reviewer summary, then starts the next subtask', function (): void {
    [$group, $task, $receipts, $signer, $publisher] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Checked the models and their tests.')]);
    $next = Task::query()->where('title', 'Routes')->sole();
    $spawner = new class implements AgentSpawner
    {
        /** @var list<int> */
        public array $implementers = [];

        public function reserveReviewer(Task $task): ?int
        {

            return null;
        }

        public function spawnReviewer(Task $task): ?int
        {
            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->implementers[] = $task->id;

            return test_agent_thread($task->parent, 'implementer-'.$task->id, $task)->id;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentSpawner::class, $spawner);

    app(TaskScheduler::class)->tick();

    $approval = $task->comments()->sole();
    expect($signer->messages)->toBe(["Models\n\nChecked the models and their tests."])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($next->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($spawner->implementers)->toBe([$next->id])
        ->and($approval->getRawOriginal('type'))->toBe('approved')
        ->and($approval->author)->toBe('reviewer')
        ->and($approval->review_attempt)->toBe($task->review_attempt)
        ->and($approval->commit_sha)->toBe(str_repeat('c', 40))
        ->and($publisher->pushes)->toBe([$group->id])
        ->and($publisher->bodies)->toBe([])
        ->and($task->fresh()?->review_handled_comment_id)->toBe($approval->id)
        ->and($receipts->cleared)->toHaveCount(1);
    Classification::assertNothingClassified();
});

it('retries the commit of an approved subtask without storing the approval twice', function (): void {
    [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Looks good.')]);
    $signer->fails = true;

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->communication_failures)->toBe(1);

    $signer->fails = false;
    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(1)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($signer->messages)->toHaveCount(2);
});

it('does not commit an approval while the workspace is on another branch', function (): void {
    [$group, $task, , $signer] = tick_review([FakeTaskTurnReceipts::contents('approved', 'Looks good.'), null], onBranch: false);

    app(TaskScheduler::class)->tick();

    $dispatcher = app(AgentCommandDispatcher::class);
    expect($signer->messages)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($dispatcher->commands[0]['message']['text'])->toBe(TaskTurnFetchNotice::Failed."\n\n".'Orbit could not confirm the review is complete. The workspace branch is not task-'.$group->id.'. Switch back to it. '.TaskTurnInstructions::reviewer(threadId: $group->reviewer_agent_thread_id));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('Checks still failed after the reminder. No turn receipt was found.');
});

it('asks for assistance with the summary of a blocked reviewer receipt', function (): void {
    [$group, $task] = tick_review([FakeTaskTurnReceipts::contents('blocked', 'The brief contradicts ADR 0098.', 'Should the subtask follow the brief or ADR 0098?', [], 'contract_gap')]);

    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe('Should the subtask follow the brief or ADR 0098?')
        ->and($task->fresh()?->assistance_reason)->toBe("The reviewer is blocked: The brief contradicts ADR 0098.\n\nQuestion: Should the subtask follow the brief or ADR 0098?")
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe('Should the subtask follow the brief or ADR 0098?')
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and(app(AgentCommandDispatcher::class)->commands)->toBe([])
        ->and($task->comments()->sole()->cause)->toBe(QuestionCause::ContractGap)
        ->and($question->asked_by)->toBe(QuestionAsker::Reviewer)
        ->and($question->cause)->toBe(QuestionCause::ContractGap)
        ->and($question->status)->toBe(QuestionStatus::Escalated)
        ->and($question->escalated_at)->not->toBeNull()
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->fresh()?->escalations)->toBe(1)
        ->and($group->fresh()?->questions)->toBe(1)
        ->and($group->fresh()?->escalations)->toBe(1);
});

it('answers a direction question from the reviewer receipt that follows the operator resolution', function (): void {
    [$group, $task] = tick_review([
        FakeTaskTurnReceipts::contents('blocked', 'The brief contradicts ADR 0098.', 'Should the subtask follow the brief or ADR 0098?', [], 'contract_gap'),
        FakeTaskTurnReceipts::contents('changes_requested', 'Follow the ADR.', cause: 'missed_contract'),
    ]);
    AgentThread::query()->where('task_group_id', $group->id)->where('role', 'reviewer')->update(['task_id' => $task->id]);
    app(TaskScheduler::class)->tick();

    app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Follow ADR 0098.', 'author' => 'operator',
    ]);
    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Escalated)
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->not->toBeNull()
        ->and(app(TaskTurnReceipts::class)->modes)->toContain('cause')
        ->and(app(TaskTurnReceipts::class)->modes)->not->toContain('relay');
    $reviewerSend = collect(app(AgentCommandDispatcher::class)->commands)->first(fn (array $command): bool => ($command['type'] ?? null) === 'send');
    expect($reviewerSend['message']['text'] ?? null)->toContain('Follow ADR 0098.', '--outcome=changes_requested --summary="The findings the implementer must address" --cause=CAUSE')
        ->and($reviewerSend['message']['text'] ?? null)->not->toContain('This is a relay');

    app(TaskScheduler::class)->tick();

    $question = TaskQuestion::query()->sole();
    expect($question->status)->toBe(QuestionStatus::Answered)
        ->and($question->answered_by)->toBe(QuestionAsker::Operator)
        ->and($question->answer)->toBe('Follow ADR 0098.')
        ->and($question->cause)->toBe(QuestionCause::MissedContract)
        ->and($question->escalated_at)->not->toBeNull()
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->fresh()?->escalations)->toBe(1)
        ->and($group->fresh()?->escalations)->toBe(1);
});

it('leaves a direction question escalated when the following review receipt has no cause', function (): void {
    [$group, $task] = tick_review([
        FakeTaskTurnReceipts::contents('blocked', 'The brief contradicts ADR 0098.', 'Should the subtask follow the brief or ADR 0098?', [], 'scope'),
        FakeTaskTurnReceipts::contents('changes_requested', 'Follow the ADR.'),
    ]);
    AgentThread::query()->where('task_group_id', $group->id)->where('role', 'reviewer')->update(['task_id' => $task->id]);
    app(TaskScheduler::class)->tick();
    app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Follow ADR 0098.', 'author' => 'operator',
    ]);

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Escalated)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and(app(AgentCommandDispatcher::class)->commands)->not->toBe([]);
});

it('starts a reviewer for a held direction resolution and answers from the relay', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    test_update_task($task, [
        'assistance_requested' => true, 'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => 'Which mirror?', 'assistance_reason' => 'Which mirror?',
    ]);
    test_update_task($group, [
        'assistance_requested' => true, 'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => 'Which mirror?', 'assistance_reason' => 'Which mirror?',
    ]);
    TaskQuestion::query()->create([
        'task_id' => $group->id, 'subtask_id' => $task->id, 'attempt' => 1, 'asked_by' => QuestionAsker::Implementer,
        'question' => 'Which mirror?', 'status' => QuestionStatus::Escalated, 'asked_at' => now(), 'escalated_at' => now(),
    ]);
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'contract_gap'),
    ]);
    $dispatcher = tick_dispatcher();
    $state = (object) ['implementer' => 'idle'];
    tick_relay_runtime($receipts, $dispatcher, $state);
    $completion = $task->completion_attempt;
    $review = $task->review_attempt;

    $comment = app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Use the public mirror.', 'author' => 'operator',
    ]);

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBe($comment->id);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->direction_relay_comment_id)->toBe($comment->id)
        ->and($receipts->modes)->not->toBeEmpty()
        ->and($receipts->modes)->each->toBe('relay')
        ->and(AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->where('external_id', 'not like', 'pending:%')->exists())->toBeTrue();

    app(TaskScheduler::class)->tick();

    $delivered = collect($dispatcher->commands)->first(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread');
    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and(TaskQuestion::query()->sole()->cause)->toBe(QuestionCause::ContractGap)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and($task->fresh()?->review_attempt)->toBe($review)
        ->and($delivered['message']['text'] ?? null)->toContain('Use the public mirror.');
});

it('keeps a relay answer while the implementer is busy and delivers it once the implementer is free', function (): void {
    [$group, $task] = tick_held_relay();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'scope'),
    ]);
    $dispatcher = tick_dispatcher();
    $state = (object) ['implementer' => 'idle'];
    tick_relay_runtime($receipts, $dispatcher, $state);
    app(TaskConsultation::class)->collectReceipt($group, $task, TaskThreadRole::Reviewer);
    $state->implementer = 'running';
    $completion = $task->completion_attempt;

    app(TaskScheduler::class)->tick();

    expect($receipts->reads())->toBe(1)
        ->and($dispatcher->commands)->toBe([])
        ->and($task->fresh()?->direction_relay_comment_id)->not->toBeNull()
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and(TaskQuestion::query()->sole()->cause)->toBe(QuestionCause::Scope)
        ->and($task->fresh()?->completion_attempt)->toBe($completion);

    $state->implementer = 'idle';
    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and(TaskQuestion::query()->sole()->cause)->toBe(QuestionCause::Scope)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and(collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread'))->toHaveCount(1);
});

it('retries a relay answer after the implementer send fails without opening another question', function (): void {
    [$group, $task] = tick_held_relay();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'environment'),
    ]);
    $dispatcher = new class implements AgentCommandDispatcher
    {
        public int $failuresLeft = 1;

        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            if ($this->failuresLeft > 0 && ($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread') {
                $this->failuresLeft--;

                throw new AgentDriverException('turn start failed');
            }
            $this->commands[] = $command;

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    tick_relay_runtime($receipts, $dispatcher, (object) ['implementer' => 'idle']);
    $completion = $task->completion_attempt;
    $review = $task->review_attempt;

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and(TaskQuestion::query()->sole()->cause)->toBe(QuestionCause::Environment)
        ->and($task->fresh()?->direction_relay_comment_id)->not->toBeNull()
        ->and($task->fresh()?->review_handled_comment_id)->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and($task->fresh()?->review_attempt)->toBe($review)
        ->and($dispatcher->commands)->toBe([]);

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->review_handled_comment_id)->not->toBeNull()
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and($task->fresh()?->review_attempt)->toBe($review)
        ->and(collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread'))->toHaveCount(1);
});

it('asks for help when a relay answer command stays rejected', function (): void {
    [$group, $task] = tick_held_relay();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'missed_contract'),
    ]);
    $dispatcher = new class implements AgentCommandDispatcher
    {
        /** @var list<string> */
        public array $rejected = [];

        /** @var list<string> */
        public array $attempts = [];

        public function dispatch(Node $node, array $command): array
        {
            if (($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread') {
                $commandId = (string) ($command['commandId'] ?? '');
                $this->attempts[] = $commandId;
                if (in_array($commandId, $this->rejected, true)) {
                    throw new AgentDriverException('OrchestrationCommandPreviouslyRejectedError');
                }
                $this->rejected[] = $commandId;

                throw new AgentDriverException('command rejected');
            }

            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    tick_relay_runtime($receipts, $dispatcher, (object) ['implementer' => 'idle']);
    $completion = $task->completion_attempt;
    $review = $task->review_attempt;

    foreach (range(1, 4) as $attempt) {
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->assistance_requested)->toBeFalse()
            ->and($task->fresh()?->communication_failures)->toBe($attempt)
            ->and(TaskQuestion::query()->count())->toBe(1)
            ->and($task->fresh()?->completion_attempt)->toBe($completion)
            ->and($task->fresh()?->review_attempt)->toBe($review);
    }

    app(TaskScheduler::class)->tick();

    $key = $dispatcher->attempts[0];
    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and($task->fresh()?->review_attempt)->toBe($review)
        ->and($dispatcher->attempts)->toHaveCount(5)
        ->and(array_unique($dispatcher->attempts))->toBe([$key])
        ->and($dispatcher->rejected)->toBe([$key])
        ->and($task->fresh()?->direction_answer_key)->toBe($key);

    app(TaskScheduler::class)->tick();

    expect($dispatcher->attempts)->toHaveCount(5)
        ->and(TaskQuestion::query()->count())->toBe(1);
});

it('delivers a relay answer that was recorded before the send was interrupted', function (): void {
    [$group, $task, $resolution, $question] = tick_held_relay();
    $receipt = TaskComment::query()->create([
        'task_id' => $task->id, 'task_group_id' => $group->id, 'agent_thread_id' => $group->reviewer_agent_thread_id,
        'review_attempt' => $task->review_attempt, 'type' => 'answered', 'body' => 'Use the public mirror.',
        'cause' => QuestionCause::MissedContract, 'author' => 'reviewer', 'receipt_hash' => hash('sha256', 'interrupted-relay'),
        'posted_at' => now(),
    ]);
    $question->update([
        'status' => QuestionStatus::Answered, 'answered_by' => QuestionAsker::Operator, 'answer' => 'Use the public mirror.',
        'cause' => QuestionCause::MissedContract, 'answered_at' => now(), 'answered_comment_id' => $receipt->id,
    ]);
    $dispatcher = tick_dispatcher();
    tick_relay_runtime(new FakeTaskTurnReceipts([null]), $dispatcher, (object) ['implementer' => 'idle']);
    $completion = $task->completion_attempt;

    app(TaskScheduler::class)->tick();

    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->id)->toBe($question->id)
        ->and(TaskQuestion::query()->sole()->answered_comment_id)->toBe($receipt->id)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Answered)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->review_handled_comment_id)->toBe($receipt->id)
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and(collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['threadId'] ?? null) === 'implementer-thread'))->toHaveCount(1);
});

it('replays a held direction review from the stored resolution after the flag write fails', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    test_update_task($task, [
        'status' => TaskStatus::Reviewing, 'review_attempt' => 3, 'review_notified_attempt' => null,
        'assistance_requested' => true, 'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => 'Which ADR?', 'assistance_reason' => 'Which ADR?',
    ]);
    test_update_task($group, [
        'status' => TaskGroupStatus::Reviewing, 'assistance_requested' => true, 'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => 'Which ADR?', 'assistance_reason' => 'Which ADR?',
    ]);
    TaskQuestion::query()->create([
        'task_id' => $group->id, 'subtask_id' => $task->id, 'attempt' => 3, 'asked_by' => QuestionAsker::Reviewer,
        'question' => 'Which ADR?', 'status' => QuestionStatus::Escalated, 'asked_at' => now(), 'escalated_at' => now(),
    ]);
    $questionSeen = false;
    $inject = true;
    DB::beforeExecuting(function (string $sql) use (&$inject, &$questionSeen): void {
        if (! $inject) {
            return;
        }
        $statement = ltrim(strtolower($sql));
        if (str_contains($sql, 'task_questions') && str_starts_with($statement, 'update')) {
            $questionSeen = true;

            return;
        }
        if ($questionSeen && str_contains($sql, '"tasks"') && str_starts_with($statement, 'update')) {
            $inject = false;

            throw new RuntimeException('injected task flag failure');
        }
    });
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);

    expect(fn () => app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Follow ADR 0098.', 'author' => 'operator',
    ]))->toThrow(RuntimeException::class);

    $comment = TaskComment::query()->where('type', 'resolution')->sole();
    expect($questionSeen)->toBeTrue()
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBeNull()
        ->and(TaskComment::query()->where('type', 'resolution')->count())->toBe(1);

    app(TaskExtensionState::class)->enable();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(TaskReviewDiff::class, new NullTaskReviewDiff);
    $dispatcher = tick_dispatcher();
    $reader = new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle'], 'latestTurn' => ['id' => 'review-turn', 'state' => 'done']]];
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, $reader);
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(dispatcher: $dispatcher, reader: $reader));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBe($comment->id)
        ->and(TaskComment::query()->where('type', 'resolution')->count())->toBe(1)
        ->and($comment->fresh()?->review_attempt)->toBe(3);

    app(TaskScheduler::class)->tick();

    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->where('external_id', 'not like', 'pending:%')->sole();
    $opening = collect($dispatcher->commands)->first(fn (array $command): bool => ($command['type'] ?? null) === 'create' && ($command['threadId'] ?? null) === $reviewer->external_id);
    expect($reviewer->id)->toBe($group->fresh()?->reviewer_agent_thread_id)
        ->and($task->fresh()?->resolution_delivered_comment_id)->toBe($comment->id)
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and(data_get($opening, 'message.text'))->toContain('Follow ADR 0098.');
});

it('refuses pending correction recovery for either real ended PR reason on scheduler retry', function (string $held): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $task->update(['deliverable_correction_check_id' => 1, 'completion_attempt' => 2]);
    $comment = TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'type' => 'resolution', 'body' => 'Corrected.', 'author' => 'operator', 'posted_at' => now()]);
    app(ResumeDeliverableCorrectionAction::class)->reserve($task, $comment);
    $pending = $task->fresh()?->deliverable_correction_resume;
    $driver = new FakeAgentDriver;
    $driver->observation = new AgentObservation(AgentThreadState::Idle);
    AgentThread::query()->where('task_group_id', $group->id)->update(['driver' => 'example']);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $receipts = new FakeTaskTurnReceipts([null]);
    app()->instance(TaskTurnReceipts::class, $receipts);
    $group->update(['watched_pr_url' => 'https://github.com/example/app/pull/965', 'watched_pr_state' => 'merged']);
    app(RequestEndedPullRequestAssistanceAction::class)->execute($group);
    $reason = $group->fresh()?->assistance_reason;
    expect(RequestEndedPullRequestAssistanceAction::isReason($reason))->toBeTrue();
    test_update_task(($held === 'task' ? $group : $task), test_assistance_attributes(AssistanceKind::Failure, null, 'Original invalid deliverable hold.'));
    $taskReason = $task->fresh()?->assistance_reason;
    $groupReason = $group->fresh()?->assistance_reason;
    app(TaskExtensionState::class)->enable();

    app(ResumeDeliverableCorrectionAction::class)->execute($task);
    expect($task->fresh()?->assistance_reason)->toBe($taskReason)
        ->and($group->fresh()?->assistance_reason)->toBe($groupReason);
    app(TaskScheduler::class)->tick();
    app(ResumeDeliverableCorrectionAction::class)->execute($task);

    expect($task->fresh()?->deliverable_correction_resume)->toBe($pending)
        ->and($task->fresh()?->completion_attempt)->toBe(2)
        ->and($task->fresh()?->resolution_delivered_comment_id)->toBeNull();
    expect($task->fresh()?->assistance_reason)->toBe($taskReason)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
    expect($receipts->prepared)->toBe([]);
    $noticeKey = $task->fresh()?->ended_pr_notice_key;
    expect($noticeKey)->not->toBe($pending['key']);
    expect(array_column($driver->calls, 'key'))->toBe([$noticeKey]);
})->with(['task', 'group']);

it('preserves a real ended PR hold arriving during correction send before the final guarded commit', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    test_update_task($task, ['assistance_requested' => true, 'deliverable_correction_check_id' => 1, 'completion_attempt' => 2]);
    $comment = TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'type' => 'resolution', 'body' => 'Corrected.', 'author' => 'operator', 'posted_at' => now()]);
    app(ResumeDeliverableCorrectionAction::class)->reserve($task, $comment);
    $pending = $task->fresh()?->deliverable_correction_resume;
    $driver = new FakeAgentDriver;
    $driver->observation = new AgentObservation(AgentThreadState::Idle);
    AgentThread::query()->where('task_group_id', $group->id)->update(['driver' => 'example']);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    $receipts = new FakeTaskTurnReceipts([null]);
    app()->instance(TaskTurnReceipts::class, $receipts);
    $reason = null;
    $transactionLevel = DB::transactionLevel();
    $driver->beforeTurn = function () use ($driver, $group, &$reason, $transactionLevel): void {
        $driver->beforeTurn = null;
        expect(DB::transactionLevel())->toBe($transactionLevel);
        $group->update(['watched_pr_url' => 'https://github.com/example/app/pull/965', 'watched_pr_state' => 'closed']);
        app(RequestEndedPullRequestAssistanceAction::class)->execute($group->fresh() ?? $group);
        $reason = $group->fresh()?->assistance_reason;
    };
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();
    $calls = $driver->calls;
    app(ResumeDeliverableCorrectionAction::class)->execute($task);
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->deliverable_correction_resume)->toBe($pending)
        ->and($task->fresh()?->completion_attempt)->toBe(2)
        ->and($task->fresh()?->resolution_delivered_comment_id)->toBeNull();
    expect(RequestEndedPullRequestAssistanceAction::isReason($reason))->toBeTrue();
    expect($task->fresh()?->assistance_reason)->toBe($reason)
        ->and($group->fresh()?->assistance_reason)->toBe($reason)
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure);
    $noticeKey = $task->fresh()?->ended_pr_notice_key;
    expect($noticeKey)->not->toBe($pending['key']);
    // The ended-PR notice waits while the correction dispatch owns the workspace.
    expect(array_column($calls, 'key'))->toBe([$pending['key']]);
    expect($driver->calls)->toBe($calls)
        ->and($receipts->prepared)->toBe(['implementer']);
    expect(Activity::query()->where('description', 'deliverable correction resumed')->count())->toBe(0);
});

it('supersedes an uncertain correction after completed direction without erasing the newer real turn or receipt', function (string $failure): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $contract = [
        ['id' => 'corrected', 'type' => 'file', 'description' => 'Corrected path.', 'path' => 'tests/CorrectedTest.php', 'change' => 'created'],
        ['id' => 'repro', 'type' => 'command', 'description' => 'Corrected regression.', 'command' => 'vendor/bin/pest tests/CorrectedTest.php', 'directory' => 'apps/gateway', 'fails_on_base' => true, 'paths' => ['tests/CorrectedTest.php']],
    ];
    test_update_task($task, ['assistance_requested' => true, 'deliverable_correction_check_id' => $failure === 'before-reservation' ? null : 1, 'deliverables' => $failure === 'before-reservation' ? [['id' => 'old', 'type' => 'review', 'description' => 'Previous contract.']] : $contract]);
    test_update_task($group, ['assistance_requested' => true]);
    AgentThread::query()->where('task_group_id', $group->id)->where('role', 'reviewer')->update(['task_id' => $task->id]);
    $checkout = sys_get_temp_dir().'/orbit-correction-direction-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($checkout.'/.git/orbit');
    $group->taskable->update(['checkout_path' => $checkout]);
    $state = (object) ['implementer' => 'idle', 'turnId' => 'turn-before'];
    $dispatcher = new class($state, $checkout, $task->implementer_agent_thread_id, $failure) implements AgentCommandDispatcher
    {
        /** @var array<string, array<string, mixed>> */
        public array $accepted = [];

        /** @var list<string> */
        public array $calls = [];

        public ?string $correctionKey = null;

        public function __construct(private object $state, private string $checkout, private ?int $threadId, private string $failure) {}

        public function dispatch(Node $node, array $command): array
        {
            $key = (string) $command['commandId'];
            $this->calls[] = $key;
            $implementer = ($command['threadId'] ?? null) === 'implementer-thread';
            if ($implementer) {
                $this->correctionKey ??= $key;
            }
            if (! isset($this->accepted[$key])) {
                $this->accepted[$key] = $command;
                $turn = json_decode((string) file_get_contents($this->checkout.'/.git/orbit/turn.json'), true);
                $arguments = [$this->checkout.'/.git/orbit/turn', '--thread='.$turn['thread'], '--summary=Authoritative direction result.'];
                $arguments = $implementer ? [...$arguments, '--outcome=ready_for_review', '--deliverable=corrected=Implemented', '--deliverable=repro=Passed'] : [...$arguments, '--outcome=answered', '--cause=scope'];
                (new Process($arguments, cwd: $this->checkout))->mustRun();
                if ($implementer) {
                    $this->state->turnId = $key;
                    $this->state->messageId = $key;
                    file_put_contents($this->checkout.'/.git/orbit/run', $key);
                    file_put_contents($this->checkout.'/.git/orbit/run.json', json_encode(['turn' => $key], JSON_THROW_ON_ERROR));
                } else {
                    $this->state->reviewerTurnId = $key;
                }
            }
            if ($implementer && $key === $this->correctionKey && str_starts_with($this->failure, 'lost-acceptance')) {
                throw new AgentDriverException('Both correction send replies lost after acceptance.');
            }

            return ['sequence' => count($this->accepted), 'thread_id' => (string) $command['threadId']];
        }
    };
    tick_relay_runtime(new FakeTaskTurnReceipts, $dispatcher, $state);
    $preparations = [];
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/unused-local-fixture-key');
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/unused-local-fixture-known-hosts');
    $nativeReceipts = new RemoteTaskTurnReceipts(new TaskWorkspaceExecutor(new DevelopmentSshExecutor(new LocalShellSshExecutor, $keys, $hosts)));
    $receipts = Mockery::mock(TaskTurnReceipts::class);
    $receipts->shouldReceive('prepare')->andReturnUsing(function (Instance $instance, TaskThreadRole $role, bool $final, array $deliverables, ?int $threadId, ?TaskTurnMode $mode = null, ?string $context = null) use ($nativeReceipts, &$preparations): void {
        $preparations[] = $mode?->deliveryKey;
        $nativeReceipts->prepare($instance, $role, $final, $deliverables, $threadId, $mode, $context);
    });
    $receipts->shouldReceive('hasLegacyTurn')->andReturnUsing($nativeReceipts->hasLegacyTurn(...));
    $receipts->shouldReceive('read')->andReturnUsing($nativeReceipts->read(...));
    $receipts->shouldReceive('clear')->andReturnUsing($nativeReceipts->clear(...));
    app()->instance(TaskTurnReceipts::class, $receipts);
    $crash = $failure === 'commit-crash';
    DB::beforeExecuting(function (string $sql) use (&$crash): void {
        if ($crash && str_starts_with($sql, 'update') && str_contains($sql, 'completion_attempt') && str_contains($sql, 'deliverable_correction_resume')) {
            $crash = false;
            throw new RuntimeException('Correction delivery commit crashed.');
        }
    });
    try {
        $actor = $group->taskable->node;
        $requestId = '53a762c6-4d7e-4cae-a9a7-7af51949e1cd';
        if ($failure === 'before-reservation') {
            $this->markAsGateway($actor);
            $this->withServerVariables(['REMOTE_ADDR' => $actor->wireguard_ip]);
            $handoff = TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'type' => TaskCommentType::ReadyForReview, 'body' => 'Invalid deliverable.', 'author' => 'implementer', 'posted_at' => now(), 'completion_attempt' => $task->completion_attempt]);
            $task->update(['completion_handoff_comment_id' => $handoff->id]);
            TaskCheck::query()->create(['task_id' => $task->id, 'task_comment_id' => $handoff->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Failed, 'failed_step' => 'invalid_deliverable', 'pid' => 123, 'process_started' => 'check-start', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(), 'finished_at' => now()]);
            $repository = Mockery::mock(DeliverablePathRepository::class);
            $repository->shouldReceive('files')->once()->andReturn([]);
            app()->instance(DeliverablePathRepository::class, $repository);
            $this->patchJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}", ['deliverables' => $contract])->assertOk();
            expect($task->fresh()?->deliverable_correction_check_id)->not->toBeNull();
            expect($task->fresh()?->deliverable_correction_resume)->toBeNull();
        } else {
            $resolve = fn () => app(StoreTaskCommentAction::class)->execute($task, ['type' => 'resolution', 'body' => 'Resume corrected handoff.', 'author' => 'operator']);
            if ($failure === 'commit-crash') {
                expect($resolve)->toThrow(RuntimeException::class, 'Correction delivery commit crashed.');
            } else {
                $resolve();
            }
            expect($task->fresh()?->deliverable_correction_resume['state'])->toBe('pending');
        }
        app(StoreTaskCommentAction::class)->execute($task, ['type' => 'assistance_requested', 'body' => 'Which approach is authoritative?', 'author' => 'operator']);
        if ($failure === 'before-reservation') {
            $this->withHeader('X-Orbit-Request-Id', $requestId)->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/comments", ['type' => 'resolution', 'body' => 'Use the reviewer direction.', 'author' => 'not-the-authenticated-caller'])->assertCreated();
            $firstReservation = $task->fresh()?->deliverable_correction_resume;
            $this->withHeader('X-Orbit-Request-Id', 'c0ccde43-30c9-44c0-973f-14a665fd2a16')->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/comments", ['type' => 'resolution', 'body' => 'Repeated resolution must not reserve a new delivery.', 'author' => 'another-author'])->assertCreated();
            expect($task->fresh()?->deliverable_correction_resume)->toBe($firstReservation);
            expect($dispatcher->accepted)->toHaveCount(1);
        } else {
            app(StoreTaskCommentAction::class)->execute($task, ['type' => 'resolution', 'body' => 'Use the reviewer direction.', 'author' => 'operator']);
        }
        expect($task->fresh()?->direction_relay_comment_id)->not->toBeNull();

        if ($failure === 'lost-acceptance-direction-commit-crash') {
            $crashDirection = true;
            DB::beforeExecuting(function (string $sql) use (&$crashDirection): void {
                if ($crashDirection && str_starts_with($sql, 'update') && str_contains($sql, 'review_handled_comment_id')) {
                    $crashDirection = false;
                    throw new RuntimeException('Direction delivery commit crashed.');
                }
            });
            test_tick_reporting(RuntimeException::class, 'Direction delivery commit crashed.');
            expect($task->fresh()?->deliverable_correction_resume['state'])->toBe('pending');
            expect($task->fresh()?->direction_relay_comment_id)->not->toBeNull();
        }
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->direction_relay_comment_id)->toBeNull();
        expect($dispatcher->accepted)->toHaveCount($failure === 'before-reservation' ? 2 : 3);
        $continuation = array_last($dispatcher->accepted)['message']['text'];
        $files = ['turn.json', 'receipt.json', 'run', 'run.json'];
        $before = array_map(fn (string $file): string => (string) file_get_contents($checkout.'/.git/orbit/'.$file), $files);
        expect(json_decode($before[0], true)['deliverables'])->toBe([
            ['id' => 'corrected', 'type' => 'file', 'description' => 'Corrected path.'],
            ['id' => 'repro', 'type' => 'command', 'description' => 'Corrected regression.', 'fails_on_base' => true, 'paths' => ['tests/CorrectedTest.php']],
        ]);
        expect(json_decode($before[0], true)['thread'])->toBe($task->implementer_agent_thread_id);
        expect(array_last($dispatcher->accepted)['threadId'])->toBe('implementer-thread');
        $prepared = $preparations;
        $calls = $dispatcher->calls;
        $state->implementer = 'running';
        app(TaskScheduler::class)->tick();
        app(ResumeDeliverableCorrectionAction::class)->execute($task);
        app(ResumeDeliverableCorrectionAction::class)->execute($task);
        expect(array_map(fn (string $file): ?string => file_exists($checkout.'/.git/orbit/'.$file) ? (string) file_get_contents($checkout.'/.git/orbit/'.$file) : null, $files))->toBe($before);
        expect($preparations)->toBe($prepared);
        expect($dispatcher->calls)->toBe($calls);
        expect($continuation)->toContain('The direction above is authoritative.');
        expect(json_decode(explode("\n```", explode("```json\n", $continuation)[1])[0], true))->toBe($contract);
        expect($task->fresh()?->deliverable_correction_resume['state'])->toBe('superseded');
        $audit = Activity::query()->where('description', 'deliverable correction superseded by direction')->sole();
        expect($audit->properties?->get('comment_id'))->toBe($task->fresh()?->deliverable_correction_resume['comment_id']);
        if ($failure === 'before-reservation') {
            expect($audit->getRawOriginal('caller_node_id'))->toBe($actor->id);
            expect($audit->caller_ip)->toBe($actor->wireguard_ip);
            expect($audit->request_id)->toBe($requestId);
            $this->patchJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}", ['deliverables' => $contract])->assertConflict()->assertJsonPath('error.code', 'tasks.deliverables_locked');
            expect($task->fresh()?->deliverables)->toBe($contract);
        }
    } finally {
        File::deleteDirectory($checkout);
    }
})->with(['lost-acceptance', 'commit-crash', 'lost-acceptance-direction-commit-crash', 'before-reservation']);

it('does not send a second implementer turn when an accepted relay answer lost its response', function (): void {
    [$group, $task] = tick_held_relay();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'scope'),
        FakeTaskTurnReceipts::contents('ready_for_review', 'The mirror is wired.'),
    ]);
    $state = (object) ['implementer' => 'idle', 'turnId' => 'turn-before', 'messageId' => null];
    $dispatcher = new class($state) implements AgentCommandDispatcher
    {
        public function __construct(private object $state) {}

        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            if (($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread') {
                $this->commands[] = $command;
                $this->state->turnId = (string) $command['commandId'];
                $this->state->messageId = (string) $command['commandId'];

                throw new AgentDriverException('response lost');
            }

            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    tick_relay_runtime($receipts, $dispatcher, $state);
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $completion = $task->completion_attempt;

    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['commandId'])->toBe($task->fresh()?->direction_answer_key)
        ->and($task->fresh()?->direction_relay_comment_id)->not->toBeNull()
        ->and(collect($receipts->prepared)->filter(fn (string $role): bool => $role === 'implementer'))->toHaveCount(1);

    $receipts->discardImplementerReceiptOnPrepare = true;
    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toHaveCount(1)
        ->and(collect($receipts->prepared)->filter(fn (string $role): bool => $role === 'implementer'))->toHaveCount(1)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and($task->fresh()?->direction_answer_key)->toBeNull()
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and($task->fresh()?->completion_attempt)->toBe($completion);

    app(TaskScheduler::class)->tick();

    expect(TaskComment::query()->where('type', 'ready_for_review')->count())->toBe(1)
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and($task->fresh()?->completion_attempt)->toBe($completion)
        ->and($dispatcher->commands)->toHaveCount(1);
});

it('keeps the implementer handoff when the relay marker update fails after the send', function (): void {
    [$group, $task] = tick_held_relay();
    $receipts = new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('answered', 'Use the public mirror.', cause: 'contract_gap'),
        FakeTaskTurnReceipts::contents('ready_for_review', 'The mirror is wired.'),
    ]);
    $state = (object) ['implementer' => 'idle', 'turnId' => 'turn-before', 'messageId' => null];
    $dispatcher = new class($state) implements AgentCommandDispatcher
    {
        public function __construct(private object $state) {}

        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;
            if (($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread') {
                $this->state->turnId = 'turn-after';
                $this->state->messageId = null;
            }

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    tick_relay_runtime($receipts, $dispatcher, $state);
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    $inject = true;
    DB::beforeExecuting(function (string $sql) use (&$inject): void {
        if ($inject && str_contains($sql, 'review_handled_comment_id')) {
            $inject = false;

            throw new RuntimeException('injected marker failure');
        }
    });
    $completion = $task->completion_attempt;
    $starts = fn (): int => collect($dispatcher->commands)->filter(fn (array $command): bool => ($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread')->count();

    test_tick_reporting(RuntimeException::class);

    expect($starts())->toBe(1)
        ->and($dispatcher->commands[0]['commandId'] ?? null)->toBe($task->fresh()?->direction_answer_key)
        ->and($task->fresh()?->direction_relay_comment_id)->not->toBeNull()
        ->and($task->fresh()?->review_handled_comment_id)->toBeNull();

    $receipts->discardImplementerReceiptOnPrepare = true;
    app(TaskScheduler::class)->tick();

    expect($starts())->toBe(1)
        ->and(collect($receipts->prepared)->filter(fn (string $role): bool => $role === 'implementer'))->toHaveCount(1)
        ->and($task->fresh()?->direction_relay_comment_id)->toBeNull()
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and($task->fresh()?->completion_attempt)->toBe($completion);

    app(TaskScheduler::class)->tick();

    expect(TaskComment::query()->where('type', 'ready_for_review')->count())->toBe(1)
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and($dispatcher->commands)->not->toBeEmpty()
        ->and($starts())->toBe(1);
});

it('reminds a reviewer that ends a turn without a receipt once, then asks for assistance', function (): void {
    [$group, $task, $receipts] = tick_review([null, null]);

    app(TaskScheduler::class)->tick();

    expect(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toBe(TaskTurnFetchNotice::Failed."\n\n".'Orbit could not confirm the review is complete. No turn receipt was found. '.TaskTurnInstructions::reviewer(threadId: $group->reviewer_agent_thread_id))
        ->and($receipts->prepared)->toBe(['reviewer']);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()?->assistance_question)->toBeNull()
        ->and($task->fresh()?->assistance_reason)->toBe('Checks still failed after the reminder. No turn receipt was found.');
    Classification::assertNothingClassified();
});

it('refuses an implementer outcome in a reviewer turn', function (): void {
    [$group, $task, $receipts, $signer] = tick_review([FakeTaskTurnReceipts::contents('ready_for_review')]);

    app(TaskScheduler::class)->tick();

    expect(app(AgentCommandDispatcher::class)->commands[0]['message']['text'])->toContain('The turn receipt was not valid for this turn.')
        ->and($task->comments()->count())->toBe(0)
        ->and($signer->messages)->toBe([])
        ->and($receipts->cleared)->toHaveCount(1);
});

it('starts the reviewer with the first review request when the group has no reviewer yet', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    AgentThread::query()->whereKey($group->reviewer_agent_thread_id)->delete();
    $group->update(['status' => TaskGroupStatus::Reviewing, 'reviewer_agent_thread_id' => null]);
    $task->update(['status' => TaskStatus::Reviewing]);
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread($threadId === 'reviewer-thread-2' ? 'running' : 'done', $threadId);
        }
    });
    $spawner = new class implements AgentSpawner
    {
        /** @var list<int> */
        public array $reviewers = [];

        public function reserveReviewer(Task $task): ?int
        {

            return null;
        }

        public function spawnReviewer(Task $task): ?int
        {
            $this->reviewers[] = $task->id;

            $thread = test_agent_thread($task->parent, 'reviewer-thread-2');
            $thread->update(['task_id' => $task->id]);
            app(TaskWorkspaceLease::class)->turn($task->parent, TaskThreadRole::Reviewer, 'thread:'.$thread->id, $thread->id);
            app(TaskWorkspaceLease::class)->dispatch($thread);

            return $thread->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return null;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentSpawner::class, $spawner);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($spawner->reviewers)->toBe([$task->id])
        ->and($group->fresh()?->reviewer_agent_thread_id)->toBe(AgentThread::query()->where('external_id', 'reviewer-thread-2')->sole()->id)
        ->and($task->fresh()?->review_notified_attempt)->toBe($task->fresh()?->review_attempt)
        ->and(app(TaskTurnReceipts::class)->prepared)->toBe(['reviewer:final']);
});

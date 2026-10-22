<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Models\AgentThread;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Classification;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;
use Tests\Support\NullAgentSpawner;

beforeEach(function (): void {
    tick_bind_defaults();
});

describe('Durable owns Pi recovery', function (): void {
    it('does not resend a failed Pi turn or reserve a Gateway retry', function (string $error, ?string $category): void {
        $task = tick_pi_implementer();
        tick_pi_failure((object) ['turnId' => 'durable-turn', 'error' => $error, 'providerError' => $category]);

        app(TaskScheduler::class)->tick();

        expect($task->fresh()->assistance_requested)->toBeTrue()
            ->and($task->fresh()->assistance_kind)->toBe(AssistanceKind::Failure)
            ->and($task->fresh()->provider_retry)->toBeNull()
            ->and($task->fresh()->pi_restart_resumes)->toBe(0)
            ->and(tick_pi_message_keys())->toBe([])
            ->and(TaskComment::query()->where('task_id', $task->id)->where('type', TaskCommentType::ProviderRetry)->count())->toBe(0);
    })->with([
        'restart error from an old binary' => ['The Pi server restarted during the turn.', null],
        'parked crash loop' => ['Pi Durable parked the turn after its restart limit (crash loop). Operator assistance is required.', null],
        'exhausted overload' => ['Servers overloaded', 'overloaded'],
        'exhausted throttle' => ['429 Too many requests', 'rate_limited'],
        'exhausted server error' => ['503 Service unavailable', 'server_error'],
    ]);

    it('asks for assistance on a terminal reviewer failure without a replacement turn', function (string $error, ?string $category): void {
        $task = tick_pi_implementer();
        $group = $task->parent;
        $reviewer = AgentThread::query()->findOrFail($group->reviewer_agent_thread_id);
        $reviewer->update(['driver' => 'pi']);
        $task->implementerThread->update(['driver' => 'example']);
        $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'handoff-turn', ...tick_review_baseline()]);
        $group->update(['status' => TaskGroupStatus::Reviewing]);
        tick_pi_failure((object) ['sessionId' => $reviewer->external_id, 'turnId' => 'review-turn', 'error' => $error, 'providerError' => $category]);

        app(TaskScheduler::class)->tick();

        expect($task->fresh()->assistance_requested)->toBeTrue()
            ->and($task->fresh()->assistance_reason)->toBe('The reviewer thread failed.')
            ->and($task->fresh()->provider_retry)->toBeNull()
            ->and(tick_pi_message_keys())->toBe([]);
    })->with([
        'restart' => ['The Pi server restarted during the turn.', null],
        'exhausted provider' => ['503 Service unavailable', 'server_error'],
    ]);

    it('leaves a recovering or retrying Pi turn working without sending or requesting assistance', function (): void {
        $task = tick_pi_implementer();
        tick_pi_failure((object) ['state' => 'working', 'turnId' => 'durable-turn', 'error' => 'Waiting for provider retry 1/4 (overloaded) at 2026-10-22T15:00:00Z.', 'providerError' => 'overloaded']);

        app(TaskScheduler::class)->tick();

        expect($task->fresh()->assistance_requested)->toBeFalse()
            ->and($task->fresh()->provider_retry)->toBeNull()
            ->and($task->fresh()->pi_restart_resumes)->toBe(0)
            ->and(tick_pi_message_keys())->toBe([]);
    });
});

it('does not resume a recorded T3 thread after a restart failure', function (string $error): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $task->implementerThread->update(['driver' => 't3']);
    app(TaskExtensionState::class)->enable();
    // A synthetic observation reaches the scheduler's driver guard without a T3 runtime.
    $driver = new FakeAgentDriver('t3');
    $driver->observation = new AgentObservation(AgentThreadState::Failed, error: $error, turnId: 'old-turn');
    $pi = new FakeAgentDriver('pi');
    $pi->observation = new AgentObservation(AgentThreadState::Idle);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver, $pi]));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and($driver->calls)->toBe([])
        ->and($task->fresh()?->pi_restart_resumes)->toBe(0)
        ->and($task->fresh()?->pi_restart_key)->toBeNull();
})->with([
    'Pi restart text on an old T3 row' => ['The Pi server restarted during the turn.'],
    'retired T3 orphaned session error' => ['Provider session did not survive a server restart. Send a new message to continue.'],
    'retired T3 continuation error' => ['Could not continue this thread after the server restart. Send a new message to continue.'],
]);

it('does not replace an open direction request when the implementer thread fails', function (): void {
    $task = tick_pi_implementer();
    $question = 'May the Node user run sudo?';
    $reason = "The implementer is blocked: sudo was denied.\n\nQuestion: {$question}";
    test_update_task($task, [
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);
    test_update_task($task->parent, [
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);
    tick_pi_failure((object) ['turnId' => 'turn-key-1', 'error' => 'The turn was interrupted.']);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe($question)
        ->and($task->fresh()?->assistance_reason)->toBe($reason)
        ->and($task->parent->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->parent->fresh()?->assistance_question)->toBe($question);
});

it('reminds an implementer that ends a turn without a receipt once, then asks for assistance', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle', $threadId);
        }
    });
    $receipts = new FakeTaskTurnReceipts([null, null]);
    app()->instance(TaskTurnReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();

    $reminder = $dispatcher->commands[0]['message']['text'];
    expect($dispatcher->commands)->toHaveCount(1)
        ->and($reminder)->toBe(TaskTurnFetchNotice::Failed."\n\n".'Orbit could not confirm the brief is complete. No turn receipt was found. '.TaskTurnInstructions::implementer(check: $group->project->taskCheckCommand(), threadId: $task->implementer_agent_thread_id))
        ->and($receipts->prepared)->toBe(['implementer'])
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_reason)->toBe('Checks still failed after the reminder. No turn receipt was found.')
        ->and($task->comments()->count())->toBe(0);
    Classification::assertNothingClassified();
});

it('refuses a receipt with an outcome that does not fit the implementer turn, or a blocked receipt without a question', function (string $contents): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $receipts = new FakeTaskTurnReceipts([$contents]);
    app()->instance(TaskTurnReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands[0]['message']['text'])->toContain('The turn receipt was not valid for this turn.')
        ->and($dispatcher->commands[0]['message']['text'])->toContain('--question=')
        ->and($task->comments()->count())->toBe(0)
        ->and($receipts->cleared)->toHaveCount(1);
})->with([
    'a reviewer outcome' => [FakeTaskTurnReceipts::contents('approved')],
    'blocked without a question' => [FakeTaskTurnReceipts::contents('blocked', 'Gateway implementation not completed; required project guidance/bootstrap review and implementation remain.')],
]);

it('dispatches nothing when Jev selects noop', function (): void {
    tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle', $threadId);
        }
    });

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Running);
});

it('runs the artisan tick while the extension is enabled', function (): void {
    app(TaskExtensionState::class)->enable();

    $this->artisan('tasks:tick')
        ->expectsOutput('Routed [0] tasks and started [0] groups.')
        ->assertSuccessful();
});

it('takes the tick lock in the default cache store when CACHE_STORE is unset', function (): void {
    /** @var array{default: string} $cache */
    $cache = config_without_env('cache.php', ['CACHE_STORE']);
    config(['cache.default' => $cache['default']]);
    Cache::lock('orbit:tasks:tick')->forceRelease();
    app(TaskExtensionState::class)->enable();

    $this->artisan('tasks:tick')
        ->expectsOutput('Routed [0] tasks and started [0] groups.')
        ->assertSuccessful();

    $held = Cache::store('file')->lock('orbit:tasks:tick', 300);
    expect($held->get())->toBeTrue();
    $this->artisan('tasks:tick')
        ->expectsOutput('Another tasks tick is already running.')
        ->assertSuccessful();
    $held->release();
});

it('does not classify or advance a task while its agent thread is active', function (string $status): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class($status) implements AgentSnapshotReader
    {
        public function __construct(private string $status) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => $threadId === 'implementer-thread' ? $this->status : 'idle'],
                'messages' => [['role' => 'assistant', 'text' => 'Done. Ready for review.']],
            ]];
        }
    });
    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->tasks()->first()?->status)->toBe(TaskStatus::Running);
})->with(['starting', 'running']);

it('ignores tasks that are not in progress even when they have a thread', function (TaskStatus $status): void {
    $group = tick_group();
    $task = $group->tasks->first();
    $task->update(['status' => $status]);
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            throw new LogicException('Only in-progress tasks should be inspected.');
        }
    });

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($task->fresh()->status)->toBe($status);
    Classification::assertNothingClassified();
})->with([TaskStatus::Todo, TaskStatus::Reserved, TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled]);

it('does not classify an in-progress task without an attached session', function (): void {
    $group = tick_group();
    $group->tasks->first()->update(['implementer_agent_thread_id' => null]);
    AgentThread::query()->where('task_id', $group->tasks->first()->id)->delete();
    app(TaskExtensionState::class)->enable();

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([]);
    Classification::assertNothingClassified();
});

it('defers the idle task handoff check while another task owns the workspace review', function (): void {
    $group = tick_group();
    $workingTask = $group->tasks->first();
    $workingTask->update(['status' => TaskStatus::Reviewing]);
    $idleTask = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 2,
        'title' => 'Second task',
        'brief' => 'Finish the second task.',
        'status' => TaskStatus::Running,
    ]);
    test_agent_thread($group, 'second-task-session', $idleTask);
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSpawner::class, new NullAgentSpawner);
    $reader = new class implements AgentSnapshotReader
    {
        /** @var list<string> */
        public array $requested = [];

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->requested[] = $threadId;

            return ['thread' => [
                'session' => ['status' => $threadId === 'implementer-thread' ? 'running' : 'idle'],
                'messages' => [['role' => 'assistant', 'text' => 'Ready for the next step.']],
            ]];
        }
    };
    app()->instance(AgentSnapshotReader::class, $reader);
    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and(TaskCheck::query()->where('task_id', $idleTask->id)->count())->toBe(0)
        ->and(TaskCheck::query()->where('task_id', $workingTask->id)->count())->toBe(0)
        ->and($workingTask->fresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($idleTask->fresh()->status)->toBe(TaskStatus::Running);
});

it('reminds three failed handoffs and asks for assistance on the fourth', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle', $threadId);
        }
    });
    $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "FAILED tests/Feature/ExportTest.php\n");
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([$failed, $failed, $failed, $failed]));
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('ready_for_review'),
        FakeTaskTurnReceipts::contents('ready_for_review', 'Fixed once.'),
        FakeTaskTurnReceipts::contents('ready_for_review', 'Fixed twice.'),
        FakeTaskTurnReceipts::contents('ready_for_review', 'Fixed three times.'),
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reminder = $dispatcher->commands[0]['message']['text'];
    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($reminder)->toContain("Orbit ran composer check, and it failed with exit code 1. The end of its output:\n\n```\nFAILED tests/Feature/ExportTest.php\n```")
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Failed);

    for ($failure = 2; $failure <= 3; $failure++) {
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($group->fresh()?->assistance_requested)->toBeFalse()
            ->and($dispatcher->commands)->toHaveCount($failure)
            ->and(TaskCheck::query()->count())->toBe($failure);
    }
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($group->fresh()?->assistance_reason)->toBe('The handoff check failed 4 times in a row; see check #'.TaskCheck::query()->latest('id')->firstOrFail()->id.' for its output.')
        ->and($group->tasks()->sole()->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($dispatcher->commands)->toHaveCount(3)
        ->and(TaskCheck::query()->count())->toBe(4);
});

it('starts a fresh handoff failure budget after a pass and ignores other check histories', function (string $history): void {
    $group = tick_group();
    $task = $group->tasks()->sole();
    $owner = $history === 'sibling'
        ? Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Earlier work', 'brief' => 'Done.', 'status' => TaskStatus::Completed])
        : $task;
    $kind = $history === 'baseline' ? 'baseline' : 'handoff';
    foreach ([1, 2, 3, 4] as $pid) {
        TaskCheck::query()->create([
            'task_id' => $owner->id, 'kind' => $kind, 'status' => TaskCheckStatus::Failed,
            'pid' => $pid, 'process_started' => 'old', 'head_before' => str_repeat('a', 40),
            'tree_before' => str_repeat('b', 40), 'started_at' => now(), 'finished_at' => now(),
        ]);
    }
    if ($history === 'passed') {
        TaskCheck::query()->create([
            'task_id' => $task->id, 'kind' => 'handoff', 'status' => TaskCheckStatus::Passed,
            'pid' => 5, 'process_started' => 'passed', 'head_before' => str_repeat('a', 40),
            'tree_before' => str_repeat('b', 40), 'started_at' => now(), 'finished_at' => now(),
        ]);
    }
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle', $threadId);
        }
    });
    $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "Failed again.\n");
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([$failed, $failed, $failed]));
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('ready_for_review'),
        FakeTaskTurnReceipts::contents('ready_for_review', 'Fixed once.'),
        FakeTaskTurnReceipts::contents('ready_for_review', 'Fixed twice.'),
    ]));
    $scheduler = app(TaskScheduler::class);

    foreach ([1, 2, 3] as $failure) {
        $scheduler->tick();
        $scheduler->tick();
    }

    expect($task->fresh()->assistance_requested)->toBeFalse()
        ->and($group->fresh()->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands)->toHaveCount(3);
})->with(['passed', 'sibling', 'baseline']);

it('shows an unexpected check error as a failed check whose output ends with the error', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle', $threadId);
        }
    });
    $output = "composer check\n\nTraceback (most recent call last):\nFileNotFoundError: [Errno 2] No such file or directory: 'gateway'\n";
    $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], $output, failedStep: 'check_error');
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([$failed]));
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('ready_for_review'), null,
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reminder = $dispatcher->commands[0]['message']['text'];
    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($reminder)->toContain('Orbit ran composer check, and it failed with exit code 1.')
        ->and($reminder)->toContain("FileNotFoundError: [Errno 2] No such file or directory: 'gateway'")
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Failed)
        ->and(TaskCheck::query()->sole()->failed_step)->toBe('check_error');
});

it('keeps an unexpected check error failed when the tree changed during the run', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle', $threadId);
        }
    });
    $output = "rm -rf app\n\nTraceback (most recent call last):\nFileNotFoundError: [Errno 2] No such file or directory\n";
    $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('c', 40), ['app'], $output, failedStep: 'check_error');
    $checks = new FakeTaskCheckRunner([$failed]);
    app()->instance(TaskCheckRunner::class, $checks);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('ready_for_review'), null,
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reminder = $dispatcher->commands[0]['message']['text'];
    $check = TaskCheck::query()->sole();
    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($checks->starts)->toBe(1)
        ->and($check->status)->toBe(TaskCheckStatus::Failed)
        ->and($check->failed_step)->toBe('check_error')
        ->and($check->changed_paths)->toBe(['app'])
        ->and($reminder)->toContain('FileNotFoundError: [Errno 2] No such file or directory')
        ->and($reminder)->not->toContain('workspace changed');
});

it('retries the reviewer nudge until the handoff send succeeds', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $spawner = new class implements AgentSpawner
    {
        public int $spawns = 0;

        public function reserveReviewer(Task $task): ?int
        {

            return null;
        }

        public function spawnReviewer(Task $task): ?int
        {
            $this->spawns++;
            if ($this->spawns === 1) {
                return null;
            }
            $thread = test_agent_thread($task->parent, 'reviewer-spawned');
            $thread->update(['task_id' => $task->id]);

            return $thread->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return null;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentSpawner::class, $spawner);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle', $threadId);
        }
    });

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $task = $group->tasks()->first();
    expect($spawner->spawns)->toBe(1)
        ->and($task?->status)->toBe(TaskStatus::Reviewing)
        ->and($task?->review_notified_attempt)->toBeNull()
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($spawner->spawns)->toBe(2)
        ->and($task?->fresh()?->review_notified_attempt)->toBe($task?->review_attempt)
        ->and($task?->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($spawner->spawns)->toBe(2)
        ->and($task?->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($group->fresh()?->assistance_requested)->toBeFalse();
});

it('waits for a newer reviewer turn before asking for an outcome', function (?string $observedTurn): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $attempt = $task->fresh()?->review_attempt ?? 1;
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update([
        'status' => TaskStatus::Reviewing,
        'review_notified_attempt' => $attempt,
        'review_notified_turn_id' => 'turn-old',
    ]);
    $reader = new class($observedTurn) implements AgentSnapshotReader
    {
        public function __construct(public ?string $turnId) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => 'done'],
                'latestTurn' => ['id' => $this->turnId, 'state' => 'completed'],
            ]];
        }
    };
    $dispatcher = tick_dispatcher();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, $reader);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([null]));

    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);

    $reader->turnId = 'turn-new';
    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain('No turn receipt was found.');
})->with(['turn-old', null, '']);

it('retries review findings until the implementer receives them', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, ...tick_review_baseline()]);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('changes_requested', 'Add the missing test.')]));
    $dispatcher = new class implements AgentCommandDispatcher
    {
        public int $calls = 0;

        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->calls++;
            $this->commands[] = $command;
            if ($this->calls === 1) {
                throw new AgentDriverException('relay failed');
            }

            return ['sequence' => $this->calls, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'review-turn', 'state' => 'completed']]];
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->comments()->sole()->getRawOriginal('type'))->toBe('changes_requested')
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($dispatcher->commands[1]['message']['text'])->toContain('Add the missing test.')
        ->and($dispatcher->commands[1]['message']['text'])->toContain(TaskTurnInstructions::implementer(check: $group->project->taskCheckCommand(), threadId: $task->implementer_agent_thread_id))
        ->and($dispatcher->commands[1]['commandId'])->toBe($dispatcher->commands[0]['commandId'])
        // Retry the admitted send without recapturing metadata or the review baseline.
        ->and(app(TaskTurnReceipts::class)->prepared)->toBe(['implementer']);
});

it('repeated reminder send failures reach assistance', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            if ($threadId === 'reviewer-thread') {
                return tick_checked_thread('done', $threadId);
            }

            return ['thread' => ['session' => ['status' => 'waiting'], 'pendingApprovals' => [['requestId' => 'pending-1']]]];
        }
    });
    app()->instance(AgentCommandDispatcher::class, new class implements AgentCommandDispatcher
    {
        public function dispatch(Node $node, array $command): array
        {
            throw new AgentDriverException('send failed');
        }
    });
    for ($i = 0; $i < 6; $i++) {
        app(TaskScheduler::class)->tick();
    }
    expect($group->tasks()->sole()->communication_failures)->toBeGreaterThanOrEqual(5);
    expect($group->fresh()->assistance_requested)->toBeTrue();
});

it('handoff waits while reviewer still reports its old turn', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            $snapshot = tick_checked_thread($threadId === 'implementer-thread' || $threadId === 'reviewer-thread' ? 'done' : 'running');
            $snapshot['thread']['latestTurn'] = [
                'id' => $threadId === 'implementer-thread' || $threadId === 'reviewer-thread' ? $threadId.'-old' : 'opening',
                'state' => $threadId === 'implementer-thread' || $threadId === 'reviewer-thread' ? 'completed' : 'running',
            ];

            return $snapshot;
        }
    });
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $reviewerId = $group->fresh()?->reviewer_agent_thread_id;
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Reviewing)
        ->and(array_column($dispatcher->commands, 'type'))->toBe(['create'])
        ->and(AgentThread::query()->find($reviewerId)?->task_id)->toBe($group->tasks()->sole()->id);
    app(TaskScheduler::class)->tick();
    expect($dispatcher->commands)->toHaveCount(1);
});

it('unavailable implementer uses observation grace instead of rubric', function (): void {
    $group = tick_group();
    AgentThread::query()->where('task_id', $group->tasks->sole()->id)->update(['state' => 'done']);
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return null;
        }
    });
    app(TaskScheduler::class)->tick();
    expect($dispatcher->commands)->toBe([]);
    expect($group->fresh()->agent_unavailable_since)->not->toBeNull();
});
it('an unavailable reviewer cannot advance an approval', function (): void {
    [$group, $task, $receipts, $signer] = tick_review([FakeTaskTurnReceipts::contents('approved')]);
    AgentThread::query()->where('task_group_id', $group->id)->update(['state' => 'done']);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return null;
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($receipts->reads())->toBe(0)
        ->and($signer->messages)->toBe([]);
});

it('relayed findings require a newer implementer turn, a new receipt, and a new check before review', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, ...tick_review_baseline()]);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('changes_requested', 'Fix the regression before requesting review again.'),
        null,
        FakeTaskTurnReceipts::contents('ready_for_review'),
    ]));
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    $reader = new class implements AgentSnapshotReader
    {
        public string $turnId = 'before-findings';

        public string $checkId = 'check-1';

        public function snapshot(Node $node, string $threadId): ?array
        {
            $snapshot = tick_checked_thread('done');
            $snapshot['thread']['latestTurn'] = ['id' => $this->turnId, 'state' => 'completed'];
            $snapshot['thread']['activities'][0]['id'] = $this->checkId;

            return $snapshot;
        }
    };
    app()->instance(AgentSnapshotReader::class, $reader);

    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect(app(AgentCommandDispatcher::class)->commands)->toHaveCount(1);
    Classification::assertNothingClassified();

    $reader->turnId = 'after-findings';
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect(app(AgentCommandDispatcher::class)->commands[1]['message']['text'])->toContain('No turn receipt was found.');

    $reader->turnId = 'after-reminder';
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running)
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Running);

    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Reviewing);
});

it('retains reminder send failures across successful classifications and clears them on delivery', function (TaskStatus $status): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => $status === TaskStatus::Reviewing ? TaskGroupStatus::Reviewing : TaskGroupStatus::Running]);
    $task->update(['status' => $status, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'old-turn']);
    app(TaskExtensionState::class)->enable();
    $receipts = new FakeTaskTurnReceipts([]);
    app()->instance(TaskTurnReceipts::class, $receipts);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'new-turn', 'state' => 'completed']]];
        }
    });
    $dispatcher = new class implements AgentCommandDispatcher
    {
        public bool $fails = true;

        /** @var list<array<string, mixed>> */
        public array $attempts = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->attempts[] = $command;
            if ($this->fails) {
                throw new AgentDriverException('send failed');
            }

            return ['sequence' => 1, 'thread_id' => $command['threadId']];
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->communication_failures)->toBe(2)
        ->and($receipts->reads())->toBe(1)
        ->and($dispatcher->attempts)->toHaveCount(2)
        ->and($dispatcher->attempts[1])->toBe($dispatcher->attempts[0]);

    $dispatcher->fails = false;
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->communication_failures)->toBe(0);
    expect($group->fresh()->assistance_requested)->toBeFalse();
})->with([TaskStatus::Running, TaskStatus::Reviewing]);

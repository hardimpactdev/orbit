<?php

declare(strict_types=1);

use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnFetchNotice;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Models\AgentThread;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

describe('transient provider retries', function (): void {
    it('waits a minute then resumes the same turn and clears the budget on success', function (): void {
        $this->freezeTime();
        $task = tick_pi_implementer();
        $state = (object) ['turnId' => 'overloaded-turn', 'error' => 'Servers overloaded', 'providerError' => 'overloaded'];
        tick_pi_failure($state);

        app(TaskScheduler::class)->tick();
        $retry = $task->fresh()->provider_retry;
        expect($retry['due_at'])->toBe(now()->addMinute()->toIso8601String())
            ->and($retry['history'])->toHaveCount(1)
            ->and(tick_pi_message_keys())->toBe([])
            ->and($task->fresh()->assistance_requested)->toBeFalse();
        $comment = TaskComment::query()->where('task_id', $task->id)->where('type', TaskCommentType::ProviderRetry)->sole();
        expect($comment->body)->toContain('1/4', 'overloaded-turn', 'overloaded', $retry['due_at']);

        $this->travel(59)->seconds();
        app(TaskScheduler::class)->tick();
        expect(tick_pi_message_keys())->toBe([]);
        $this->travel(1)->seconds();
        app(TaskScheduler::class)->tick();
        expect(tick_pi_message_keys())->toBe([$retry['key']]);

        // A lost response or stale observation reuses the committed key, not another attempt.
        app(TaskScheduler::class)->tick();
        expect(tick_pi_message_keys())->toBe([$retry['key'], $retry['key']])
            ->and($task->fresh()->provider_retry['history'])->toHaveCount(1);
        $state->turnId = $retry['key'];
        $state->state = 'working';
        app(TaskScheduler::class)->tick();
        expect($task->fresh()->provider_retry['history'])->toHaveCount(1);
        $state->state = 'done';
        $state->providerError = null;
        $state->error = null;
        app(TaskScheduler::class)->tick();
        expect($task->fresh()->provider_retry)->toBeNull()
            ->and($task->fresh()->assistance_requested)->toBeFalse()
            ->and(TaskComment::query()->where('task_id', $task->id)->where('type', TaskCommentType::ProviderRetry)->count())->toBe(1);
    });

    it('backs off four times and asks for assistance only after the last retry fails', function (): void {
        $this->freezeTime();
        $task = tick_pi_implementer();
        $state = (object) ['turnId' => 'original-turn', 'error' => 'Servers overloaded', 'providerError' => 'overloaded'];
        tick_pi_failure($state);
        $keys = [];
        foreach ([1, 2, 4, 8] as $index => $minutes) {
            app(TaskScheduler::class)->tick();
            $retry = $task->fresh()->provider_retry;
            expect($retry['due_at'])->toBe(now()->addMinutes($minutes)->toIso8601String())
                ->and($retry['history'])->toHaveCount($index + 1)
                ->and($task->fresh()->assistance_requested)->toBeFalse()
                ->and($task->parent->fresh()->assistance_requested)->toBeFalse()
                ->and(tick_pi_message_keys())->toBe($keys);
            $this->travel($minutes)->minutes();
            app(TaskScheduler::class)->tick();
            $keys[] = $retry['key'];
            expect(tick_pi_message_keys())->toBe($keys);
            $state->turnId = $retry['key'];
        }

        app(TaskScheduler::class)->tick();

        expect($task->fresh()->assistance_requested)->toBeTrue()
            ->and($task->fresh()->assistance_kind)->toBe(AssistanceKind::Failure)
            ->and($task->parent->fresh()->assistance_requested)->toBeTrue()
            ->and($task->fresh()->assistance_reason)->toContain('exhausted after 4 retries', 'original-turn', 'retry 1/4', 'retry 4/4', $state->turnId)
            ->and(tick_pi_message_keys())->toBe($keys)
            ->and(TaskComment::query()->where('task_id', $task->id)->where('type', TaskCommentType::ProviderRetry)->count())->toBe(4);
    });

    it('does not retry unclassified errors or a provider failure without a turn id', function (string $error, ?string $category, string $turn): void {
        $task = tick_pi_implementer();
        tick_pi_failure((object) ['turnId' => $turn, 'error' => $error, 'providerError' => $category]);

        app(TaskScheduler::class)->tick();

        expect($task->fresh()->assistance_requested)->toBeTrue()
            ->and($task->fresh()->provider_retry)->toBeNull()
            ->and(tick_pi_message_keys())->toBe([]);
    })->with([
        'old server has no classification' => ['Servers overloaded', null, 'turn-1'],
        'check failure' => ['composer check failed', null, 'turn-1'],
        'tool failure' => ['Command exited with code 1', null, 'turn-1'],
        'refusal' => ['The model refused the request', null, 'turn-1'],
        'missing turn' => ['Servers overloaded', 'overloaded', ''],
    ]);

    it('retries a rate-limited reviewer on its own thread without advancing the review', function (): void {
        $this->freezeTime();
        $task = tick_pi_implementer();
        $group = $task->parent;
        AgentThread::query()->whereKey($task->implementer_agent_thread_id)->update(['driver' => 'example']);
        AgentThread::query()->whereKey($group->reviewer_agent_thread_id)->update(['driver' => 'pi', 'task_id' => $task->id]);
        $group->update(['status' => TaskGroupStatus::Reviewing, 'reviewer_agent_driver' => 'pi']);
        $task->update([
            'status' => TaskStatus::Reviewing,
            'review_notified_attempt' => $task->review_attempt,
            'review_notified_turn_id' => 'handoff-turn',
            ...tick_review_baseline(),
        ]);
        tick_pi_failure((object) [
            'sessionId' => 'reviewer-thread', 'turnId' => 'review-turn',
            'error' => 'Too many requests', 'providerError' => 'rate_limited',
        ]);

        app(TaskScheduler::class)->tick();
        expect(tick_pi_message_keys())->toBe([])
            ->and($task->fresh()->provider_retry)->toBeArray();
        $this->travel(1)->minutes();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()->status)->toBe(TaskStatus::Reviewing)
            ->and($task->fresh()->assistance_requested)->toBeFalse()
            ->and($task->fresh()->provider_retry['thread_id'])->toBe($group->reviewer_agent_thread_id)
            ->and(tick_pi_message_keys())->toBe([$task->fresh()->provider_retry['key']]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sessions/reviewer-thread/messages')
            && str_contains((string) $request['text'], 'Continue the same assignment'));
    });

    it('preserves the provider budget across a server-restart resume', function (): void {
        $this->freezeTime();
        $task = tick_pi_implementer();
        $state = (object) ['turnId' => 'turn-1', 'error' => 'Overloaded', 'providerError' => 'overloaded'];
        tick_pi_failure($state);
        app(TaskScheduler::class)->tick();
        $this->travel(1)->minutes();
        app(TaskScheduler::class)->tick();
        $state->turnId = $task->fresh()->provider_retry['key'];
        $state->error = TaskScheduler::PiServerRestartError;
        $state->providerError = null;
        app(TaskScheduler::class)->tick();
        $state->turnId = $task->fresh()->pi_restart_key;
        $state->error = 'Overloaded';
        $state->providerError = 'overloaded';

        app(TaskScheduler::class)->tick();

        expect($task->fresh()->provider_retry['history'])->toHaveCount(2)
            ->and($task->fresh()->provider_retry['due_at'])->toBe(now()->addMinutes(2)->toIso8601String())
            ->and($task->fresh()->assistance_requested)->toBeFalse()
            ->and(tick_pi_message_keys())->toHaveCount(2);
    });

    it('gives a new unrelated turn a fresh retry budget', function (): void {
        $this->freezeTime();
        $task = tick_pi_implementer();
        $state = (object) ['turnId' => 'turn-1', 'error' => 'Unavailable', 'providerError' => 'server_error'];
        tick_pi_failure($state);
        app(TaskScheduler::class)->tick();
        $first = $task->fresh()->provider_retry;
        $state->turnId = 'operator-turn';

        app(TaskScheduler::class)->tick();

        expect($task->fresh()->provider_retry['history'])->toHaveCount(1)
            ->and($task->fresh()->provider_retry['key'])->not->toBe($first['key'])
            ->and($task->fresh()->provider_retry['source_turn_id'])->toBe('operator-turn');
    });
});

it('resumes a Pi implementer restarted during the turn instead of asking for assistance', function (): void {
    $task = tick_pi_implementer();
    $notifier = new class implements CoderSettleNotifier
    {
        public bool $called = false;

        public function notify(Task $group): void
        {
            $this->called = true;
        }

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
        {
            $this->called = true;
        }

        public function assistance(Task $group, string $reason): void
        {
            $this->called = true;
        }
    };
    app()->instance(CoderSettleNotifier::class, $notifier);
    tick_pi_failure((object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.']);

    app(TaskScheduler::class)->tick();

    $sent = collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->first(fn (Request $request): bool => str_ends_with($request->url(), '/messages'));
    $fresh = $task->fresh();
    expect($fresh?->assistance_reason)->toBeNull()
        ->and($fresh?->assistance_requested)->toBeFalse()
        ->and($fresh?->status)->toBe(TaskStatus::Running)
        ->and($task->parent->fresh()?->assistance_requested)->toBeFalse()
        ->and($notifier->called)->toBeFalse()
        ->and($sent)->toBeInstanceOf(Request::class)
        ->and($sent['text'])->toBe(TaskTurnFetchNotice::Failed."\n\n".TaskScheduler::PiServerRestartContinue)
        ->and($sent['key'])->not->toBe('turn-key-1')
        ->and($sent['key'])->toBeUuid()
        ->and($fresh?->pi_restart_resumes)->toBe(1)
        ->and($fresh?->pi_restart_key)->toBe($sent['key'])
        ->and($fresh?->pi_restart_source_turn_id)->toBe('turn-key-1')
        ->and($fresh?->pi_restart_thread_id)->toBe($task->implementer_agent_thread_id)
        ->and($fresh?->pi_restart_reservation)->toBe('pending')
        ->and($fresh?->communication_failures)->toBe(0);
});

it('retries the same Pi resume key while that turn is still unaccepted', function (): void {
    $task = tick_pi_implementer();
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.'];
    tick_pi_failure($state);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $fresh = $task->fresh();
    $reserved = $fresh?->pi_restart_key;
    expect($reserved)->toBeString()->not->toBe('turn-key-1')
        ->and(tick_pi_message_keys())->toBe([$reserved, $reserved])
        ->and($fresh?->pi_restart_resumes)->toBe(1)
        ->and($fresh?->pi_restart_reservation)->toBe('pending')
        ->and($fresh?->assistance_requested)->toBeFalse();
});

it('asks for assistance when a Pi implementer fails for another reason', function (): void {
    $task = tick_pi_implementer();
    tick_pi_failure((object) ['turnId' => 'turn-key-1', 'error' => 'The turn was interrupted.']);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->fresh()?->assistance_question)->toBeNull()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and($task->parent->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
        ->and($task->parent->fresh()?->assistance_question)->toBeNull()
        ->and($task->parent->fresh()?->assistance_requested)->toBeTrue()
        ->and(tick_pi_message_keys())->toBe([])
        ->and($task->fresh()?->pi_restart_resumes)->toBe(0);
});

it('does not replace an open direction request when the implementer thread fails', function (): void {
    $task = tick_pi_implementer();
    $question = 'May the Node user run sudo?';
    $reason = "The implementer is blocked: sudo was denied.\n\nQuestion: {$question}";
    $task->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);
    $task->parent->update([
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

it('asks for assistance after two reserved Pi resumes', function (): void {
    $task = tick_pi_implementer();
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.'];
    tick_pi_failure($state);
    app(TaskScheduler::class)->tick();
    $first = $task->fresh()?->pi_restart_key;
    expect($first)->toBeString()->and($task->fresh()?->pi_restart_resumes)->toBe(1);

    $state->turnId = (string) $first;
    app(TaskScheduler::class)->tick();
    $second = $task->fresh()?->pi_restart_key;
    expect($second)->toBeString()->not->toBe($first)
        ->and($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->pi_restart_source_turn_id)->toBe($first)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(tick_pi_message_keys())->toBe([$first, $second]);

    $state->turnId = (string) $second;
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->pi_restart_key)->toBe($second)
        ->and(tick_pi_message_keys())->toBe([$first, $second]);
});

it('retries the stored Pi resume key when the send throws before Pi accepts it', function (): void {
    $task = tick_pi_implementer();
    $posts = 0;
    Http::fake(function (Request $request) use (&$posts) {
        if (str_ends_with($request->url(), '/messages')) {
            $posts++;
            if ($posts <= 2) {
                return Http::response(['error' => ['code' => 'unavailable', 'message' => 'down']], 503);
            }

            return Http::response(['duplicate' => false], 202);
        }
        if (! str_contains($request->url(), '/sessions/implementer-thread')) {
            return Http::response([], 404);
        }

        return Http::response([
            'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 4,
            'session' => ['id' => 'implementer-thread'],
            'state' => 'failed', 'error' => 'The Pi server restarted during the turn.', 'turnId' => 'turn-key-1', 'entries' => [],
        ]);
    });

    app(TaskScheduler::class)->tick();

    $reserved = $task->fresh()?->pi_restart_key;
    expect($reserved)->toBeString()->not->toBe('turn-key-1')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(1)
        ->and($task->fresh()?->pi_restart_reservation)->toBe('pending')
        ->and($task->fresh()?->pi_restart_source_turn_id)->toBe('turn-key-1')
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(tick_pi_message_keys())->toBe([$reserved, $reserved]);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->pi_restart_resumes)->toBe(1)
        ->and($task->fresh()?->pi_restart_key)->toBe($reserved)
        ->and($task->fresh()?->pi_restart_reservation)->toBe('pending')
        ->and($task->fresh()?->communication_failures)->toBe(0)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(tick_pi_message_keys())->toBe([$reserved, $reserved, $reserved]);
});

it('reserves a new Pi resume for a restart during the turn that followed an accepted resume', function (): void {
    $task = tick_pi_implementer();
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([null, null]));
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.', 'state' => 'failed'];
    tick_pi_failure($state);
    app(TaskScheduler::class)->tick();
    $accepted = $task->fresh()?->pi_restart_key;
    expect($accepted)->toBeString()->and($task->fresh()?->pi_restart_resumes)->toBe(1);

    $state->state = 'idle';
    $state->turnId = (string) $accepted;
    $state->error = null;
    app(TaskScheduler::class)->tick();

    $reminder = tick_pi_message_keys()[1] ?? null;
    expect($task->fresh()?->pi_restart_reservation)->toBe('accepted')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(1)
        ->and($task->fresh()?->pi_restart_key)->toBe($accepted)
        ->and($reminder)->toBeString()->not->toBe($accepted)
        ->and(collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/messages'))->values()[1][0]['text'])
        ->toContain('No turn receipt was found.');

    $state->state = 'failed';
    $state->turnId = (string) $reminder;
    $state->error = 'The Pi server restarted during the turn.';
    app(TaskScheduler::class)->tick();

    $fresh = $task->fresh();
    $keys = tick_pi_message_keys();
    expect($fresh?->assistance_requested)->toBeFalse()
        ->and($fresh?->pi_restart_resumes)->toBe(2)
        ->and($fresh?->pi_restart_reservation)->toBe('pending')
        ->and($fresh?->pi_restart_source_turn_id)->toBe($reminder)
        ->and($fresh?->pi_restart_key)->toBeString()->not->toBe($accepted)->not->toBe($reminder)
        ->and($keys)->toBe([$accepted, $reminder, $fresh?->pi_restart_key])
        ->and(array_count_values($keys)[$accepted])->toBe(1);
});

it('does not reset Pi resumes when a resolution is delivered and asks after the cap', function (): void {
    $task = tick_pi_implementer();
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.'];
    tick_pi_failure($state);
    app(TaskScheduler::class)->tick();
    $first = (string) $task->fresh()?->pi_restart_key;
    $state->turnId = $first;
    app(TaskScheduler::class)->tick();
    $second = (string) $task->fresh()?->pi_restart_key;
    $state->turnId = $second;
    app(TaskScheduler::class)->tick();
    expect($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->assistance_requested)->toBeTrue();

    app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Continue from the checkout.', 'author' => 'operator',
    ]);

    $afterResolution = $task->fresh();
    expect($afterResolution?->assistance_requested)->toBeFalse()
        ->and($afterResolution?->pi_restart_resumes)->toBe(2)
        ->and($afterResolution?->pi_restart_key)->toBe($second)
        ->and($afterResolution?->pi_restart_reservation)->toBe('accepted')
        ->and($afterResolution?->pi_restart_source_turn_id)->toBe($first)
        ->and($afterResolution?->pi_restart_thread_id)->toBe($task->implementer_agent_thread_id);

    $state->turnId = 'resolution-turn';
    app(TaskScheduler::class)->tick();

    $keys = tick_pi_message_keys();
    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->pi_restart_key)->toBe($second)
        ->and($task->fresh()?->pi_restart_reservation)->toBe('accepted')
        ->and(array_count_values($keys)[$second] ?? 0)->toBe(1)
        ->and($keys)->not->toContain('resolution-turn');
});

it('does not send an implementer Pi resume to the reviewer', function (): void {
    $task = tick_pi_implementer();
    $reviewer = AgentThread::query()->findOrFail($task->parent->reviewer_agent_thread_id);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([app(PiDriver::class)]));
    $reviewer->update(['driver' => 'pi']);
    $implementer = (object) ['state' => 'failed', 'error' => 'The Pi server restarted during the turn.', 'turnId' => 'impl-turn'];
    $review = (object) ['state' => 'idle', 'error' => null, 'turnId' => 'old-review'];
    /** @var list<array{session: string, key: string, text: string}> $messages */
    $messages = [];
    Http::fake(function (Request $request) use ($implementer, $review, &$messages) {
        if (preg_match('#/sessions/([^/]+)/messages$#', $request->url(), $match) === 1) {
            $messages[] = ['session' => $match[1], 'key' => (string) $request['key'], 'text' => (string) $request['text']];

            return Http::response(['duplicate' => false], 202);
        }
        if (preg_match('#/sessions/([^/]+)$#', $request->url(), $match) !== 1) {
            return Http::response([], 404);
        }
        $snap = $match[1] === 'reviewer-thread' ? $review : $implementer;

        return Http::response([
            'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 4,
            'session' => ['id' => $match[1]],
            'state' => $snap->state, 'error' => $snap->error, 'turnId' => $snap->turnId, 'entries' => [],
        ]);
    });

    app(TaskScheduler::class)->tick();

    $implementerKey = $task->fresh()?->pi_restart_key;
    expect($implementerKey)->toBeString()
        ->and($task->fresh()?->pi_restart_resumes)->toBe(1)
        ->and($task->fresh()?->pi_restart_thread_id)->toBe($task->implementer_agent_thread_id)
        ->and($messages)->toHaveCount(1)
        ->and($messages[0]['session'])->toBe('implementer-thread')
        ->and($messages[0]['key'])->toBe($implementerKey);

    $task->parent->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update([
        'status' => TaskStatus::Reviewing,
        'review_notified_attempt' => $task->review_attempt,
        'review_notified_turn_id' => 'handoff-turn',
        ...tick_review_baseline(),
    ]);
    $review->state = 'failed';
    $review->error = 'The Pi server restarted during the turn.';
    $review->turnId = 'review-turn';
    app(TaskScheduler::class)->tick();

    $fresh = $task->fresh();
    $reviewerKey = $fresh?->pi_restart_key;
    expect($fresh?->assistance_requested)->toBeFalse()
        ->and($fresh?->pi_restart_resumes)->toBe(2)
        ->and($fresh?->pi_restart_reservation)->toBe('pending')
        ->and($fresh?->pi_restart_thread_id)->toBe($reviewer->id)
        ->and($fresh?->pi_restart_source_turn_id)->toBe('review-turn')
        ->and($reviewerKey)->toBeString()->not->toBe($implementerKey)
        ->and($messages)->toHaveCount(2)
        ->and($messages[1]['session'])->toBe('reviewer-thread')
        ->and($messages[1]['key'])->toBe($reviewerKey)
        ->and($messages[1]['text'])->toBe(TaskTurnFetchNotice::Failed."\n\n".TaskScheduler::PiServerRestartContinue)
        ->and(collect($messages)->where('session', 'reviewer-thread')->pluck('key')->all())->not->toContain($implementerKey);

    $review->turnId = (string) $reviewerKey;
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The reviewer thread failed.')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->pi_restart_key)->toBe($reviewerKey)
        ->and($messages)->toHaveCount(2);
});

it('asks for assistance when a Pi restart has no turn id', function (): void {
    $task = tick_pi_implementer();
    tick_pi_failure((object) ['turnId' => '', 'error' => 'The Pi server restarted during the turn.']);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and(tick_pi_message_keys())->toBe([])
        ->and($task->fresh()?->pi_restart_resumes)->toBe(0)
        ->and($task->fresh()?->pi_restart_key)->toBeNull();
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
    'Pi restart text on an old T3 row' => [TaskScheduler::PiServerRestartError],
    'retired T3 orphaned session error' => ['Provider session did not survive a server restart. Send a new message to continue.'],
    'retired T3 continuation error' => ['Could not continue this thread after the server restart. Send a new message to continue.'],
]);

it('keeps a Pi resume counted when the server accepts the key and both responses fail', function (): void {
    $task = tick_pi_implementer();
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.', 'state' => 'failed'];
    $accepted = [];
    $failedKey = null;
    Http::fake(function (Request $request) use ($state, &$accepted, &$failedKey) {
        if (str_ends_with($request->url(), '/messages')) {
            $key = (string) $request['key'];
            $accepted[$key] = true;
            $failedKey ??= $key;
            if ($key === $failedKey) {
                return Http::response(['error' => ['code' => 'unavailable', 'message' => 'down']], 503);
            }

            return Http::response(['duplicate' => false], 202);
        }
        if (! str_contains($request->url(), '/sessions/implementer-thread')) {
            return Http::response([], 404);
        }

        return Http::response([
            'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 4,
            'session' => ['id' => 'implementer-thread'],
            'state' => $state->state, 'error' => $state->error, 'turnId' => $state->turnId, 'entries' => [],
        ]);
    });

    app(TaskScheduler::class)->tick();

    $reserved = $task->fresh();
    expect($reserved?->pi_restart_resumes)->toBe(1)
        ->and($reserved?->pi_restart_reservation)->toBe('pending')
        ->and($reserved?->pi_restart_key)->toBeString()
        ->and($accepted)->toHaveKey($reserved?->pi_restart_key)
        ->and($reserved?->communication_failures)->toBe(1)
        ->and($reserved?->assistance_requested)->toBeFalse()
        ->and(tick_pi_message_keys())->toBe([$reserved?->pi_restart_key, $reserved?->pi_restart_key]);

    $state->turnId = (string) $reserved?->pi_restart_key;
    app()->forgetInstance(TaskScheduler::class);
    app(TaskScheduler::class)->tick();

    $fresh = $task->fresh();
    $keys = tick_pi_message_keys();
    expect($fresh?->pi_restart_resumes)->toBe(2)
        ->and($fresh?->pi_restart_reservation)->toBe('pending')
        ->and($fresh?->pi_restart_source_turn_id)->toBe($reserved?->pi_restart_key)
        ->and($fresh?->pi_restart_key)->toBeString()->not->toBe($reserved?->pi_restart_key)
        ->and($fresh?->assistance_requested)->toBeFalse()
        ->and($fresh?->communication_failures)->toBe(0)
        ->and(array_count_values($keys)[(string) $reserved?->pi_restart_key])->toBe(2)
        ->and($keys)->toBe([$reserved?->pi_restart_key, $reserved?->pi_restart_key, $fresh?->pi_restart_key]);

    $state->turnId = (string) $fresh?->pi_restart_key;
    app()->forgetInstance(TaskScheduler::class);
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('The implementer thread failed.')
        ->and($task->fresh()?->pi_restart_resumes)->toBe(2)
        ->and($task->fresh()?->pi_restart_key)->toBe($fresh?->pi_restart_key)
        ->and($task->fresh()?->pi_restart_reservation)->toBe('accepted')
        ->and(tick_pi_message_keys())->toBe($keys);
});

it('supersedes a pending Pi resume when a later turn is first observed', function (): void {
    $task = tick_pi_implementer();
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([null, null]));
    $state = (object) ['turnId' => 'turn-key-1', 'error' => 'The Pi server restarted during the turn.', 'state' => 'failed'];
    tick_pi_failure($state);
    app(TaskScheduler::class)->tick();
    $reserved = (string) $task->fresh()?->pi_restart_key;

    $state->state = 'idle';
    $state->turnId = 'normal-turn';
    $state->error = null;
    app(TaskScheduler::class)->tick();

    $fresh = $task->fresh();
    $keys = tick_pi_message_keys();
    expect($fresh?->pi_restart_reservation)->toBe('superseded')
        ->and($fresh?->pi_restart_resumes)->toBe(1)
        ->and($fresh?->pi_restart_key)->toBe($reserved)
        ->and($fresh?->pi_restart_source_turn_id)->toBe('turn-key-1')
        ->and($fresh?->assistance_requested)->toBeFalse()
        ->and($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($reserved)
        ->and($keys[1])->not->toBe($reserved)
        ->and(collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/messages'))->values()[1][0]['text'])
        ->toContain('No turn receipt was found.');

    $state->state = 'failed';
    $state->turnId = 'normal-turn';
    $state->error = 'The Pi server restarted during the turn.';
    app(TaskScheduler::class)->tick();

    $after = $task->fresh();
    expect($after?->pi_restart_resumes)->toBe(2)
        ->and($after?->pi_restart_reservation)->toBe('pending')
        ->and($after?->pi_restart_source_turn_id)->toBe('normal-turn')
        ->and($after?->pi_restart_key)->toBeString()->not->toBe($reserved)->not->toBe($keys[1])
        ->and($after?->assistance_requested)->toBeFalse()
        ->and(array_count_values(tick_pi_message_keys())[$reserved])->toBe(1);
});

it('reminds an implementer that ends a turn without a receipt once, then asks for assistance', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $notifier = new class implements CoderSettleNotifier
    {
        public ?string $reason = null;

        public function notify(Task $group): void {}

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(Task $group, string $reason): void
        {
            $this->reason = $reason;
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(CoderSettleNotifier::class, $notifier);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle');
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
        ->and($notifier->reason)->toBe('Checks still failed after the reminder. No turn receipt was found.')
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
    $notifier = new class implements CoderSettleNotifier
    {
        public bool $called = false;

        public function notify(Task $group): void
        {
            $this->called = true;
        }

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
        {
            $this->called = true;
        }

        public function assistance(Task $group, string $reason): void
        {
            $this->called = true;
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    app()->instance(CoderSettleNotifier::class, $notifier);

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Running)
        ->and($notifier->called)->toBeFalse();
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

it('targets the idle in-progress task while another task is working', function (): void {
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
        ->and(TaskCheck::query()->where('task_id', $idleTask->id)->count())->toBe(1)
        ->and(TaskCheck::query()->where('task_id', $workingTask->id)->count())->toBe(0)
        ->and($workingTask->fresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($idleTask->fresh()->status)->toBe(TaskStatus::Running);
});

it('reminds the implementer with the failing check output once, then asks for assistance', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $notifier = new class implements CoderSettleNotifier
    {
        public ?string $reason = null;

        public function notify(Task $group): void {}

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(Task $group, string $reason): void
        {
            $this->reason = $reason;
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(CoderSettleNotifier::class, $notifier);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "FAILED tests/Feature/ExportTest.php\n");
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([$failed, $failed]));
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
        FakeTaskTurnReceipts::contents('ready_for_review'), null, FakeTaskTurnReceipts::contents('ready_for_review', 'Fixed the export test.'), null,
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reminder = $dispatcher->commands[0]['message']['text'];
    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($reminder)->toContain("Orbit ran composer check, and it failed with exit code 1. The end of its output:\n\n```\nFAILED tests/Feature/ExportTest.php\n```")
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Failed);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($notifier->reason)->toStartWith('Checks still failed after the reminder. Orbit ran composer check, and it failed with exit code 1.')
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and(TaskCheck::query()->count())->toBe(2);
});

it('shows an unexpected check error as a failed check whose output ends with the error', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
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
            return ['thread' => ['session' => ['status' => 'idle']]];
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
            return tick_checked_thread('idle');
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
        ->and(app(TaskTurnReceipts::class)->prepared)->toBe(['implementer', 'implementer']);
});

it('repeated reminder send failures reach assistance', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
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
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([]));
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

        public function dispatch(Node $node, array $command): array
        {
            if ($this->fails) {
                throw new AgentDriverException('send failed');
            }

            return ['sequence' => 1, 'thread_id' => $command['threadId']];
        }
    };
    app()->instance(AgentCommandDispatcher::class, $dispatcher);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->communication_failures)->toBe(2);

    $dispatcher->fails = false;
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->communication_failures)->toBe(0);
    expect($group->fresh()->assistance_requested)->toBeFalse();
})->with([TaskStatus::Running, TaskStatus::Reviewing]);

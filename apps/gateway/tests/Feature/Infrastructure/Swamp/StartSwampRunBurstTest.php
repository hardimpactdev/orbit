<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../../Domain/Instances/ContainerInstanceHelpers.php';

/**
 * Serve as Node 209 runs it: at most `$limit` WebSocket upgrades from one address in a fixed window, a refused one answered
 * 429 with the rest of the window as Retry-After. Replies to vault.put and workflow.run like serve.
 */
final class BurstLimitedServe implements WebSocketClient
{
    /** @var list<array<string,mixed>> */
    public array $frames = [];

    public int $connects = 0;

    public int $refused = 0;

    /** Workflow starts whose `started` event must never arrive (the confirmation is lost after serve accepted). */
    public bool $loseConfirmation = false;

    /** @var list<array<string,mixed>>|null Serve's reply to a start it rejects, sent once with the start's id. */
    public ?array $rejection = null;

    private bool $rejected = false;

    private int $inWindow = 0;

    private ?int $windowEnds = null;

    private bool $open = false;

    public function __construct(public int $limit, public int $windowSeconds = 60) {}

    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
    {
        $now = now()->getTimestamp();
        if ($this->windowEnds === null || $now > $this->windowEnds) {
            [$this->windowEnds, $this->inWindow] = [$now + $this->windowSeconds, 0];
        }
        $this->connects++;
        if (++$this->inWindow > $this->limit) {
            $this->refused++;
            throw new WebSocketException('refused', 429, retryAfterSeconds: max(1, $this->windowEnds - $now));
        }
        $this->open = true;
    }

    public function send(array $message): void
    {
        if (! $this->open) {
            throw new WebSocketException('not connected');
        }
        $this->frames[] = $message;
    }

    public function receive(float $timeoutSeconds): array
    {
        $frame = end($this->frames);
        if ($frame === false) {
            return [];
        }
        if ($frame['type'] === 'vault.put') {
            return [['type' => 'vault.put', 'id' => $frame['id'], 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption',
            ]]]];
        }
        if ($this->rejection !== null) {
            $reply = $this->rejected ? [] : array_map(static fn (array $message): array => ['id' => $frame['id']] + $message, $this->rejection);
            $this->rejected = true;

            return $reply;
        }
        if ($this->loseConfirmation) {
            $this->open = false;

            return [];
        }

        return [['type' => 'event', 'id' => $frame['id'], 'event' => ['kind' => 'started',
            'workflowName' => $frame['payload']['workflowIdOrName'], 'runId' => (string) Str::uuid()]]];
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isConnected(): bool
    {
        return $this->open;
    }

    /** @return list<array<string,mixed>> */
    public function starts(int $operationId): array
    {
        return array_values(array_filter($this->frames, static fn (array $frame): bool => $frame['type'] === 'workflow.run'
            && ($frame['payload']['runtimeTags']['orbit_start_key'] ?? null) === 'operation:'.$operationId));
    }
}

/** @return list<Operation> Admitted creates, each in its own Project, whose starts are all queued now. */
function burstCreates(int $count): array
{
    $node = containerDefaultNode();
    $operations = [];
    for ($index = 1; $index <= $count; $index++) {
        Http::fake(['api.github.com/repos/nckrtl/tool-'.$index => Http::response(['full_name' => 'nckrtl/tool-'.$index, 'private' => false])]);
        $project = Project::query()->create(['name' => 'tool-'.$index, 'slug' => 'tool-'.$index, 'type' => ProjectType::NodePackage,
            'repository_url' => 'https://github.com/nckrtl/tool-'.$index.'.git', 'default_branch' => 'main', 'root' => '.',
            'is_laravel' => false, 'source_facts_captured_at' => now()]);
        $operations[] = containerCreate($project, $node);
    }

    return $operations;
}

/** Runs every pushed start job that is due now, oldest first, and forgets it; returns how many ran. */
function runDueStarts(array &$ran): int
{
    $count = 0;
    foreach (Queue::pushedJobs()[StartAdmittedSwampRun::class] ?? [] as $index => $pushed) {
        if (isset($ran[$index])) {
            continue;
        }
        $delay = $pushed['job']->delay;
        if ($delay instanceof DateTimeInterface && $delay->getTimestamp() > now()->getTimestamp()) {
            continue;
        }
        $ran[$index] = true;
        $count++;
        try {
            app()->call([$pushed['job'], 'handle']);
        } catch (ResourceOperationException) {
            // A start that fails for good throws after recording its failure, as the queue worker sees it.
        }
    }

    return $count;
}

beforeEach(function (): void {
    Queue::fake();
    SwampNodeFixture::ensure();
    config(['swamp.run_token' => 'test-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
});

it('starts every Operation released at once although serve refuses part of the burst', function (): void {
    $operations = burstCreates(4);
    // Each create pushes its execution copies over one connection and then connects for its start: 8 connections. A limit of 7
    // refuses the last start of the burst, as 50 refused Nick's four destroys after a removal's cleanup on 13 November.
    $serve = new BurstLimitedServe(limit: 7);
    app()->instance(WebSocketClient::class, $serve);
    $ran = [];

    runDueStarts($ran);
    expect($serve->refused)->toBeGreaterThan(0);
    foreach ($operations as $operation) {
        expect($operation->refresh()->status)->toBeIn([OperationStatus::Running, OperationStatus::Queued]);
    }
    for ($round = 0; $round < StartSwampRun::MaxDeferrals && Operation::query()->where('status', OperationStatus::Queued)->exists(); $round++) {
        $this->travel(StartSwampRun::MaxDeferralSeconds)->seconds();
        runDueStarts($ran);
    }

    foreach ($operations as $operation) {
        expect($operation->refresh()->status)->toBe(OperationStatus::Running)
            ->and($operation->state['swamp_run_id'])->toBeString()
            ->and($serve->starts($operation->id))->toHaveCount(1);
    }
});

it('queues a refused start again after Retry-After and sends it once when serve admits it', function (): void {
    [$operation] = burstCreates(1);
    // The first connection carries an execution copy; the next one, before any start frame, is refused.
    $serve = new BurstLimitedServe(limit: 1, windowSeconds: 20);
    app()->instance(WebSocketClient::class, $serve);
    $ran = [];

    runDueStarts($ran);
    expect($operation->refresh()->status)->toBe(OperationStatus::Queued)
        ->and($operation->started_at)->not->toBeNull()
        ->and($operation->error_code)->toBeNull()
        ->and($operation->state['start_deferrals'])->toBe(1)
        ->and($serve->starts($operation->id))->toBe([]);
    $pushed = Queue::pushedJobs()[StartAdmittedSwampRun::class];
    $delay = end($pushed)['job']->delay;
    expect($delay->getTimestamp() - now()->getTimestamp())->toBe(21);

    $serve->limit = 100;
    $this->travel(21)->seconds();
    runDueStarts($ran);
    // A late duplicate of the job finds the start begun and sends nothing.
    app()->call([new StartAdmittedSwampRun($operation->id), 'handle']);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->state['swamp_run_id'])->toBeString()
        ->and($serve->starts($operation->id))->toHaveCount(1);
});

it('fails a start for good after its last deferral, never having sent it', function (): void {
    [$operation] = burstCreates(1);
    $serve = new BurstLimitedServe(limit: 1, windowSeconds: 100_000);
    app()->instance(WebSocketClient::class, $serve);
    $ran = [];

    runDueStarts($ran);
    for ($round = 0; $round < StartSwampRun::MaxDeferrals; $round++) {
        $this->travel(StartSwampRun::MaxDeferralSeconds)->seconds();
        expect(runDueStarts($ran))->toBe(1);
    }

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        // The refused connection is an execution copy's or the start's own, so the failure names that step.
        ->and($operation->error_code)->toBeIn(['swamp.vault_sync_unavailable', 'swamp.start_unavailable'])
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->state['start_deferrals'])->toBe(StartSwampRun::MaxDeferrals)
        ->and($serve->starts($operation->id))->toBe([])
        ->and(count(Queue::pushedJobs()[StartAdmittedSwampRun::class]))->toBe(1 + StartSwampRun::MaxDeferrals);
});

it('never queues a start again once serve may have accepted it', function (): void {
    [$operation] = burstCreates(1);
    $serve = new BurstLimitedServe(limit: 100);
    $serve->loseConfirmation = true;
    app()->instance(WebSocketClient::class, $serve);
    $ran = [];

    runDueStarts($ran);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->error_code)->toBe('swamp.start_uncertain')
        ->and($operation->state['start_deferrals'] ?? null)->toBeNull()
        ->and($serve->starts($operation->id))->toHaveCount(1)
        ->and(Queue::pushedJobs()[StartAdmittedSwampRun::class])->toHaveCount(1);
});

// Serve's replies as recorded on 14 November 2026 (task 5117) to the Gateway's workflow.run frame; ids are the start's own.
dataset('serve start rejections', [
    // The live inner serve on 9418, to operation-task-sync with a copy intent, as Operation 22250's start met it.
    'input validation' => [[
        ['type' => 'event', 'event' => ['kind' => 'validating_inputs', 'seq' => 1]],
        ['type' => 'event', 'event' => ['kind' => 'error', 'error' => ['code' => 'input_validation_failed',
            'message' => "Input validation failed:\n  intent.checkout_path is required",
            'details' => [['path' => 'intent.checkout_path', 'message' => 'intent.checkout_path is required']]], 'seq' => 2]],
        ['type' => 'done'],
    ], 'input_validation_failed'],
    // A scratch serve of the same build, to a workflow whose step input reads a missing inputs.intent.x.
    'input resolution' => [[
        ['type' => 'event', 'event' => ['kind' => 'validating_inputs', 'seq' => 1]],
        ['type' => 'event', 'event' => ['kind' => 'evaluating_workflow', 'seq' => 2]],
        ['type' => 'event', 'event' => ['kind' => 'error', 'error' => ['code' => 'workflow_execution_failed',
            'message' => "Workflow execution failed: Invalid expression: No such key: x\n\n>    1 | inputs.intent.x\n                       ^"], 'seq' => 3]],
        ['type' => 'done'],
    ], 'workflow_execution_failed'],
]);

it('fails a start that serve rejects at run start in seconds and frees its target', function (array $reply, string $code): void {
    [$operation] = burstCreates(1);
    $serve = new BurstLimitedServe(limit: 100);
    $serve->rejection = $reply;
    app()->instance(WebSocketClient::class, $serve);
    // Each clock read stands for a 0.2 s receive wait, so a start left to wait out its confirmation reads it past 120 s.
    $now = 0.0;
    app()->instance(StartSwampRun::class, new StartSwampRun($serve, static function () use (&$now): float {
        return $now += 0.2;
    }));
    Log::spy();
    $ran = [];

    runDueStarts($ran);

    expect($now)->toBeLessThan(5.0)
        ->and($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('swamp.start_refused')
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->state['swamp_run_id'] ?? null)->toBeNull()
        ->and($serve->starts($operation->id))->toHaveCount(1)
        ->and(Queue::pushedJobs()[StartAdmittedSwampRun::class])->toHaveCount(1);
    Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context = []): bool => $message === 'Swamp direct start refused'
        && $context['remote_type'] === 'event:error' && $context['remote_code'] === $code && ! str_contains($context['remote_message'], "\n"))->once();
})->with('serve start rejections');

/** Runs one admitted create's start against serve's `$reply`, on a clock read once per 0.2 s receive wait; returns the clock. */
function startAgainst(Operation $operation, array $reply): float
{
    $serve = new BurstLimitedServe(limit: 100);
    $serve->rejection = $reply;
    app()->instance(WebSocketClient::class, $serve);
    $now = 0.0;
    app()->instance(StartSwampRun::class, new StartSwampRun($serve, static function () use (&$now): float {
        return $now += 0.2;
    }));
    $ran = [];
    runDueStarts($ran);

    return $now;
}

it('binds a run whose started event came before a root error event, and refuses nothing', function (): void {
    [$operation] = burstCreates(1);
    $run = (string) Str::uuid();

    startAgainst($operation, [
        ['type' => 'event', 'event' => ['kind' => 'validating_inputs', 'seq' => 1]],
        ['type' => 'event', 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-create', 'runId' => $run, 'seq' => 2]],
        ['type' => 'event', 'event' => ['kind' => 'error', 'error' => ['code' => 'workflow_execution_failed', 'message' => 'Workflow execution failed'], 'seq' => 3]],
        ['type' => 'done'],
    ]);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->state['swamp_run_id'])->toBe($run)
        ->and($operation->error_code)->toBeNull()
        ->and($operation->lock_key)->not->toBeNull();
});

it('keeps a start uncertain when an event that is not a pre-run one came before the root error event', function (array $earlier): void {
    [$operation] = burstCreates(1);

    $clock = startAgainst($operation, [
        ['type' => 'event', 'event' => ['kind' => 'validating_inputs', 'seq' => 1]],
        ['type' => 'event', 'event' => $earlier + ['seq' => 2]],
        ['type' => 'event', 'event' => ['kind' => 'error', 'error' => ['code' => 'workflow_execution_failed', 'message' => 'Workflow execution failed'], 'seq' => 3]],
        ['type' => 'done'],
    ]);

    // A run may exist behind a start the Gateway could not read, so the start waits out its confirmation and holds its target.
    expect($clock)->toBeGreaterThan(120.0)
        ->and($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->error_code)->toBe('swamp.start_uncertain')
        ->and($operation->lock_key)->not->toBeNull()
        ->and($operation->state['swamp_run_id'] ?? null)->toBeNull();
})->with([
    'an unknown kind' => [['kind' => 'run_saved']],
    'a started event for another workflow' => [['kind' => 'started', 'workflowName' => 'operation-other', 'runId' => '0f0e0d0c-0b0a-4908-8706-050403020100']],
]);

it('does not fail a start that a duplicate job began while its deferral could not be queued', function (): void {
    [$operation] = burstCreates(1);
    $operation->forceFill(['status' => OperationStatus::Running, 'started_at' => now()])->save();

    app(StartSwampRun::class)->refuse($operation, new ResourceOperationException('swamp.start_enqueue_failed', 'The native workflow start could not be queued.', 503), onlyQueued: true);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)->and($operation->error_code)->toBeNull();
});

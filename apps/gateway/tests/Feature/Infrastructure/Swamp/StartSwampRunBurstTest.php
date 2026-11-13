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

it('does not fail a start that a duplicate job began while its deferral could not be queued', function (): void {
    [$operation] = burstCreates(1);
    $operation->forceFill(['status' => OperationStatus::Running, 'started_at' => now()])->save();

    app(StartSwampRun::class)->refuse($operation, new ResourceOperationException('swamp.start_enqueue_failed', 'The native workflow start could not be queued.', 503), onlyQueued: true);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)->and($operation->error_code)->toBeNull();
});

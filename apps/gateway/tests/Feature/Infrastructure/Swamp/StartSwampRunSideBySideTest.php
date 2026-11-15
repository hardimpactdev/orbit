<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Node;
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
 * Serve for starts that run side by side. Each worker gets its own connection. A start listed in `$held` gets no reply on its
 * first read: its closure runs there instead, as another worker's job runs while this one waits for its confirmation.
 */
final class SideBySideServe implements WebSocketClient
{
    /** @var array<int, Closure(): void> */
    public static array $held = [];

    /** @var list<int> Starts whose confirmation never comes. */
    public static array $lost = [];

    /** @var list<array<string,mixed>> Every start frame, in the order serve received them. */
    public static array $starts = [];

    /** @var list<string> What serve saw, in order: `sent:<id>`, `confirmed:<id>`. */
    public static array $log = [];

    /** @var list<array<string,mixed>> */
    private array $frames = [];

    private bool $open = false;

    public static function reset(): void
    {
        self::$held = [];
        self::$lost = [];
        self::$starts = [];
        self::$log = [];
    }

    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
    {
        $this->open = true;
    }

    public function send(array $message): void
    {
        if (! $this->open) {
            throw new WebSocketException('not connected');
        }
        $this->frames[] = $message;
        if ($message['type'] === 'workflow.run') {
            self::$starts[] = $message;
            self::$log[] = 'sent:'.self::operationOf($message);
        }
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
        $operation = self::operationOf($frame);
        if (isset(self::$held[$operation])) {
            $other = self::$held[$operation];
            unset(self::$held[$operation]);
            $other();

            return [];
        }
        if (in_array($operation, self::$lost, true)) {
            $this->open = false;

            return [];
        }
        self::$log[] = 'confirmed:'.$operation;

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

    /** @param array<string,mixed> $frame */
    private static function operationOf(array $frame): int
    {
        return (int) Str::after((string) ($frame['payload']['runtimeTags']['orbit_start_key'] ?? ''), 'operation:');
    }

    public static function startsOf(int $operationId): int
    {
        return count(array_filter(self::$starts, static fn (array $frame): bool => self::operationOf($frame) === $operationId));
    }
}

/** A create in its own Project, whose start is queued now. */
function sideBySideCreate(string $slug): Operation
{
    Http::fake(['api.github.com/repos/nckrtl/'.$slug => Http::response(['full_name' => 'nckrtl/'.$slug, 'private' => false])]);
    $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => ProjectType::NodePackage,
        'repository_url' => 'https://github.com/nckrtl/'.$slug.'.git', 'default_branch' => 'main', 'root' => '.',
        'is_laravel' => false, 'source_facts_captured_at' => now()]);

    return containerCreate($project, sideBySideNode());
}

/** The one container Node of these tests. */
function sideBySideNode(): Node
{
    return Node::query()->where('name', 'beast')->first() ?? containerDefaultNode();
}

/**
 * Runs one start job as a worker does: a failed start throws after it recorded its failure. A job that runs inside another one's
 * wait stands for another Horizon process, so it gets its own dispatch and kept connection, and the waiting job keeps its own.
 */
function workStart(int $operationId): void
{
    $process = [SwampDispatch::class => app(SwampDispatch::class), SwampConnection::class => app(SwampConnection::class)];
    app()->instance(SwampDispatch::class, new SwampDispatch);
    app()->instance(SwampConnection::class, new SwampConnection);
    try {
        app()->call([new StartAdmittedSwampRun($operationId), 'handle']);
    } catch (ResourceOperationException) {
        // Recorded on the Operation.
    } finally {
        foreach ($process as $abstract => $instance) {
            app()->instance($abstract, $instance);
        }
    }
}

beforeEach(function (): void {
    Queue::fake();
    SwampNodeFixture::ensure();
    SideBySideServe::reset();
    config(['swamp.run_token' => 'test-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    // Every worker opens its own connection, as each Horizon process does.
    app()->bind(WebSocketClient::class, static fn (): WebSocketClient => new SideBySideServe);
});

it('runs native starts on their own queue with four workers and keeps one worker for the other Operation jobs', function (): void {
    $job = new StartAdmittedSwampRun(1);
    $supervisors = config('horizon.defaults');

    expect($job->queue)->toBe('operation-starts')
        ->and($job->connection)->toBe(GatewayQueue::Connection)
        ->and($supervisors['operation-starts']['queue'])->toBe(['operation-starts'])
        ->and($supervisors['operation-starts']['balance'])->toBeFalse()
        ->and($supervisors['operation-starts']['minProcesses'])->toBe(4)
        ->and($supervisors['operation-starts']['maxProcesses'])->toBe(4)
        ->and($supervisors['operation-starts']['tries'])->toBe(1)
        // The job's own timeout: a start waits at most 15 s to connect and 120 s for its confirmation.
        ->and($supervisors['operation-starts']['timeout'])->toBe($job->timeout)
        ->and($supervisors['operations']['queue'])->toBe(['operations'])
        ->and($supervisors['operations']['maxProcesses'])->toBe(1)
        ->and(config('horizon.environments.*'))->toHaveKey('operation-starts')
        // The start workers do not lengthen the release drain.
        ->and(GatewayHorizon::drainSeconds())->toBe(1730);
});

it('queues each admitted start on the start queue', function (): void {
    $operation = sideBySideCreate('tool-queued');

    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $operation->id
        && $job->connection === GatewayQueue::Connection && $job->queue === GatewayQueue::OperationStarts->value);
});

it('starts and binds another target while one start still waits for its confirmation', function (): void {
    $waiting = sideBySideCreate('tool-waiting');
    $other = sideBySideCreate('tool-other');
    SideBySideServe::$held[$waiting->id] = static function () use ($waiting, $other): void {
        workStart($other->id);
        expect($waiting->refresh()->status)->toBe(OperationStatus::Running)
            ->and($waiting->state['swamp_run_id'] ?? null)->toBeNull()
            ->and($other->refresh()->status)->toBe(OperationStatus::Running)
            ->and($other->state['swamp_run_id'])->toBeString();
    };

    workStart($waiting->id);

    expect(SideBySideServe::$log)->toBe(['sent:'.$waiting->id, 'sent:'.$other->id, 'confirmed:'.$other->id, 'confirmed:'.$waiting->id])
        ->and($waiting->refresh()->state['swamp_run_id'])->toBeString()
        ->and(SideBySideServe::startsOf($waiting->id))->toBe(1)
        ->and(SideBySideServe::startsOf($other->id))->toBe(1);
});

it('sends a start once when a second job for the same Operation runs while the first waits', function (): void {
    $operation = sideBySideCreate('tool-twice');
    SideBySideServe::$held[$operation->id] = static fn () => workStart($operation->id);

    workStart($operation->id);

    expect(SideBySideServe::startsOf($operation->id))->toBe(1)
        ->and($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->state['swamp_run_id'])->toBeString();
});

it('refuses a second Operation for the same target while its start is in flight', function (): void {
    $operation = sideBySideCreate('tool-same');
    $project = Project::query()->where('slug', 'tool-same')->sole();
    $refusal = null;
    SideBySideServe::$held[$operation->id] = static function () use ($project, &$refusal): void {
        $refusal = containerRefusal(static fn () => containerCreate($project, sideBySideNode()));
    };

    workStart($operation->id);

    expect($refusal)->toBe('operation.target_busy')
        ->and(Operation::query()->where('kind', $operation->kind)->count())->toBe(1)
        ->and(SideBySideServe::startsOf($operation->id))->toBe(1);
});

it('keeps an uncertain start on its own target only and starts other targets beside it', function (): void {
    $uncertain = sideBySideCreate('tool-uncertain');
    $other = sideBySideCreate('tool-beside');
    SideBySideServe::$lost[] = $uncertain->id;
    SideBySideServe::$held[$uncertain->id] = static fn () => workStart($other->id);

    workStart($uncertain->id);

    expect($uncertain->refresh()->status)->toBe(OperationStatus::Running)
        ->and($uncertain->error_code)->toBe('swamp.start_uncertain')
        ->and($uncertain->lock_key)->not->toBeNull()
        ->and($other->refresh()->status)->toBe(OperationStatus::Running)
        ->and($other->state['swamp_run_id'])->toBeString()
        ->and(SideBySideServe::startsOf($uncertain->id))->toBe(1)
        // The uncertain start is never queued again.
        ->and(collect(Queue::pushedJobs()[StartAdmittedSwampRun::class])->filter(fn (array $pushed): bool => $pushed['job']->operationId === $uncertain->id))->toHaveCount(1);
    $project = Project::query()->where('slug', 'tool-uncertain')->sole();
    expect(containerRefusal(static fn () => containerCreate($project, sideBySideNode())))->toBe('operation.target_busy');
});

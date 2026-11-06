<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampStartBatch;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

const BATCH_RUN_A = '11111111-1111-4111-8111-111111111111';
const BATCH_RUN_B = '22222222-2222-4222-8222-222222222222';
const BATCH_RUN_C = '33333333-3333-4333-8333-333333333333';

/**
 * A Swamp that answers each `workflow.run` after a delay. The clock moves by the wait of each receive.
 *
 * @param  array<string, array{after:float, reply:array<string,mixed>|null}>  $script  by the operation id in the inputs
 */
function batchSwamp(array $script, bool $failSendForB = false): object
{
    return new class($script, $failSendForB) implements WebSocketClient
    {
        public float $time = 1000.0;

        /** @var list<string> */
        public array $log = [];

        public bool $open = true;

        /** @var array<string, array{at:float, message:array<string,mixed>}> */
        private array $due = [];

        /** @param array<string, array{after:float, reply:array<string,mixed>|null}> $script */
        public function __construct(private array $script, private bool $failSendForB) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $operation = (string) ($message['payload']['inputs']['operation_id'] ?? '');
            if ($this->failSendForB && $operation === 'b') {
                throw new RuntimeException('The write failed.');
            }
            $this->log[] = 'send:'.$operation;
            $entry = $this->script[$operation] ?? null;
            if ($entry !== null && $entry['reply'] !== null) {
                $this->due[$message['id']] = ['at' => $this->time + $entry['after'], 'message' => ['id' => $message['id'], ...$entry['reply']]];
            }
        }

        public function receive(float $timeoutSeconds): array
        {
            $this->log[] = 'receive';
            $this->time += $timeoutSeconds;
            $ready = [];
            foreach ($this->due as $id => $item) {
                if ($item['at'] <= $this->time) {
                    $ready[] = $item['message'];
                    unset($this->due[$id]);
                }
            }

            return $ready;
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
}

/** @return array<string,mixed> */
function batchStarted(string $runId, string $workflow = 'operation-proof-run'): array
{
    return ['type' => 'event', 'event' => ['workflowName' => $workflow, 'runId' => $runId, 'kind' => 'started']];
}

/** @return array<string,mixed> */
function batchPayload(string $operation): array
{
    return ['workflowIdOrName' => 'operation-proof-run', 'inputs' => ['operation_id' => $operation], 'runtimeTags' => ['orbit_start_key' => 'operation:'.$operation]];
}

/**
 * @param  array<string, array<string,mixed>>  $payloads
 * @return array<string, mixed>
 */
function batchRun(object $socket, array $payloads, ?WebSocketClient $client = null): array
{
    $endpoint = new WebSocketEndpoint('swamp.test', 443, 'swamp.orbit', '/', '/tmp/ca.pem', 'run-token');

    return (new SwampStartBatch(static fn (): float => $socket->time))->run($client ?? $socket, $endpoint, $payloads);
}

it('sends every request before it reads a reply, and keys each confirmed run to its request', function (): void {
    $socket = batchSwamp(['a' => ['after' => 30.0, 'reply' => batchStarted(BATCH_RUN_A)], 'b' => ['after' => 10.0, 'reply' => batchStarted(BATCH_RUN_B)],
        'c' => ['after' => 50.0, 'reply' => batchStarted(BATCH_RUN_C)]]);

    $results = batchRun($socket, ['a' => batchPayload('a'), 'b' => batchPayload('b'), 'c' => batchPayload('c')]);

    expect(array_slice($socket->log, 0, 3))->toBe(['send:a', 'send:b', 'send:c'])->and($socket->log[3])->toBe('receive')
        ->and($results['a'])->toBe(['run_id' => BATCH_RUN_A, 'failed' => false])
        ->and($results['b'])->toBe(['run_id' => BATCH_RUN_B, 'failed' => false])
        ->and($results['c'])->toBe(['run_id' => BATCH_RUN_C, 'failed' => false]);
    // The wait is the slowest reply (50 s) and not the sum (90 s).
    expect($socket->time - 1000.0)->toBeLessThan(60.0);
});

it('ends only the run that Swamp refuses', function (): void {
    $socket = batchSwamp(['a' => ['after' => 1.0, 'reply' => ['type' => 'error', 'error' => ['code' => 'invalid', 'message' => 'no']]],
        'b' => ['after' => 2.0, 'reply' => batchStarted(BATCH_RUN_B)]]);

    $results = batchRun($socket, ['a' => batchPayload('a'), 'b' => batchPayload('b')]);

    expect($results['a'])->toBeInstanceOf(ResourceOperationException::class)->and($results['a']->errorCode)->toBe('swamp.start_refused')
        ->and($results['b'])->toBe(['run_id' => BATCH_RUN_B, 'failed' => false]);
});

it('reports a run that Swamp ended before its start completed as failed, with its run id', function (): void {
    $socket = batchSwamp(['a' => ['after' => 1.0, 'reply' => ['type' => 'event', 'event' => ['workflowName' => 'operation-proof-run', 'runId' => BATCH_RUN_A, 'kind' => 'failed']]]]);

    expect(batchRun($socket, ['a' => batchPayload('a')]))->toBe(['a' => ['run_id' => BATCH_RUN_A, 'failed' => true]]);
});

it('gives each run its own 120 s confirmation: an unanswered run is uncertain and the answered one is not', function (): void {
    $socket = batchSwamp(['a' => ['after' => 5.0, 'reply' => batchStarted(BATCH_RUN_A)], 'b' => ['after' => 0.0, 'reply' => null]]);

    $results = batchRun($socket, ['a' => batchPayload('a'), 'b' => batchPayload('b')]);

    expect($results['a'])->toBe(['run_id' => BATCH_RUN_A, 'failed' => false])
        ->and($results['b'])->toBeInstanceOf(ResourceOperationException::class)->and($results['b']->errorCode)->toBe('swamp.start_uncertain')
        ->and($socket->time - 1000.0)->toBeGreaterThanOrEqual(120.0)->and($socket->time - 1000.0)->toBeLessThan(121.0);
});

it('makes the unanswered runs uncertain when the connection drops, and keeps the ones it answered', function (): void {
    $socket = batchSwamp(['a' => ['after' => 0.0, 'reply' => batchStarted(BATCH_RUN_A)], 'b' => ['after' => 100.0, 'reply' => batchStarted(BATCH_RUN_B)]]);
    $client = new class($socket) implements WebSocketClient
    {
        public function __construct(private object $inner) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->inner->send($message);
        }

        public function receive(float $timeoutSeconds): array
        {
            $messages = $this->inner->receive($timeoutSeconds);
            $this->inner->open = false;

            return $messages;
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return $this->inner->open;
        }
    };

    $results = batchRun($socket, ['a' => batchPayload('a'), 'b' => batchPayload('b')], $client);

    expect($results['a'])->toBe(['run_id' => BATCH_RUN_A, 'failed' => false])->and($results['b'])->toBeInstanceOf(ResourceOperationException::class)
        ->and($results['b']->errorCode)->toBe('swamp.start_uncertain');
});

it('makes a run whose request cannot be written uncertain, and still sends and confirms the others', function (): void {
    $socket = batchSwamp(['a' => ['after' => 1.0, 'reply' => batchStarted(BATCH_RUN_A)], 'c' => ['after' => 1.0, 'reply' => batchStarted(BATCH_RUN_C)]], failSendForB: true);

    $results = batchRun($socket, ['a' => batchPayload('a'), 'b' => batchPayload('b'), 'c' => batchPayload('c')]);

    expect($results['b'])->toBeInstanceOf(ResourceOperationException::class)->and($results['b']->errorCode)->toBe('swamp.start_uncertain')
        ->and($results['a'])->toBe(['run_id' => BATCH_RUN_A, 'failed' => false])->and($results['c'])->toBe(['run_id' => BATCH_RUN_C, 'failed' => false]);
});

it('does not take the event of a child run for the confirmation of its request', function (): void {
    $socket = batchSwamp(['a' => ['after' => 1.0, 'reply' => ['type' => 'event', 'event' => ['workflowName' => 'operation-proof-run', 'runId' => BATCH_RUN_B, 'kind' => 'started', 'parentRunId' => BATCH_RUN_C]]]]);

    $results = batchRun($socket, ['a' => batchPayload('a')]);

    // The child-run event does not confirm the request, so the 120 s window runs out.
    expect($results['a'])->toBeInstanceOf(ResourceOperationException::class)->and($results['a']->errorCode)->toBe('swamp.start_uncertain');
});

/** A queued run of a direct kind that takes no workflow input. */
function batchOperation(): Operation
{
    return Operation::query()->create(['kind' => 'project.pr_merge', 'target_type' => 'project', 'target_id' => 1, 'node_id' => null,
        'status' => OperationStatus::Queued, 'input' => ['execution_secrets' => []], 'state' => [], 'sequence' => 1, 'attempts' => 0]);
}

it('starts several admitted runs on one connection and binds each to its Swamp run', function (): void {
    [$a, $b] = [batchOperation(), batchOperation()];
    $socket = batchSwamp([(string) $a->id => ['after' => 3.0, 'reply' => batchStarted(BATCH_RUN_A, 'operation-task-pr-merge')],
        (string) $b->id => ['after' => 1.0, 'reply' => batchStarted(BATCH_RUN_B, 'operation-task-pr-merge')]]);
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    app(StartSwampRun::class)->startMany([$a, $b]);

    expect(array_slice($socket->log, 0, 2))->toBe(['send:'.$a->id, 'send:'.$b->id])
        ->and($a->refresh()->state['swamp_run_id'])->toBe(BATCH_RUN_A)->and($b->refresh()->state['swamp_run_id'])->toBe(BATCH_RUN_B)
        ->and($a->status)->toBe(OperationStatus::Running)->and($b->status)->toBe(OperationStatus::Running);
});

it('does not strand the later runs when the first is refused: it ends alone and the error is thrown after all are handled', function (): void {
    [$a, $b] = [batchOperation(), batchOperation()];
    $socket = batchSwamp([(string) $a->id => ['after' => 1.0, 'reply' => ['type' => 'error', 'error' => ['code' => 'invalid', 'message' => 'no']]],
        (string) $b->id => ['after' => 2.0, 'reply' => batchStarted(BATCH_RUN_B, 'operation-task-pr-merge')]]);
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    expect(fn () => app(StartSwampRun::class)->startMany([$a, $b]))->toThrow(ResourceOperationException::class, 'refused');

    expect($a->refresh()->status)->toBe(OperationStatus::Failed)->and($a->error_code)->toBe('swamp.start_refused')
        ->and($b->refresh()->state['swamp_run_id'])->toBe(BATCH_RUN_B)->and($b->status)->toBe(OperationStatus::Running);
});

it('keeps the path of one run when a single run is started', function (): void {
    $a = batchOperation();
    $socket = batchSwamp([(string) $a->id => ['after' => 1.0, 'reply' => batchStarted(BATCH_RUN_A, 'operation-task-pr-merge')]]);
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    app(StartSwampRun::class)->startMany([$a]);

    expect($a->refresh()->state['swamp_run_id'])->toBe(BATCH_RUN_A);
});

it('makes every unanswered run uncertain when a read throws, as a lost reply does', function (): void {
    $socket = batchSwamp(['a' => ['after' => 1.0, 'reply' => batchStarted(BATCH_RUN_A)], 'b' => ['after' => 1.0, 'reply' => batchStarted(BATCH_RUN_B)]]);
    $client = new class($socket) implements WebSocketClient
    {
        public function __construct(private object $inner) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->inner->send($message);
        }

        public function receive(float $timeoutSeconds): array
        {
            throw new RuntimeException('The read failed.');
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };

    $results = batchRun($socket, ['a' => batchPayload('a'), 'b' => batchPayload('b')], $client);

    expect($results['a'])->toBeInstanceOf(ResourceOperationException::class)->and($results['a']->errorCode)->toBe('swamp.start_uncertain')
        ->and($results['b'])->toBeInstanceOf(ResourceOperationException::class)->and($results['b']->errorCode)->toBe('swamp.start_uncertain');
});

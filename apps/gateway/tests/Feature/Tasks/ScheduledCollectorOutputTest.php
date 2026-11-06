<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;

const COLLECTOR_WORKFLOW = '4a2d9dd5-9f83-41d4-b8df-8e46367ed230';

/** A read connection that answers each request with the reply the test names for its type. */
function collectorSocket(array $replies): WebSocketClient
{
    $socket = new class($replies) implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        private bool $open = false;

        /** @param array<string,array<string,mixed>> $replies */
        public function __construct(private readonly array $replies) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);

            return [['id' => $frame['id'], ...$this->replies[$frame['type']]]];
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
    app()->instance(WebSocketClient::class, $socket);
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'read-token']);

    return $socket;
}

/** @return array<string,mixed> */
function collectorRun(?string $stdout): array
{
    return ['status' => 'succeeded', 'jobs' => [['name' => 'checks', 'steps' => [[
        'name' => 'collect', 'status' => 'succeeded',
        'dataArtifacts' => [['name' => 'output', 'version' => 22], ['name' => 'report-swamp-method-summary', 'version' => 22]],
        ...($stdout === null ? [] : ['outputs' => ['stdout' => $stdout]]),
    ]]]]];
}

function terminalRows(array $run): array
{
    $step = app(SwampCommandStep::class);
    $selector = app(DirectOperation::class)->scheduledResultDefinitions()[COLLECTOR_WORKFLOW]['terminal_result'];
    $method = new ReflectionMethod($step, 'terminalOutput');

    return $method->invoke($step, $run, $selector);
}

afterEach(fn () => app(SwampConnection::class)->release());

it('names the behavior that holds the collector output in the check declaration', function (): void {
    expect(app(DirectOperation::class)->scheduledResultDefinitions()[COLLECTOR_WORKFLOW]['terminal_result'])
        ->toBe(['job' => 'checks', 'step' => 'collect', 'data' => 'task-check-ci-data']);
});

it('reads the rows from the data artifact when the run history did not inline the step output', function (): void {
    $rows = '{"checks":[{"operation_id":11153,"node_id":625,"kind":"tasks.check","root_run_id":"986bc349-54dc-4dea-9f22-76e3353d1c81","status":"succeeded","result":{"outcome":"passed"}}]}';
    $socket = collectorSocket(['data.get' => ['type' => 'data.get', 'payload' => ['data' => ['version' => 22, 'content' => json_encode(['stdout' => $rows], JSON_THROW_ON_ERROR)]]]]);

    $result = terminalRows(collectorRun(null));

    expect($result['checks'][0]['operation_id'])->toBe(11153)
        ->and($socket->frames[0]['type'])->toBe('data.get')
        ->and($socket->frames[0]['payload'])->toBe(['modelIdOrName' => 'task-check-ci-data', 'dataName' => 'output', 'version' => 22]);
});

it('reads no artifact when the run history inlined the output', function (): void {
    $socket = collectorSocket([]);

    expect(terminalRows(collectorRun('{"checks":[]}')))->toBe(['checks' => []])
        ->and($socket->frames)->toBe([]);
});

it('refuses a run whose output was removed and cannot be read', function (): void {
    collectorSocket(['data.get' => ['type' => 'error', 'error' => ['code' => 'data_get_failed', 'message' => 'Data not found']]]);

    expect(fn () => terminalRows(collectorRun(null)))->toThrow(ResourceOperationException::class);
});

it('refuses a step with no output artifact at all', function (): void {
    collectorSocket([]);
    $run = collectorRun(null);
    $run['jobs'][0]['steps'][0]['dataArtifacts'] = [];

    expect(fn () => terminalRows($run))->toThrow(ResourceOperationException::class);
});

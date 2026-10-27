<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Tasks\WebSocketSwampRunReader;

beforeEach(function (): void {
    $this->socket = new class implements WebSocketClient
    {
        public ?WebSocketEndpoint $endpoint = null;

        public array $sent = [];

        public array $answers = [];

        public bool $connected = true;

        public bool $closed = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->endpoint = $endpoint;
        }

        public function send(array $message): void
        {
            $this->sent[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $last = array_last($this->sent);
            $answer = array_shift($this->answers);
            if ($answer === null) {
                $this->connected = false;

                return [];
            }

            return [['type' => 'heartbeat', 'id' => 'other'], ['type' => $last['type'], 'id' => $last['id'], 'payload' => $answer]];
        }

        public function close(): void
        {
            $this->closed = true;
        }

        public function isConnected(): bool
        {
            return $this->connected;
        }
    };
    $this->reader = new WebSocketSwampRunReader($this->socket, '192.0.2.17', 'reader.fake-token', '/test/ca.pem');
});

it('uses only read requests and verifies exact group inputs before fetching log tails', function (): void {
    $this->socket->answers = [
        ['data' => ['results' => [['runId' => 'run-42', 'inputs' => ['group' => '42']]]], 'total' => 9],
        ['data' => ['id' => 'run-42', 'inputs' => ['group' => '42'], 'jobs' => []]],
        ['data' => ['type' => 'log', 'log' => ['lines' => ['first', 'second']]]],
    ];
    expect($this->reader->read(42))->toMatchArray(['truncated' => true])
        ->and($this->socket->sent)->toHaveCount(3)
        ->and(array_column($this->socket->sent, 'type'))->toBe(['workflow.run.search', 'workflow.history.get', 'workflow.history.logs'])
        ->and($this->socket->sent[0]['payload'])->toBe(['inputs' => ['group' => '42'], 'limit' => 5])
        ->and($this->socket->sent[1]['payload'])->toBe(['workflowIdOrName' => 'run-42'])
        ->and($this->socket->sent[2]['payload'])->toBe(['runIdOrWorkflow' => 'run-42', 'tail' => 200])
        ->and($this->socket->endpoint->serverName)->toBe('swamp.orbit')
        ->and($this->socket->endpoint->bearerToken)->toBe('reader.fake-token')
        ->and($this->socket->endpoint->path)->toBe('/')
        ->and($this->socket->closed)->toBeTrue();
});

it('rejects another groups run even when search returns it', function (): void {
    $this->socket->answers = [['data' => ['results' => [['runId' => 'other', 'inputs' => ['group' => '420']]]]]];
    expect(fn () => $this->reader->read(42))->toThrow(RuntimeException::class)
        ->and($this->socket->sent)->toHaveCount(1)->and($this->socket->closed)->toBeTrue();
});

it('rechecks the details and refuses another run before reading logs', function (): void {
    $this->socket->answers = [
        ['data' => ['results' => [['runId' => 'run-42', 'inputs' => ['group' => '42']]]]],
        ['data' => ['id' => 'run-42', 'inputs' => ['group' => '43'], 'jobs' => []]],
    ];
    expect(fn () => $this->reader->read(42))->toThrow(RuntimeException::class)
        ->and($this->socket->sent)->toHaveCount(2)->and($this->socket->closed)->toBeTrue();
});

it('rejects malformed replies and closes a disconnected read', function (array $answers): void {
    $this->socket->answers = $answers;
    expect(fn () => $this->reader->read(42))->toThrow(RuntimeException::class)
        ->and($this->socket->closed)->toBeTrue();
})->with([
    'missing results' => [[['data' => []]]],
    'disconnect' => [[]],
    'invalid match' => [[['data' => ['results' => ['no']]]]],
]);

it('reads an available empty result and accepts runs without a log file', function (): void {
    $this->socket->answers = [['data' => ['results' => []]]];
    expect($this->reader->read(42))->toBe(['runs' => [], 'truncated' => false]);
    $this->socket->answers = [
        ['data' => ['results' => [['runId' => 'r', 'inputs' => ['group' => '42']]]]],
        ['data' => ['id' => 'r', 'inputs' => ['group' => '42'], 'jobs' => []]],
        ['data' => ['type' => 'no_log_file']],
    ];
    expect($this->reader->read(42)['runs'][0]['log'])->toBe('');
});

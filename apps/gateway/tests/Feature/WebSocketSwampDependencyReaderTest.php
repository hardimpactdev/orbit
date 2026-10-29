<?php

declare(strict_types=1);

use App\Domain\Instances\Dependencies\DependencyReadFailure;
use App\Domain\Instances\Dependencies\SwampDependencyReadException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Dependencies\WebSocketSwampDependencyReader;

beforeEach(function (): void {
    $this->socket = new class implements WebSocketClient
    {
        public ?WebSocketEndpoint $endpoint = null;

        public ?WebSocketException $failure = null;

        public array $sent = [];

        public array $answers = [];

        public bool $connected = true;

        public bool $closed = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->endpoint = $endpoint;
            if ($this->failure !== null) {
                throw $this->failure;
            }
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
    $this->reader = new WebSocketSwampDependencyReader(fn (int $limit): WebSocketClient => $this->socket, '192.0.2.17', 'reader.fake-token', '/test/ca.pem');
});

it('matches every shared native envelope and pins immutable report versions', function (string $scenario): void {
    $snapshots = json_decode(file_get_contents(base_path('tests/Fixtures/SwampDependencies/'.$scenario.'.json')), true, flags: JSON_THROW_ON_ERROR)['snapshots'];
    $requests = 0;
    foreach ($snapshots as $fixture) {
        foreach ([$fixture['index'], ...$fixture['reports']] as $rpc) {
            $this->socket->answers = [$rpc['response']['payload']];
            $payload = $rpc['request']['payload'];
            $content = $this->reader->read($payload['dataName'], $payload['version'] ?? null, isset($payload['version']) ? 1_048_576 : 65_536);
            expect($content)->toBe($rpc['response']['payload']['data']['content']);
            expect(array_last($this->socket->sent)['payload'])->toBe($payload);
            $requests++;
        }
    }
    expect(array_column($this->socket->sent, 'type'))->toBe(array_fill(0, $requests, 'data.get'));
    expect($this->socket->endpoint->address)->toBe('192.0.2.17');
    expect($this->socket->endpoint->port)->toBe(443);
    expect($this->socket->endpoint->path)->toBe('/');
    expect($this->socket->endpoint->caPath)->toBe('/test/ca.pem');
    expect($this->socket->endpoint->serverName)->toBe('swamp.orbit');
    expect($this->socket->endpoint->bearerToken)->toBe('reader.fake-token');
    expect($this->socket->closed)->toBeTrue();
})->with(['success', 'running-partial', 'failed', 'blocked', 'pr-unknown']);

it('accepts inclusive native content byte limits without truncation', function (int $limit): void {
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/SwampDependencies/success.json')), true, flags: JSON_THROW_ON_ERROR)['snapshots'][0]['index'];
    $payload = $fixture['response']['payload'];
    $content = str_pad($payload['data']['content'], $limit);
    $payload['data']['content'] = $content;
    $payload['data']['size'] = $limit;
    $payload['data']['checksum'] = hash('sha256', $content);
    $this->socket->answers = [$payload];
    expect($this->reader->read($fixture['request']['payload']['dataName'], null, $limit))->toBe($content);
})->with([65_536, 1_048_576]);

it('rejects resource metadata and bounds without publishing upstream data', function (string $field, mixed $value): void {
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/SwampDependencies/success.json')), true, flags: JSON_THROW_ON_ERROR)['snapshots'][0]['reports'][0];
    $payload = $fixture['response']['payload'];
    $payload['data'][$field] = $value;
    if ($field === 'content') {
        $payload['data']['size'] = strlen($value);
        $payload['data']['checksum'] = hash('sha256', $value);
    }
    $this->socket->answers = [$payload];
    expect(fn () => $this->reader->read($fixture['request']['payload']['dataName'], 1, 1_048_576))->toThrow(SwampDependencyReadException::class);
    expect($this->socket->closed)->toBeTrue();
})->with([
    'wrong producer' => ['modelName', 'other'], 'wrong name' => ['name', 'other'], 'latest instead of pinned' => ['version', 2],
    'base64' => ['contentEncoding', 'base64'], 'checksum' => ['checksum', 'invalid'], 'size' => ['size', 1],
    'oversize' => ['content', str_repeat('x', 1_048_577)],
]);

it('closes refused and disconnected reads and requires reader configuration', function (): void {
    $this->socket->answers = [];
    expect(fn () => $this->reader->read('missing', null, 65_536))->toThrow(RuntimeException::class);
    expect($this->socket->closed)->toBeTrue();
    $reader = new WebSocketSwampDependencyReader(fn (int $limit): WebSocketClient => $this->socket, '192.0.2.17', '', '/test/ca.pem');
    expect(fn () => $reader->read('missing', null, 65_536))->toThrow(SwampDependencyReadException::class);
});

it('bounds the socket envelope for each resource before decoding it', function (int $bytes): void {
    $limits = [];
    $reader = new WebSocketSwampDependencyReader(function (int $limit) use (&$limits): WebSocketClient {
        $limits[] = $limit;

        return $this->socket;
    }, '192.0.2.17', 'reader.fake-token', '/test/ca.pem');
    expect(fn () => $reader->read('missing', null, $bytes))->toThrow(RuntimeException::class);
    expect($limits)->toBe([6 * $bytes + 4_096]);
})->with([65_536, 1_048_576]);

it('classifies transport failures without exposing upstream exception text', function (string $message, int $status, DependencyReadFailure $failure): void {
    $this->socket->failure = new WebSocketException($message.' reader.fake-token', $status);
    expect(fn () => $this->reader->read('missing', null, 65_536))->toThrow(function (SwampDependencyReadException $exception) use ($failure): void {
        expect($exception->failure)->toBe($failure);
        expect($exception->getMessage())->not->toContain('reader.fake-token');
        expect($exception->getPrevious())->toBeNull();
    });
    expect($this->socket->closed)->toBeTrue();
})->with([
    ['TLS certificate verification failed', 0, DependencyReadFailure::Tls],
    ['Connection refused', 0, DependencyReadFailure::Unavailable],
    ['Handshake refused', 401, DependencyReadFailure::Auth],
    ['Handshake refused', 403, DependencyReadFailure::Auth],
]);

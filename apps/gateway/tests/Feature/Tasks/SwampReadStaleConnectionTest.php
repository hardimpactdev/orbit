<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;

/** A read connection that serve can close while it sits idle: it still looks open, and the next send fails. */
function staleSocket(): WebSocketClient
{
    $socket = new class implements WebSocketClient
    {
        public int $connects = 0;

        public int $sends = 0;

        public bool $stale = false;

        public bool $refuseConnect = false;

        private bool $open = false;

        /** @var array<string,mixed>|null */
        private ?array $frame = null;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            if ($this->refuseConnect) {
                throw new WebSocketException('Could not connect.');
            }
            $this->connects++;
            $this->stale = false;
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->sends++;
            if ($this->stale) {
                throw new WebSocketException('Broken pipe.');
            }
            $this->frame = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = $this->frame;
            $this->frame = null;

            return $frame === null ? [] : [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => ['ok' => true]]]];
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

function readHistory(): array
{
    $step = new ReflectionMethod(SwampCommandStep::class, 'read');

    return $step->invoke(app(SwampCommandStep::class), 'workflow.history.get', ['workflowIdOrName' => 'x']);
}

afterEach(fn () => app(SwampConnection::class)->release());

it('keeps one connection for two reads inside one queue job', function (): void {
    $socket = staleSocket();

    Queue::connection('sync')->push(CallQueuedClosure::create(static function (): void {
        readHistory();
        readHistory();
    }));

    expect($socket->connects)->toBe(1)->and($socket->sends)->toBe(2);
});

it('closes the earlier job socket before the next job starts in the same process', function (): void {
    $socket = staleSocket();
    Queue::connection('sync')->push(CallQueuedClosure::create(static function (): void {
        readHistory();
    }));
    $socket->stale = true;

    Queue::connection('sync')->push(CallQueuedClosure::create(static function (): void {
        expect(app(WebSocketClient::class)->isConnected())->toBeFalse();
        expect(readHistory()['data'])->toBe(['ok' => true]);
    }));

    expect($socket->connects)->toBe(2)->and($socket->sends)->toBe(2);
});

it('reads on a new connection when the kept one was closed while idle', function (): void {
    $socket = staleSocket();
    readHistory();
    $socket->stale = true;

    $reply = readHistory();

    expect($reply['data'])->toBe(['ok' => true])->and($socket->connects)->toBe(2)->and($socket->sends)->toBe(3);
});

it('does not try again when a new connection fails, and names the cause', function (): void {
    $socket = staleSocket();
    $socket->refuseConnect = true;

    try {
        readHistory();
        $failed = null;
    } catch (SwampTerminalReadUnavailable $exception) {
        $failed = $exception;
    }

    expect($failed)->toBeInstanceOf(SwampTerminalReadUnavailable::class)
        ->and($failed->getPrevious())->toBeInstanceOf(WebSocketException::class)
        ->and($socket->sends)->toBe(0);
});

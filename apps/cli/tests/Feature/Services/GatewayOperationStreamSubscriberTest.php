<?php

declare(strict_types=1);

use App\Exceptions\GatewayApiException;
use App\Services\GatewayOperationEventStreamClient;
use App\Services\GatewayOperationStreamSubscriber;
use App\Services\Operations\OperationStreamWebSocketConnection;
use App\Services\Operations\OperationStreamWebSocketTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ProgressEventType;

it('subscribes to a private operation stream and backfills after the last durable cursor', function (): void {
    $history = [];
    $transport = new FakeOperationStreamWebSocketTransport([
        [
            'event' => 'pusher:connection_established',
            'data' => json_encode(['socket_id' => '1234.5678', 'activity_timeout' => 120], JSON_THROW_ON_ERROR),
        ],
        ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-operations.run-1'],
        [
            'event' => 'operation.stream.frame',
            'channel' => 'private-operations.run-1',
            'data' => json_encode([
                'operation_uuid' => 'run-1',
                'sequence' => 12,
                'durable_replay_cursor' => ['event_sequence' => 12, 'event_id' => 102],
                'payload' => ['line' => 'live'],
            ], JSON_THROW_ON_ERROR),
        ],
        null,
    ], $history);
    $events = new FakeOperationStreamBackfillClient([
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => 'operation_stream.frame',
                    'frame' => [
                        'operation_uuid' => 'run-1',
                        'sequence' => 11,
                        'durable_replay_cursor' => ['event_sequence' => 11, 'event_id' => 101],
                        'payload' => ['line' => 'backfill'],
                    ],
                ],
            ],
        ],
    ], $history);
    $frames = [];

    Http::fake([
        'https://gateway.test/api/operations/run-1/stream' => Http::response([
            'success' => [
                'data' => [
                    'operation' => ['uuid' => 'run-1'],
                    'channel' => ['name' => 'private-operations.run-1', 'private' => true],
                    'reverb' => [
                        'app_key' => 'gateway-reverb-key',
                        'host' => 'operations.orbit.test',
                        'port' => 443,
                        'scheme' => 'https',
                    ],
                    'auth' => [
                        'endpoint' => '/api/operations/run-1/stream/auth',
                        'method' => 'POST',
                        'token' => operation_stream_subscriber_token(),
                    ],
                    'backfill' => [
                        'events_endpoint' => '/api/operations/run-1/events?once=1',
                        'cursor' => 11,
                    ],
                ],
            ],
        ]),
        'https://gateway.test/api/operations/run-1/stream/auth' => Http::response([
            'success' => [
                'data' => [
                    'auth' => 'gateway-reverb-key:signed-subscribe-payload',
                    'channel' => 'private-operations.run-1',
                ],
            ],
        ]),
        'https://gateway.test/api/operations/run-1/stream/leave' => Http::response([
            'success' => ['data' => ['lease' => ['active_subscribers' => 0]]],
        ]),
    ]);

    new GatewayOperationStreamSubscriber(
        baseUrl: 'https://gateway.test',
        timeout: 30,
        events: $events,
        transport: $transport,
    )->subscribe('run-1', 10, function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });

    Http::assertSent(
        fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://gateway.test/api/operations/run-1/stream/auth'
        && $request['socket_id'] === '1234.5678'
        && $request['channel_name'] === 'private-operations.run-1'
        && hash_equals(operation_stream_subscriber_token(), (string) $request['auth_token']),
    );

    Http::assertSent(
        fn (Request $request): bool => (
            $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/operations/run-1/stream/leave'
            && $request['socket_id'] === '1234.5678'
        ),
    );

    expect($transport->connection)
        ->not
        ->toBeNull()
        ->and($transport->connection?->scheme)
        ->toBe('wss')
        ->and($transport->connection?->host)
        ->toBe('gateway.test')
        ->and($transport->connection?->port)
        ->toBe(443)
        ->and($transport->connection?->appKey)
        ->toBe('gateway-reverb-key')
        ->and($transport->sent)
        ->toContain([
            'event' => 'pusher:subscribe',
            'data' => [
                'auth' => 'gateway-reverb-key:signed-subscribe-payload',
                'channel' => 'private-operations.run-1',
            ],
        ])
        ->and($events->replays)
        ->toBe([['/api/operations/run-1/events?once=1', 10]])
        ->and($history)
        ->toBe([
            'connect',
            'receive:pusher:connection_established',
            'send:pusher:subscribe',
            'receive:pusher_internal:subscription_succeeded',
            'replay:/api/operations/run-1/events?once=1:10',
            'receive:operation.stream.frame',
            'receive:null',
            'close',
        ])
        ->and($frames)
        ->toBe([
            [
                'operation_uuid' => 'run-1',
                'sequence' => 11,
                'durable_replay_cursor' => ['event_sequence' => 11, 'event_id' => 101],
                'payload' => ['line' => 'backfill'],
            ],
            [
                'operation_uuid' => 'run-1',
                'sequence' => 12,
                'durable_replay_cursor' => ['event_sequence' => 12, 'event_id' => 102],
                'payload' => ['line' => 'live'],
            ],
        ]);
});

it('renews subscriber leases on keepalive pings and refreshes expired subscriber tokens', function (): void {
    $transport = new FakeOperationStreamWebSocketTransport([
        [
            'event' => 'pusher:connection_established',
            'data' => json_encode(['socket_id' => '1234.5678'], JSON_THROW_ON_ERROR),
        ],
        ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-operations.run-1'],
        ['event' => 'pusher:ping', 'data' => '{}'],
        null,
    ]);
    $events = new FakeOperationStreamBackfillClient([]);

    Http::fake([
        'https://gateway.test/api/operations/run-1/stream' => Http::sequence()
            ->push(operation_stream_descriptor(operation_stream_subscriber_token(), cursor: null))
            ->push(operation_stream_descriptor(operation_stream_refreshed_subscriber_token(), cursor: null)),
        'https://gateway.test/api/operations/run-1/stream/auth' => Http::sequence()
            ->push([
                'success' => [
                    'data' => [
                        'auth' => 'gateway-reverb-key:initial-signature',
                        'channel' => 'private-operations.run-1',
                    ],
                ],
            ])
            ->push([
                'error' => [
                    'code' => 'operation_stream.auth_expired',
                    'message' => 'The operation stream authorization token has expired.',
                    'meta' => [],
                ],
            ], 403)
            ->push([
                'success' => [
                    'data' => [
                        'auth' => 'gateway-reverb-key:renewed-signature',
                        'channel' => 'private-operations.run-1',
                    ],
                ],
            ]),
        'https://gateway.test/api/operations/run-1/stream/leave' => Http::response([
            'success' => ['data' => ['lease' => ['active_subscribers' => 0]]],
        ]),
    ]);

    new GatewayOperationStreamSubscriber(
        baseUrl: 'https://gateway.test',
        timeout: 30,
        events: $events,
        transport: $transport,
    )->subscribe('run-1', null, fn () => null);

    $authTokens = Http::recorded()
        ->filter(
            fn (array $record): bool => $record[0]->url() === 'https://gateway.test/api/operations/run-1/stream/auth',
        )
        ->map(fn (array $record): string => $record[0]['auth_token'])
        ->values()
        ->all();

    expect(
        collect($transport->sent)
            ->contains(
                fn (array $message): bool => (
                    ($message['event'] ?? null) === 'pusher:pong'
                    && ($message['data'] ?? null) instanceof stdClass
                ),
            ),
    )
        ->toBeTrue()
        ->and($authTokens)
        ->toBe([
            operation_stream_subscriber_token(),
            operation_stream_subscriber_token(),
            operation_stream_refreshed_subscriber_token(),
        ]);
});

it('replays frames published after descriptor fetch but before subscription confirmation', function (): void {
    $transport = new FakeOperationStreamWebSocketTransport([
        [
            'event' => 'pusher:connection_established',
            'data' => json_encode(['socket_id' => '1234.5678'], JSON_THROW_ON_ERROR),
        ],
        ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-operations.run-1'],
        null,
    ]);
    $events = new FakeOperationStreamBackfillClient([
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => 'operation_stream.frame',
                    'frame' => [
                        'operation_uuid' => 'run-1',
                        'sequence' => 11,
                        'durable_replay_cursor' => ['event_sequence' => 11, 'event_id' => 101],
                        'payload' => ['line' => 'post-descriptor'],
                    ],
                ],
            ],
        ],
    ]);
    $frames = [];

    Http::fake([
        'https://gateway.test/api/operations/run-1/stream' => Http::response(operation_stream_descriptor(
            operation_stream_subscriber_token(),
            cursor: null,
        )),
        'https://gateway.test/api/operations/run-1/stream/auth' => Http::response([
            'success' => [
                'data' => [
                    'auth' => 'gateway-reverb-key:signed-subscribe-payload',
                    'channel' => 'private-operations.run-1',
                ],
            ],
        ]),
        'https://gateway.test/api/operations/run-1/stream/leave' => Http::response([
            'success' => ['data' => ['lease' => ['active_subscribers' => 0]]],
        ]),
    ]);

    new GatewayOperationStreamSubscriber(
        baseUrl: 'https://gateway.test',
        timeout: 30,
        events: $events,
        transport: $transport,
    )->subscribe('run-1', null, function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });

    expect($events->replays)
        ->toBe([['/api/operations/run-1/events?once=1', null]])
        ->and($frames)
        ->toBe([
            [
                'operation_uuid' => 'run-1',
                'sequence' => 11,
                'durable_replay_cursor' => ['event_sequence' => 11, 'event_id' => 101],
                'payload' => ['line' => 'post-descriptor'],
            ],
        ]);
});

it('dedupes live frames and falls back to durable replay on websocket protocol failure', function (): void {
    $transport = new FakeOperationStreamWebSocketTransport([
        [
            'event' => 'pusher:connection_established',
            'data' => json_encode(['socket_id' => '1234.5678'], JSON_THROW_ON_ERROR),
        ],
        ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-operations.run-1'],
        [
            'event' => 'operation.stream.frame',
            'channel' => 'private-operations.run-1',
            'data' => json_encode([
                'operation_uuid' => 'run-1',
                'sequence' => 11,
                'durable_replay_cursor' => ['event_sequence' => 11, 'event_id' => 101],
                'payload' => ['line' => 'duplicate-live'],
            ], JSON_THROW_ON_ERROR),
        ],
        ['event' => 'pusher:error', 'data' => json_encode(['message' => 'subscription lost'], JSON_THROW_ON_ERROR)],
    ]);
    $events = new FakeOperationStreamBackfillClient([
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => 'operation_stream.frame',
                    'frame' => [
                        'operation_uuid' => 'run-1',
                        'sequence' => 11,
                        'durable_replay_cursor' => ['event_sequence' => 11, 'event_id' => 101],
                        'payload' => ['line' => 'backfill'],
                    ],
                ],
            ],
        ],
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => 'operation_stream.frame',
                    'frame' => [
                        'operation_uuid' => 'run-1',
                        'sequence' => 11,
                        'durable_replay_cursor' => ['event_sequence' => 11, 'event_id' => 101],
                        'payload' => ['line' => 'duplicate-fallback'],
                    ],
                ],
            ],
            [
                'id' => 102,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => 'operation_stream.frame',
                    'frame' => [
                        'operation_uuid' => 'run-1',
                        'sequence' => 12,
                        'durable_replay_cursor' => ['event_sequence' => 12, 'event_id' => 102],
                        'payload' => ['line' => 'fallback'],
                    ],
                ],
            ],
        ],
    ]);
    $frames = [];

    Http::fake([
        'https://gateway.test/api/operations/run-1/stream' => Http::response(operation_stream_descriptor(
            operation_stream_subscriber_token(),
            cursor: 11,
        )),
        'https://gateway.test/api/operations/run-1/stream/auth' => Http::response([
            'success' => [
                'data' => [
                    'auth' => 'gateway-reverb-key:signed-subscribe-payload',
                    'channel' => 'private-operations.run-1',
                ],
            ],
        ]),
        'https://gateway.test/api/operations/run-1/stream/leave' => Http::response([
            'success' => ['data' => ['lease' => ['active_subscribers' => 0]]],
        ]),
    ]);

    new GatewayOperationStreamSubscriber(
        baseUrl: 'https://gateway.test',
        timeout: 30,
        events: $events,
        transport: $transport,
    )->subscribe('run-1', 100, function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });

    expect($events->replays)
        ->toBe([
            ['/api/operations/run-1/events?once=1', 100],
            ['/api/operations/run-1/events?once=1', 101],
        ])
        ->and($frames)
        ->toBe([
            [
                'operation_uuid' => 'run-1',
                'sequence' => 11,
                'durable_replay_cursor' => ['event_sequence' => 11, 'event_id' => 101],
                'payload' => ['line' => 'backfill'],
            ],
            [
                'operation_uuid' => 'run-1',
                'sequence' => 12,
                'durable_replay_cursor' => ['event_sequence' => 12, 'event_id' => 102],
                'payload' => ['line' => 'fallback'],
            ],
        ]);
});

it('durably replays frames when the websocket fails before the descriptor cursor advances', function (): void {
    $history = [];
    $transport = new FailingOperationStreamWebSocketTransport($history);
    $events = new FakeOperationStreamBackfillClient([
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => 'operation_stream.frame',
                    'frame' => [
                        'operation_uuid' => 'run-1',
                        'sequence' => 11,
                        'durable_replay_cursor' => ['event_sequence' => 11, 'event_id' => 101],
                        'payload' => ['line' => 'fallback-after-connect-failure'],
                    ],
                ],
            ],
        ],
    ], $history);
    $frames = [];

    Http::fake([
        'https://gateway.test/api/operations/run-1/stream' => Http::response(operation_stream_descriptor(
            operation_stream_subscriber_token(),
            cursor: null,
        )),
    ]);

    new GatewayOperationStreamSubscriber(
        baseUrl: 'https://gateway.test',
        timeout: 30,
        events: $events,
        transport: $transport,
    )->subscribe('run-1', null, function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });

    expect($events->replays)
        ->toBe([['/api/operations/run-1/events?once=1', null]])
        ->and($history)
        ->toBe([
            'connect',
            'replay:/api/operations/run-1/events?once=1:null',
            'close',
        ])
        ->and($frames)
        ->toBe([
            [
                'operation_uuid' => 'run-1',
                'sequence' => 11,
                'durable_replay_cursor' => ['event_sequence' => 11, 'event_id' => 101],
                'payload' => ['line' => 'fallback-after-connect-failure'],
            ],
        ]);
});

/**
 * @return array<string, mixed>
 */
function operation_stream_subscriber_token(): string
{
    return implode('-', ['subscriber', 'token']);
}

function operation_stream_refreshed_subscriber_token(): string
{
    return implode('-', ['refreshed-subscriber', 'token']);
}

function operation_stream_descriptor(
    #[SensitiveParameter]
    string $authToken,
    ?int $cursor,
): array {
    return [
        'success' => [
            'data' => [
                'operation' => ['uuid' => 'run-1'],
                'channel' => ['name' => 'private-operations.run-1', 'private' => true],
                'reverb' => [
                    'app_key' => 'gateway-reverb-key',
                    'host' => 'operations.orbit.test',
                    'port' => 443,
                    'scheme' => 'https',
                ],
                'auth' => [
                    'endpoint' => '/api/operations/run-1/stream/auth',
                    'method' => 'POST',
                    'token' => $authToken,
                ],
                'backfill' => [
                    'events_endpoint' => '/api/operations/run-1/events?once=1',
                    'cursor' => $cursor,
                ],
            ],
        ],
    ];
}

class FakeOperationStreamWebSocketTransport implements OperationStreamWebSocketTransport
{
    public ?OperationStreamWebSocketConnection $connection = null;

    /**
     * @var list<string>
     */
    private array $history = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $sent = [];

    /**
     * @param  list<array<string, mixed>|null>  $messages
     * @param  list<string>  $history
     */
    public function __construct(
        private array $messages,
        array &$history = [],
    ) {
        $this->history = &$history;
    }

    public function connect(OperationStreamWebSocketConnection $connection): void
    {
        $this->history[] = 'connect';
        $this->connection = $connection;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function send(array $message): void
    {
        $this->history[] = 'send:'.$message['event'];
        $this->sent[] = $message;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function receive(): ?array
    {
        $message = array_shift($this->messages);

        $this->history[] = 'receive:'.($message['event'] ?? 'null');

        return $message;
    }

    public function close(): void
    {
        $this->history[] = 'close';
    }
}

class FailingOperationStreamWebSocketTransport extends FakeOperationStreamWebSocketTransport
{
    /**
     * @param  list<string>  $history
     */
    public function __construct(array &$history = [])
    {
        parent::__construct([], $history);
    }

    #[Override]
    public function connect(OperationStreamWebSocketConnection $connection): void
    {
        parent::connect($connection);

        throw GatewayApiException::networkError(new RuntimeException('socket refused'));
    }
}

class FakeOperationStreamBackfillClient extends GatewayOperationEventStreamClient
{
    /**
     * @var list<array{0: string, 1: int|null}>
     */
    public array $replays = [];

    /**
     * @var list<string>
     */
    private array $history = [];

    /**
     * @param  list<list<array{id: int|null, type: ProgressEventType, payload: array<string, mixed>}>>  $eventBatches
     * @param  list<string>  $history
     */
    public function __construct(
        private array $eventBatches,
        array &$history = [],
    ) {
        $this->history = &$history;
    }

    /**
     * @param  callable(ProgressEventType, array<string, mixed>, int|null): void  $onEvent
     */
    #[Override]
    public function replay(string $eventsUrl, ?int $lastEventId, callable $onEvent): ?array
    {
        $this->replays[] = [$eventsUrl, $lastEventId];
        $this->history[] = "replay:{$eventsUrl}:".($lastEventId ?? 'null');

        $events = array_shift($this->eventBatches) ?? [];

        foreach ($events as $event) {
            $onEvent($event['type'], $event['payload'], $event['id']);
        }

        return null;
    }
}

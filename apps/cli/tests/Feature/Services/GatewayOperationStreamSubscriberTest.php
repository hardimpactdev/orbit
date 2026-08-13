<?php

declare(strict_types=1);

use App\Exceptions\GatewayApiException;
use App\Services\GatewayOperationEventStreamClient;
use App\Services\GatewayOperationStreamSubscriber;
use App\Services\Operations\OperationStreamWebSocketConnection;
use App\Services\Operations\OperationStreamWebSocketTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Operations\OperationStreamFrameEvents;
use Orbit\Core\Progress\ProgressEventType;

it('resumes backfill from the operation-local sequence when the global event id differs', function (): void {
    $history = [];
    $backfillFrame = operation_stream_durable_node_frame(11, 11, 101, 'backfill');
    $liveFrame = operation_stream_durable_node_frame(12, 12, 102, 'live');
    $transport = new FakeOperationStreamWebSocketTransport([
        [
            'event' => 'pusher:connection_established',
            'data' => json_encode(['socket_id' => '1234.5678', 'activity_timeout' => 120], JSON_THROW_ON_ERROR),
        ],
        ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-operations.run-1'],
        [
            'event' => OperationStreamFrameEvents::Live,
            'channel' => 'private-operations.run-1',
            'data' => json_encode($liveFrame, JSON_THROW_ON_ERROR),
        ],
        null,
    ], $history);
    $events = new FakeOperationStreamBackfillClient([
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => OperationStreamFrameEvents::Journal,
                    'frame' => $backfillFrame,
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
        ->toBe([
            ['/api/operations/run-1/events?once=1', 10],
            ['/api/operations/run-1/events?once=1', 12],
        ])
        ->and($history)
        ->toBe([
            'connect',
            'receive:pusher:connection_established',
            'send:pusher:subscribe',
            'receive:pusher_internal:subscription_succeeded',
            'replay:/api/operations/run-1/events?once=1:10',
            'receive:operation.stream.frame',
            'receive:null',
            'replay:/api/operations/run-1/events?once=1:12',
            'close',
        ])
        ->and($frames)
        ->toBe([$backfillFrame, $liveFrame]);
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
    $postDescriptorFrame = operation_stream_durable_node_frame(11, 11, 101, 'post-descriptor');
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
                    'event' => OperationStreamFrameEvents::Journal,
                    'frame' => $postDescriptorFrame,
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
        ->toBe([
            ['/api/operations/run-1/events?once=1', null],
            ['/api/operations/run-1/events?once=1', 11],
        ])
        ->and($frames)
        ->toBe([$postDescriptorFrame]);
});

it('replays frames missed between initial replay and websocket close', function (): void {
    $finalReplayFrame = operation_stream_durable_node_frame(11, 11, 101, 'final-replay');
    $transport = new FakeOperationStreamWebSocketTransport([
        [
            'event' => 'pusher:connection_established',
            'data' => json_encode(['socket_id' => '1234.5678'], JSON_THROW_ON_ERROR),
        ],
        ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-operations.run-1'],
        null,
    ]);
    $events = new FakeOperationStreamBackfillClient([
        [],
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => OperationStreamFrameEvents::Journal,
                    'frame' => $finalReplayFrame,
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
        ->toBe([
            ['/api/operations/run-1/events?once=1', null],
            ['/api/operations/run-1/events?once=1', null],
        ])
        ->and($frames)
        ->toBe([$finalReplayFrame]);
});

it('dedupes live frames and falls back to durable replay on websocket protocol failure', function (): void {
    $backfillFrame = operation_stream_durable_node_frame(11, 11, 101, 'backfill');
    $duplicateLiveFrame = operation_stream_durable_node_frame(11, 11, 101, 'duplicate-live');
    $duplicateFallbackFrame = operation_stream_durable_node_frame(11, 11, 101, 'duplicate-fallback');
    $fallbackFrame = operation_stream_durable_node_frame(12, 12, 102, 'fallback');
    $transport = new FakeOperationStreamWebSocketTransport([
        [
            'event' => 'pusher:connection_established',
            'data' => json_encode(['socket_id' => '1234.5678'], JSON_THROW_ON_ERROR),
        ],
        ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-operations.run-1'],
        [
            'event' => OperationStreamFrameEvents::Live,
            'channel' => 'private-operations.run-1',
            'data' => json_encode($duplicateLiveFrame, JSON_THROW_ON_ERROR),
        ],
        ['event' => 'pusher:error', 'data' => json_encode(['message' => 'subscription lost'], JSON_THROW_ON_ERROR)],
    ]);
    $events = new FakeOperationStreamBackfillClient([
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => OperationStreamFrameEvents::Journal,
                    'frame' => $backfillFrame,
                ],
            ],
        ],
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => OperationStreamFrameEvents::Journal,
                    'frame' => $duplicateFallbackFrame,
                ],
            ],
            [
                'id' => 102,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => OperationStreamFrameEvents::Journal,
                    'frame' => $fallbackFrame,
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
    )->subscribe('run-1', 10, function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });

    expect($events->replays)
        ->toBe([
            ['/api/operations/run-1/events?once=1', 10],
            ['/api/operations/run-1/events?once=1', 11],
        ])
        ->and($frames)
        ->toBe([$backfillFrame, $fallbackFrame]);
});

it('durably replays frames when the websocket fails before the descriptor cursor advances', function (): void {
    $history = [];
    $fallbackFrame = operation_stream_durable_node_frame(11, 11, 101, 'fallback-after-connect-failure');
    $transport = new FailingOperationStreamWebSocketTransport($history);
    $events = new FakeOperationStreamBackfillClient([
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => OperationStreamFrameEvents::Journal,
                    'frame' => $fallbackFrame,
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
        ->toBe([$fallbackFrame]);
});

it('rejects a live frame without a durable replay cursor', function (): void {
    $transport = new FakeOperationStreamWebSocketTransport([
        [
            'event' => 'pusher:connection_established',
            'data' => json_encode(['socket_id' => '1234.5678'], JSON_THROW_ON_ERROR),
        ],
        ['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'private-operations.run-1'],
        [
            'event' => OperationStreamFrameEvents::Live,
            'channel' => 'private-operations.run-1',
            'data' => json_encode(operation_stream_cursorless_node_frame(), JSON_THROW_ON_ERROR),
        ],
    ]);

    Http::fake([
        'https://gateway.test/api/operations/run-1/stream' => Http::response(operation_stream_descriptor(
            operation_stream_subscriber_token(),
            cursor: null,
        )),
        'https://gateway.test/api/operations/run-1/stream/auth' => Http::response([
            'success' => ['data' => ['auth' => 'gateway-reverb-key:signed-subscribe-payload']],
        ]),
        'https://gateway.test/api/operations/run-1/stream/leave' => Http::response([
            'success' => ['data' => ['lease' => ['active_subscribers' => 0]]],
        ]),
    ]);

    try {
        new GatewayOperationStreamSubscriber(
            baseUrl: 'https://gateway.test',
            timeout: 30,
            events: new FakeOperationStreamBackfillClient([[]]),
            transport: $transport,
        )->subscribe('run-1', null, fn () => null);

        test()->fail('Expected a malformed live operation stream frame.');
    } catch (GatewayApiException $exception) {
        expect($exception->failureKind())->toBe(App\Exceptions\GatewayApiFailureKind::StreamMalformed);
    }
});

it('keeps cursorless frames compatible when they come from the operation event journal', function (): void {
    $legacyFrame = operation_stream_cursorless_node_frame();
    $frames = [];
    $events = new FakeOperationStreamBackfillClient([
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => OperationStreamFrameEvents::Journal,
                    'frame' => $legacyFrame,
                ],
            ],
        ],
    ]);

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
        transport: new FailingOperationStreamWebSocketTransport,
    )->subscribe('run-1', null, function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });

    expect($events->replays)
        ->toBe([['/api/operations/run-1/events?once=1', null]])
        ->and($frames)
        ->toBe([$legacyFrame]);
});

it('keeps old incomplete cursor frames compatible during operation event journal replay', function (): void {
    $legacyFrame = operation_stream_node_fixture('node-durable-frame.json');
    $legacyFrame['durable_replay_cursor'] = [
        'operation_uuid' => 'run-1',
        'event_sequence' => null,
        'event_id' => null,
    ];
    $frames = [];
    $events = new FakeOperationStreamBackfillClient([
        [
            [
                'id' => 101,
                'type' => ProgressEventType::Step,
                'payload' => [
                    'event' => OperationStreamFrameEvents::Journal,
                    'frame' => $legacyFrame,
                ],
            ],
        ],
    ]);

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
        transport: new FailingOperationStreamWebSocketTransport,
    )->subscribe('run-1', null, function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });

    expect($events->replays)
        ->toBe([['/api/operations/run-1/events?once=1', null]])
        ->and($frames)
        ->toBe([$legacyFrame]);
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

/**
 * @return array<string, mixed>
 */
function operation_stream_durable_node_frame(
    int $sequence,
    int $eventSequence,
    int $eventId,
    string $data,
): array {
    $frame = operation_stream_node_fixture('node-durable-frame.json');
    $frame['sequence'] = $sequence;
    $frame['payload'] = ['line' => $data];
    $frame['durable_replay_cursor'] = [
        'operation_uuid' => 'run-1',
        'event_sequence' => $eventSequence,
        'event_id' => $eventId,
    ];

    return $frame;
}

/**
 * @return array<string, mixed>
 */
function operation_stream_cursorless_node_frame(): array
{
    $frame = operation_stream_node_fixture('node-durable-frame.json');
    unset($frame['durable_replay_cursor']);

    return $frame;
}

/**
 * @return array<string, mixed>
 */
function operation_stream_node_fixture(string $name): array
{
    $contents = file_get_contents(
        dirname(__DIR__, 5)."/packages/core/tests/Fixtures/Operations/{$name}",
    );

    if ($contents === false) {
        throw new RuntimeException("Unable to read the operation stream frame fixture [{$name}].");
    }

    /** @var array<string, mixed> $frame */
    $frame = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);

    return $frame;
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
    public function replay(string $eventsUrl, ?int $lastSequence, callable $onEvent): ?array
    {
        $this->replays[] = [$eventsUrl, $lastSequence];
        $this->history[] = "replay:{$eventsUrl}:".($lastSequence ?? 'null');

        $events = array_shift($this->eventBatches) ?? [];

        foreach ($events as $event) {
            $onEvent($event['type'], $event['payload'], $event['id']);
        }

        return null;
    }
}

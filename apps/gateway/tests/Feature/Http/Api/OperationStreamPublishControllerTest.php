<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationStreamFrameBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const OPERATION_STREAM_PUBLISH_GATEWAY_WG_IP = '10.6.0.44';

const OPERATION_STREAM_PUBLISH_AGENT_WG_IP = '10.6.0.45';

const OPERATION_STREAM_PUBLISH_OTHER_AGENT_WG_IP = '10.6.0.46';

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-08 13:00:00');

    Config::set('orbit.operation_token_secret', operation_stream_publish_token_secret());
    Config::set('orbit.operations.publisher_token_ttl_seconds', 120);
    Config::set('orbit.operations.reverb.app_id', 'orbit-operations');
    Config::set('orbit.operations.reverb.app_key', 'operations-key');
    Config::set('orbit.operations.reverb.app_secret', operation_stream_publish_reverb_secret());
    Config::set('orbit.operations.reverb.host', 'orbit-operations-reverb');
    Config::set('orbit.operations.reverb.port', 8080);
    Config::set('orbit.operations.reverb.scheme', 'http');
    Config::set('orbit.operations.reverb.timeout_seconds', 2);

    $this->gateway = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway',
            'host' => 'gateway',
            'wireguard_address' => OPERATION_STREAM_PUBLISH_GATEWAY_WG_IP,
        ]);

    $this->agent = Node::factory()->create([
        'name' => 'agent-1',
        'host' => 'agent-1',
        'wireguard_address' => OPERATION_STREAM_PUBLISH_AGENT_WG_IP,
    ]);

    $this->otherAgent = Node::factory()->create([
        'name' => 'agent-2',
        'host' => 'agent-2',
        'wireguard_address' => OPERATION_STREAM_PUBLISH_OTHER_AGENT_WG_IP,
    ]);

    $this->run = app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
        targetNodeId: $this->agent->id,
    );
});

it('publishes accepted frames to the operations Reverb service with a signed Pusher event request', function (): void {
    Http::fake([
        'http://orbit-operations-reverb:8080/apps/orbit-operations/events*' => Http::response([], 202),
    ]);

    $token = operation_stream_publish_credentials($this->run)->json('success.data.publisher.token');

    publish_operation_stream_frame($this->run, [
        'publisher_token' => $token,
        'frame' => [
            'operation_uuid' => $this->run->id,
            'channel' => "private-operations.{$this->run->id}",
            'sequence' => 14,
            'emitted_at' => Carbon::now()->toIso8601String(),
            'type' => 'stdout',
            'payload' => [
                'data' => 'published'.PHP_EOL,
                'encoding' => 'utf-8',
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('success.data.broadcast.delivered', true);

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(1);

    [$request] = $recorded[0];
    $body = $request->data();
    $expectedBody = json_encode($body, JSON_UNESCAPED_SLASHES);
    $expectedSignature = hash_hmac(
        'sha256',
        "POST\n/apps/orbit-operations/events\n"
            .http_build_query(
                data: [
                    'auth_key' => 'operations-key',
                    'auth_timestamp' => (string) Carbon::now()->timestamp,
                    'auth_version' => '1.0',
                    'body_md5' => md5($expectedBody),
                ],
                numeric_prefix: '',
                arg_separator: '&',
                encoding_type: PHP_QUERY_RFC3986,
            ),
        operation_stream_publish_reverb_secret(),
    );
    $data = json_decode((string) $body['data'], true);

    expect($request->url())
        ->toBe(
            'http://orbit-operations-reverb:8080/apps/orbit-operations/events?'
                .http_build_query(
                    data: [
                        'auth_key' => 'operations-key',
                        'auth_timestamp' => (string) Carbon::now()->timestamp,
                        'auth_version' => '1.0',
                        'body_md5' => md5($expectedBody),
                        'auth_signature' => $expectedSignature,
                    ],
                    numeric_prefix: '',
                    arg_separator: '&',
                    encoding_type: PHP_QUERY_RFC3986,
                ),
        )
        ->not
        ->toContain('operations-secret')
        ->and($request->method())
        ->toBe('POST')
        ->and($body['name'])
        ->toBe('operation.stream.frame')
        ->and($body['channel'])
        ->toBe("private-operations.{$this->run->id}")
        ->and($data)
        ->toBeArray()
        ->and($data['operation_uuid'])
        ->toBe($this->run->id)
        ->and($data['type'])
        ->toBe('stdout')
        ->and($data['payload']['data'])
        ->toBe('published'.PHP_EOL)
        ->and($data['durable_replay_cursor']['event_sequence'])
        ->toBe(1);

    expect(OperationEvent::query()->where('operation_run_id', $this->run->id)->count())->toBe(1);
});

it('accepts canonical target agent operation stream frames and records durable replay cursors', function (
    string $frameType,
    array $payload,
): void {
    $publisher = new RecordingOperationStreamFrameBroadcaster;
    app()->instance(OperationStreamFrameBroadcaster::class, $publisher);

    $token = operation_stream_publish_credentials($this->run)->json('success.data.publisher.token');

    $response = publish_operation_stream_frame($this->run, [
        'publisher_token' => $token,
        'frame' => [
            'operation_uuid' => $this->run->id,
            'channel' => "private-operations.{$this->run->id}",
            'sequence' => 7,
            'emitted_at' => Carbon::now()->toIso8601String(),
            'type' => $frameType,
            'payload' => $payload,
        ],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success.data.operation.uuid', $this->run->id)
        ->assertJsonPath('success.data.frame.operation_uuid', $this->run->id)
        ->assertJsonPath('success.data.frame.channel', "private-operations.{$this->run->id}")
        ->assertJsonPath('success.data.frame.sequence', 7)
        ->assertJsonPath('success.data.frame.source_node.id', $this->agent->id)
        ->assertJsonPath('success.data.frame.source_node.name', 'agent-1')
        ->assertJsonPath('success.data.frame.type', $frameType)
        ->assertJsonPath('success.data.frame.emitted_at', Carbon::now()->toIso8601String())
        ->assertJsonPath('success.data.frame.durable_replay_cursor.operation_uuid', $this->run->id)
        ->assertJsonPath('success.data.broadcast.delivered', true);

    $event = OperationEvent::query()->where('operation_run_id', $this->run->id)->firstOrFail();

    expect($event->event_type)
        ->toBe('operation_stream.frame')
        ->and($event->payload['frame']['operation_uuid'])
        ->toBe($this->run->id)
        ->and($event->payload['frame']['durable_replay_cursor']['event_sequence'])
        ->toBe($event->sequence)
        ->and($event->payload['frame']['durable_replay_cursor']['event_id'])
        ->toBe($event->id)
        ->and($publisher->frames)
        ->toHaveCount(1)
        ->and($publisher->frames[0]['durable_replay_cursor']['event_sequence'])
        ->toBe($event->sequence);
})->with([
    'stdout' => ['stdout', ['data' => 'installing gateway service'.PHP_EOL, 'encoding' => 'utf-8']],
    'stderr' => ['stderr', ['data' => 'warning'.PHP_EOL, 'encoding' => 'utf-8']],
    'status' => ['status', ['status' => 'running']],
    'control' => ['control', ['action' => 'heartbeat']],
    'error' => ['error', ['code' => 'process_timeout', 'message' => 'Timed out']],
    'terminal' => ['terminal', ['exit_code' => 0, 'duration_ms' => 1200]],
]);

it('records terminal frames without mutating the authoritative operation outcome', function (): void {
    $token = operation_stream_publish_credentials($this->run)->json('success.data.publisher.token');

    publish_operation_stream_frame($this->run, [
        'publisher_token' => $token,
        'frame' => [
            'operation_uuid' => $this->run->id,
            'channel' => "private-operations.{$this->run->id}",
            'sequence' => 8,
            'emitted_at' => Carbon::now()->toIso8601String(),
            'type' => 'terminal',
            'payload' => [
                'exit_code' => 0,
                'duration_ms' => 1200,
            ],
        ],
    ])->assertOk();

    expect($this->run->refresh()->status->value)
        ->toBe('queued')
        ->and(OperationEvent::query()->where('operation_run_id', $this->run->id)->count())
        ->toBe(1);
});

it('persists frames when best effort broadcast delivery fails', function (): void {
    app()->instance(OperationStreamFrameBroadcaster::class, new FailingOperationStreamFrameBroadcaster);

    $token = operation_stream_publish_credentials($this->run)->json('success.data.publisher.token');

    publish_operation_stream_frame($this->run, [
        'publisher_token' => $token,
        'frame' => [
            'operation_uuid' => $this->run->id,
            'channel' => "private-operations.{$this->run->id}",
            'sequence' => 9,
            'emitted_at' => Carbon::now()->toIso8601String(),
            'type' => 'stderr',
            'payload' => [
                'data' => 'warning'.PHP_EOL,
                'encoding' => 'utf-8',
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('success.data.broadcast.delivered', false)
        ->assertJsonPath('success.data.broadcast.error.code', 'operation_stream.broadcast_failed');

    expect(OperationEvent::query()->where('operation_run_id', $this->run->id)->count())->toBe(1);
});

it('rejects publisher credentials that do not match the operation stream publish scope', function (string $credentialCase): void {
    publish_operation_stream_frame($this->run, [
        'publisher_token' => operation_stream_publisher_token_for_case($credentialCase),
        'frame' => [
            'operation_uuid' => $this->run->id,
            'channel' => "private-operations.{$this->run->id}",
            'sequence' => 10,
            'emitted_at' => Carbon::now()->toIso8601String(),
            'type' => 'status',
            'payload' => [
                'status' => 'running',
            ],
        ],
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'operation_stream.publisher_invalid');

    expect(OperationEvent::query()->where('operation_run_id', $this->run->id)->count())->toBe(0);
})->with([
    'malformed credential' => 'malformed',
    'wrong operation' => 'wrong-operation',
    'wrong channel' => 'wrong-channel',
    'wrong target node' => 'wrong-target-node',
]);

it('rejects missing publisher credentials', function (): void {
    publish_operation_stream_frame($this->run, [
        'frame' => [
            'operation_uuid' => $this->run->id,
            'channel' => "private-operations.{$this->run->id}",
            'sequence' => 10,
            'emitted_at' => Carbon::now()->toIso8601String(),
            'type' => 'status',
            'payload' => [
                'status' => 'running',
            ],
        ],
    ])->assertUnprocessable();
});

it('rejects expired publisher credentials separately from malformed credentials', function (): void {
    $token = operation_stream_publish_credentials($this->run)->json('success.data.publisher.token');

    Carbon::setTestNow(Carbon::now()->addSeconds(121));

    publish_operation_stream_frame($this->run, [
        'publisher_token' => $token,
        'frame' => [
            'operation_uuid' => $this->run->id,
            'channel' => "private-operations.{$this->run->id}",
            'sequence' => 11,
            'emitted_at' => Carbon::now()->toIso8601String(),
            'type' => 'status',
            'payload' => [
                'status' => 'running',
            ],
        ],
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'operation_stream.publisher_expired');

    expect(OperationEvent::query()->where('operation_run_id', $this->run->id)->count())->toBe(0);
});

it('rejects publish requests from nodes other than the target agent', function (): void {
    $token = operation_stream_publish_credentials($this->run)->json('success.data.publisher.token');

    publish_operation_stream_frame(
        $this->run,
        [
            'publisher_token' => $token,
            'frame' => [
                'operation_uuid' => $this->run->id,
                'channel' => "private-operations.{$this->run->id}",
                'sequence' => 12,
                'emitted_at' => Carbon::now()->toIso8601String(),
                'type' => 'status',
                'payload' => [
                    'status' => 'running',
                ],
            ],
        ],
        OPERATION_STREAM_PUBLISH_OTHER_AGENT_WG_IP,
    )
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');
});

it('rejects frames that claim the wrong operation or channel', function (
    array $frameOverride,
    string $errorCode,
): void {
    $token = operation_stream_publish_credentials($this->run)->json('success.data.publisher.token');

    publish_operation_stream_frame($this->run, [
        'publisher_token' => $token,
        'frame' => [
            'operation_uuid' => $this->run->id,
            'channel' => "private-operations.{$this->run->id}",
            'sequence' => 13,
            'emitted_at' => Carbon::now()->toIso8601String(),
            'type' => 'control',
            'payload' => [
                'action' => 'heartbeat',
            ],
            ...$frameOverride,
        ],
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', $errorCode);
})->with([
    'wrong operation uuid' => [
        ['operation_uuid' => '00000000-0000-4000-8000-000000000001'],
        'operation_stream.operation_denied',
    ],
    'wrong channel' => [
        ['channel' => 'private-operations.wrong-channel'],
        'operation_stream.channel_denied',
    ],
]);

it('registers the publish route outside app websocket binding semantics', function (): void {
    $route = Route::getRoutes()->getByName('api.operations.stream.publish');

    expect($route)->not->toBeNull();
});

function operation_stream_publish_token_secret(): string
{
    return implode('-', ['operation-stream-publish', 'secret']);
}

function operation_stream_publish_reverb_secret(): string
{
    return implode('-', ['operations', 'secret']);
}

function operation_stream_publish_credentials(OperationRun $operationRun): TestResponse
{
    return test()->call(
        'POST',
        "/api/operations/{$operationRun->id}/stream/publisher-credentials",
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => OPERATION_STREAM_PUBLISH_AGENT_WG_IP,
        ],
    );
}

/**
 * @param  array<string, mixed>  $data
 */
function publish_operation_stream_frame(
    OperationRun $operationRun,
    array $data,
    string $remoteAddress = OPERATION_STREAM_PUBLISH_AGENT_WG_IP,
): TestResponse {
    return test()->call(
        'POST',
        "/api/operations/{$operationRun->id}/stream/publish",
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $remoteAddress,
        ],
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

/**
 * @param  array{
 *     operation_uuid: string,
 *     channel: string,
 *     target_node_id: int,
 *     expires_at: int,
 * }  $overrides
 */
function signed_operation_stream_publisher_token(array $overrides): string
{
    $payload = base64_encode(json_encode([
        'id' => (string) Str::uuid(),
        'purpose' => 'publisher',
        ...$overrides,
    ], JSON_THROW_ON_ERROR));

    return (
        "{$payload}."
        .hash_hmac(
            algo: 'sha256',
            data: $payload,
            key: Config::string('orbit.operation_token_secret'),
        )
    );
}

function operation_stream_publisher_token_for_case(string $case): string
{
    if ($case === 'malformed') {
        return 'not-a-signed-token';
    }

    if ($case === 'wrong-operation') {
        $otherRun = app(OperationRunRecorder::class)->queued(
            operationId: (string) Str::uuid(),
            lane: 'gateway',
            operationType: 'update:all',
            targetNodeId: test()->agent->id,
        );

        return operation_stream_publish_credentials($otherRun)->json('success.data.publisher.token');
    }

    if ($case === 'wrong-channel') {
        return signed_operation_stream_publisher_token([
            'operation_uuid' => test()->run->id,
            'channel' => 'private-operations.wrong-channel',
            'target_node_id' => test()->agent->id,
            'expires_at' => Carbon::now()->addSeconds(120)->timestamp,
        ]);
    }

    return signed_operation_stream_publisher_token([
        'operation_uuid' => test()->run->id,
        'channel' => 'private-operations.'.test()->run->id,
        'target_node_id' => test()->otherAgent->id,
        'expires_at' => Carbon::now()->addSeconds(120)->timestamp,
    ]);
}

final class RecordingOperationStreamFrameBroadcaster extends OperationStreamFrameBroadcaster
{
    /** @var list<array<string, mixed>> */
    public array $frames = [];

    /**
     * @param  array<string, mixed>  $frame
     */
    public function broadcast(string $channel, array $frame): void
    {
        $this->frames[] = $frame;
    }
}

/**
 * @mago-expect lint:single-class-per-file
 */
final class FailingOperationStreamFrameBroadcaster extends OperationStreamFrameBroadcaster
{
    /**
     * @param  array<string, mixed>  $frame
     */
    public function broadcast(string $channel, array $frame): void
    {
        throw new RuntimeException('Reverb publish service unavailable');
    }
}

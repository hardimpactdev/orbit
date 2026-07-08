<?php

declare(strict_types=1);

use App\Models\AppWebSocketBinding;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\OperationStreamSubscriberLease;
use App\Services\Operations\OperationEventRecorder;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const OPERATION_STREAM_GATEWAY_WG_IP = '10.6.0.44';

const OPERATION_STREAM_AGENT_WG_IP = '10.6.0.45';

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-08 12:00:00');

    Config::set('orbit.operations.reverb.app_key', 'gateway-reverb-key');
    Config::set('orbit.operations.reverb.app_secret', operation_stream_reverb_secret());
    Config::set('orbit.operations.reverb.host', 'operations.orbit.test');
    Config::set('orbit.operations.reverb.port', '443');
    Config::set('orbit.operations.reverb.scheme', 'https');
    Config::set('orbit.operations.stream_auth_ttl_seconds', '300');
    Config::set('orbit.operations.publisher_token_ttl_seconds', '120');
    Config::set('orbit.operations.subscriber_lease_ttl_seconds', '60');

    LocalGatewaySettings::current()->fill(['gateway_url' => 'https://gateway.test'])->save();

    $this->gateway = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway',
            'host' => 'gateway',
            'wireguard_address' => OPERATION_STREAM_GATEWAY_WG_IP,
        ]);

    $this->agent = Node::factory()->create([
        'name' => 'agent-1',
        'host' => 'agent-1',
        'wireguard_address' => OPERATION_STREAM_AGENT_WG_IP,
    ]);

    $this->run = app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
        targetNodeId: $this->agent->id,
    );
});

it('returns an operation stream descriptor without app websocket credentials', function (): void {
    $first = app(OperationEventRecorder::class)->step($this->run, 'gateway', 'running');
    $second = app(OperationEventRecorder::class)->step($this->run, 'agent', 'queued');

    AppWebSocketBinding::factory()->create([
        'reverb_app_key' => 'app-facing-key',
        'reverb_app_secret' => operation_stream_app_secret(),
    ]);

    $response = get_operation_stream_descriptor_json($this->run->id);

    $response
        ->assertOk()
        ->assertJsonPath('success.data.operation.uuid', $this->run->id)
        ->assertJsonPath('success.data.operation.operation_id', $this->run->operation_id)
        ->assertJsonPath('success.data.channel.name', "private-operations.{$this->run->id}")
        ->assertJsonPath('success.data.channel.private', true)
        ->assertJsonPath('success.data.reverb.app_key', 'gateway-reverb-key')
        ->assertJsonPath('success.data.reverb.host', 'gateway.test')
        ->assertJsonPath('success.data.reverb.port', 443)
        ->assertJsonPath('success.data.reverb.scheme', 'https')
        ->assertJsonPath('success.data.auth.endpoint', "/api/operations/{$this->run->id}/stream/auth")
        ->assertJsonPath('success.data.auth.method', 'POST')
        ->assertJsonPath('success.data.backfill.events_endpoint', "/api/operations/{$this->run->id}/events")
        ->assertJsonPath('success.data.backfill.cursor', $second->sequence)
        ->assertJsonPath('success.data.backfill.from_sequence', $first->sequence)
        ->assertJsonPath('success.data.backfill.available', 2)
        ->assertJsonMissingPath('success.data.reverb.app_secret')
        ->assertJsonMissingPath('success.data.publisher.token')
        ->assertJsonMissingPath('success.data.reverb_app_secret');
});

it('returns an explicit gateway websocket port from local gateway settings', function (): void {
    LocalGatewaySettings::current()->fill(['gateway_url' => 'http://gateway.test:18080/'])->save();

    get_operation_stream_descriptor_json($this->run->id)
        ->assertOk()
        ->assertJsonPath('success.data.reverb.host', 'gateway.test')
        ->assertJsonPath('success.data.reverb.port', 18080)
        ->assertJsonPath('success.data.reverb.scheme', 'http');
});

it('falls back to the request gateway endpoint when the local gateway url is absent', function (): void {
    LocalGatewaySettings::current()->fill(['gateway_url' => null])->save();

    get_operation_stream_descriptor_json($this->run->id, baseUrl: 'https://request-gateway.test')
        ->assertOk()
        ->assertJsonPath('success.data.reverb.host', 'request-gateway.test')
        ->assertJsonPath('success.data.reverb.port', 443)
        ->assertJsonPath('success.data.reverb.scheme', 'https');
});

it('mints operation scoped publisher credentials for the trusted target agent', function (): void {
    $response = post_operation_publisher_credentials_json($this->run->id);

    $response
        ->assertOk()
        ->assertJsonPath('success.data.operation.uuid', $this->run->id)
        ->assertJsonPath('success.data.channel.name', "private-operations.{$this->run->id}")
        ->assertJsonPath('success.data.publisher.scope.operation_uuid', $this->run->id)
        ->assertJsonPath('success.data.publisher.scope.channel', "private-operations.{$this->run->id}")
        ->assertJsonPath('success.data.publisher.expires_at', Carbon::now()->addSeconds(120)->toIso8601String())
        ->assertJsonMissingPath('success.data.publisher.reverb_app_secret')
        ->assertJsonMissingPath('success.data.publisher.app_websocket_binding_id');

    expect($response->json('success.data.publisher.token'))
        ->toBeString()
        ->not
        ->toBeEmpty()
        ->and(AppWebSocketBinding::query()->count())
        ->toBe(0);
});

it('denies publisher credentials to callers that are not the trusted target agent', function (): void {
    get_operation_stream_descriptor_json($this->run->id);

    $response = $this->call(
        'POST',
        "/api/operations/{$this->run->id}/stream/publisher-credentials",
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => OPERATION_STREAM_GATEWAY_WG_IP,
        ],
    );

    $response
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');
});

it('authorizes only the matching private operation channel and records subscriber leases', function (): void {
    $descriptor = get_operation_stream_descriptor_json($this->run->id);
    $authToken = $descriptor->json('success.data.auth.token');

    $approved = post_operation_stream_auth_json($this->run->id, [
        'socket_id' => '1234.5678',
        'channel_name' => "private-operations.{$this->run->id}",
        'auth_token' => $authToken,
    ]);

    $approved
        ->assertOk()
        ->assertJsonPath(
            'success.data.auth',
            'gateway-reverb-key:'
                .hash_hmac(
                    algo: 'sha256',
                    data: "1234.5678:private-operations.{$this->run->id}",
                    key: operation_stream_reverb_secret(),
                ),
        )
        ->assertJsonPath('success.data.channel', "private-operations.{$this->run->id}")
        ->assertJsonPath('success.data.lease.subscriber', '1234.5678')
        ->assertJsonPath('success.data.lease.active_subscribers', 1)
        ->assertJsonPath('success.data.lease.expires_at', Carbon::now()->addSeconds(60)->toIso8601String());

    expect(OperationStreamSubscriberLease::query()->where('operation_run_id', $this->run->id)->count())->toBe(1);

    post_operation_stream_auth_json($this->run->id, [
        'socket_id' => '8765.4321',
        'channel_name' => "private-operations.{$this->run->id}",
        'auth_token' => $authToken,
    ])->assertJsonPath('success.data.lease.active_subscribers', 2);

    expect(
        OperationStreamSubscriberLease::query()
            ->where('operation_run_id', $this->run->id)
            ->whereNull('left_at')
            ->count(),
    )
        ->toBe(2);

    expect(get_operation_stream_stop_decision_json($this->run->id)->json('success.data.should_stop_tail'))
        ->toBeFalse();

    post_operation_stream_leave_json($this->run->id, '1234.5678')
        ->assertOk()
        ->assertJsonPath('success.data.lease.active_subscribers', 1);

    expect(get_operation_stream_stop_decision_json($this->run->id)->json('success.data.should_stop_tail'))
        ->toBeFalse()
        ->and(
            OperationStreamSubscriberLease::query()
                ->where('operation_run_id', $this->run->id)
                ->whereNull('left_at')
                ->count(),
        )
        ->toBe(1);

    post_operation_stream_leave_json($this->run->id, '8765.4321')
        ->assertOk()
        ->assertJsonPath('success.data.lease.active_subscribers', 0);

    expect(get_operation_stream_stop_decision_json($this->run->id)->json('success.data.should_stop_tail'))
        ->toBeTrue()
        ->and(
            OperationStreamSubscriberLease::query()
                ->where('operation_run_id', $this->run->id)
                ->whereNull('left_at')
                ->count(),
        )
        ->toBe(0);
});

it('denies wrong operation channel and expired auth access', function (): void {
    $descriptor = get_operation_stream_descriptor_json($this->run->id);
    $authToken = $descriptor->json('success.data.auth.token');

    post_operation_stream_auth_json($this->run->id, [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-operations.wrong-operation',
        'auth_token' => $authToken,
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'operation_stream.channel_denied');

    Carbon::setTestNow(Carbon::now()->addSeconds(301));

    post_operation_stream_auth_json($this->run->id, [
        'socket_id' => '1234.5678',
        'channel_name' => "private-operations.{$this->run->id}",
        'auth_token' => $authToken,
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'operation_stream.auth_expired');
});

it('expires subscriber leases before evaluating cancel stop decisions', function (): void {
    $descriptor = get_operation_stream_descriptor_json($this->run->id);

    post_operation_stream_auth_json($this->run->id, [
        'socket_id' => '1234.5678',
        'channel_name' => "private-operations.{$this->run->id}",
        'auth_token' => $descriptor->json('success.data.auth.token'),
    ])->assertOk();

    expect(get_operation_stream_stop_decision_json($this->run->id)->json('success.data.should_stop_tail'))
        ->toBeFalse();

    Carbon::setTestNow(Carbon::now()->addSeconds(61));

    get_operation_stream_stop_decision_json($this->run->id)
        ->assertOk()
        ->assertJsonPath('success.data.active_subscribers', 0)
        ->assertJsonPath('success.data.should_stop_tail', true);

    expect(
        OperationStreamSubscriberLease::query()
            ->where('operation_run_id', $this->run->id)
            ->whereNull('left_at')
            ->count(),
    )
        ->toBe(0);
});

it('keeps a renewed subscriber active past the original lease ttl', function (): void {
    $descriptor = get_operation_stream_descriptor_json($this->run->id);
    $authToken = $descriptor->json('success.data.auth.token');

    post_operation_stream_auth_json($this->run->id, [
        'socket_id' => '1234.5678',
        'channel_name' => "private-operations.{$this->run->id}",
        'auth_token' => $authToken,
    ])->assertOk();

    Carbon::setTestNow(Carbon::now()->addSeconds(30));

    post_operation_stream_auth_json($this->run->id, [
        'socket_id' => '1234.5678',
        'channel_name' => "private-operations.{$this->run->id}",
        'auth_token' => $authToken,
    ])->assertOk();

    Carbon::setTestNow(Carbon::now()->addSeconds(31));

    get_operation_stream_stop_decision_json($this->run->id)
        ->assertOk()
        ->assertJsonPath('success.data.active_subscribers', 1)
        ->assertJsonPath('success.data.should_stop_tail', false);
});

it('registers operation stream control plane routes on the gateway grant middleware group', function (): void {
    expect(Route::getRoutes()->getByName('api.operations.stream.descriptor'))
        ->not->toBeNull()->and(Route::getRoutes()->getByName('api.operations.stream.auth'))
        ->not->toBeNull()->and(Route::getRoutes()->getByName('api.operations.stream.publisherCredentials'))
        ->not->toBeNull()->and(Route::getRoutes()->getByName('api.operations.stream.leave'))
        ->not->toBeNull()->and(Route::getRoutes()->getByName('api.operations.stream.stopDecision'))
        ->not->toBeNull();
});

function operation_stream_app_secret(): string
{
    return implode('-', ['app-facing', 'secret']);
}

function operation_stream_reverb_secret(): string
{
    return implode('-', ['gateway-reverb', 'secret']);
}

/**
 * @param  array<string, string>  $server
 */
function get_operation_stream_descriptor_json(
    string $operationRunId,
    array $server = [],
    ?string $baseUrl = null,
): TestResponse {
    $uri = "/api/operations/{$operationRunId}/stream";

    if (is_string($baseUrl) && $baseUrl !== '') {
        $uri = rtrim($baseUrl, characters: '/').$uri;
    }

    return test()->call(
        'GET',
        $uri,
        [],
        [],
        [],
        array_merge([
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => OPERATION_STREAM_GATEWAY_WG_IP,
        ], $server),
    );
}

function post_operation_publisher_credentials_json(string $operationRunId): TestResponse
{
    return test()->call(
        'POST',
        "/api/operations/{$operationRunId}/stream/publisher-credentials",
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => OPERATION_STREAM_AGENT_WG_IP,
        ],
    );
}

/**
 * @param  array<string, string|null>  $data
 */
function post_operation_stream_auth_json(string $operationRunId, array $data): TestResponse
{
    return test()->call(
        'POST',
        "/api/operations/{$operationRunId}/stream/auth",
        $data,
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => OPERATION_STREAM_GATEWAY_WG_IP,
        ],
    );
}

function post_operation_stream_leave_json(string $operationRunId, string $socketId): TestResponse
{
    return test()->call(
        'POST',
        "/api/operations/{$operationRunId}/stream/leave",
        [
            'socket_id' => $socketId,
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => OPERATION_STREAM_GATEWAY_WG_IP,
        ],
    );
}

function get_operation_stream_stop_decision_json(string $operationRunId): TestResponse
{
    return test()->call(
        'GET',
        "/api/operations/{$operationRunId}/stream/stop-decision",
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => OPERATION_STREAM_GATEWAY_WG_IP,
        ],
    );
}

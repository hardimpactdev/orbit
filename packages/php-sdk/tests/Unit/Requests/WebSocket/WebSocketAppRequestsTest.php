<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\WebSocket\AddInstanceWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\CreateWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\DestroyWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\ListWebSocketAppsRequest;
use Orbit\Sdk\Requests\WebSocket\RemoveInstanceWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\RotateWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\ShowWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\ShowWebSocketOperationRequest;
use Orbit\Sdk\Requests\WebSocket\SyncWebSocketAppsRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppAttachmentResponse;
use Orbit\Sdk\Responses\WebSocket\WebSocketApplyResponse;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppResponse;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppsResponse;
use Orbit\Sdk\Responses\WebSocket\WebSocketChangeResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @return array<string, mixed> */
function websocket_app_sdk_payload(array $overrides = []): array
{
    return [
        'id' => 2,
        'name' => 'hauzer',
        'app_id' => 'hauzerappid000000001',
        'key' => 'hauzerappkey00000001',
        'instance_ids' => [7],
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function websocket_attachment_sdk_payload(array $overrides = []): array
{
    return [
        'instance_id' => 7,
        'name' => 'hauzer',
        'keys' => ['BROADCAST_CONNECTION', 'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME'],
        'operation' => 'attach',
        'changed' => true,
        'key_count' => 7,
        ...$overrides,
    ];
}

describe('websocket app requests', function (): void {
    it('uses the app and attachment methods and endpoints', function (GatewayRequest $request, Method $method, string $endpoint): void {
        expect($request->getMethod())->toBe($method)
            ->and($request->resolveEndpoint())->toBe($endpoint);
    })->with([
        'rotate' => [new RotateWebSocketAppRequest('alpha'), Method::POST, '/api/v1/websocket-apps/alpha/rotate'],
        'sync' => [new SyncWebSocketAppsRequest, Method::POST, '/api/v1/websocket-sync'],
        'operation' => [new ShowWebSocketOperationRequest('operation-id'), Method::GET, '/api/v1/websocket-operations/operation-id'],
        'list' => [new ListWebSocketAppsRequest, Method::GET, '/api/v1/websocket-apps'],
        'show' => [new ShowWebSocketAppRequest('hauzer'), Method::GET, '/api/v1/websocket-apps/hauzer'],
        'create' => [new CreateWebSocketAppRequest('hauzer'), Method::POST, '/api/v1/websocket-apps'],
        'destroy' => [new DestroyWebSocketAppRequest('hauzer'), Method::DELETE, '/api/v1/websocket-apps/hauzer'],
        'add by ID' => [new AddInstanceWebSocketAppRequest(7, 'hauzer'), Method::PUT, '/api/v1/instances/7/websocket-apps/hauzer'],
        'remove by domain' => [new RemoveInstanceWebSocketAppRequest('hauzer.test', 'hauzer'), Method::DELETE, '/api/v1/instances/hauzer.test/websocket-apps/hauzer'],
    ]);

    it('sends only the name on create and no body on attach and detach', function (): void {
        expect(new CreateWebSocketAppRequest('hauzer')->body()->all())->toBe(['name' => 'hauzer'])
            ->and(new AddInstanceWebSocketAppRequest(7, 'hauzer'))->not->toBeInstanceOf(HasBody::class)
            ->and(new RemoveInstanceWebSocketAppRequest(7, 'hauzer'))->not->toBeInstanceOf(HasBody::class);
    });

    it('encodes selectors as single path segments without applying Gateway validation', function (GatewayRequest $request, string $endpoint): void {
        expect($request->resolveEndpoint())->toBe($endpoint);
    })->with([
        'show' => [new ShowWebSocketAppRequest('app/name'), '/api/v1/websocket-apps/app%2Fname'],
        'destroy' => [new DestroyWebSocketAppRequest('app/name'), '/api/v1/websocket-apps/app%2Fname'],
        'add' => [new AddInstanceWebSocketAppRequest('route/name', 'app/name'), '/api/v1/instances/route%2Fname/websocket-apps/app%2Fname'],
        'remove' => [new RemoveInstanceWebSocketAppRequest('route/name', 'app/name'), '/api/v1/instances/route%2Fname/websocket-apps/app%2Fname'],
    ]);

    it('maps mutation responses without retaining secret or environment values', function (GatewayRequest $request, array $payload, string $responseClass, int $status): void {
        $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $secret = 'websocket-sdk-secret-sentinel-7f9b3';
        $connector = new GatewayConnector('https://10.44.0.1', '/tmp/orbit-ca.pem');
        $connector->withMockClient(new MockClient([
            $request::class => MockResponse::make([
                'data' => [...$payload, 'secret' => $secret, 'values' => ['REVERB_APP_SECRET' => $secret]],
                'meta' => ['request_id' => $requestId],
                'operation' => websocket_apply_sdk_payload(),
            ], $status),
        ]));

        $result = $connector->send($request)->dto();

        expect($result)->toBeInstanceOf($responseClass)
            ->and($result instanceof WebSocketChangeResponse ? $result->app?->toArray() : $result->toArray())->toBe([...$payload, 'request_id' => $requestId])
            ->and(print_r($result, true))->not->toContain($secret)
            ->and(serialize($result))->not->toContain($secret);
    })->with([
        'create' => [new CreateWebSocketAppRequest('hauzer'), websocket_app_sdk_payload(), WebSocketChangeResponse::class, 201],
        'destroy' => [new DestroyWebSocketAppRequest('hauzer'), websocket_app_sdk_payload(['instance_ids' => []]), WebSocketChangeResponse::class, 200],
        'remove' => [new RemoveInstanceWebSocketAppRequest('hauzer.test', 'hauzer'), websocket_attachment_sdk_payload(['operation' => 'detach', 'key_count' => 0]), WebSocketAppAttachmentResponse::class, 200],
    ]);

    it('maps an empty app collection', function (): void {
        $connector = new GatewayConnector('https://10.44.0.1', '/tmp/orbit-ca.pem');
        $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $connector->withMockClient(new MockClient([
            ListWebSocketAppsRequest::class => MockResponse::make(['data' => [], 'meta' => ['request_id' => $requestId]]),
        ]));

        expect($connector->send(new ListWebSocketAppsRequest)->dto()->toArray())
            ->toBe(['apps' => [], 'request_id' => $requestId]);
    });

    it('preserves structured Gateway refusals', function (GatewayRequest $request, string $code, int $status): void {
        $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $connector = new GatewayConnector('https://10.44.0.1', '/tmp/orbit-ca.pem');
        $connector->withMockClient(new MockClient([
            $request::class => MockResponse::make([
                'error' => ['code' => $code, 'message' => 'App request refused.'],
            ], $status, ['X-Orbit-Request-Id' => $requestId]),
        ]));

        expect(fn () => $connector->send($request))->toThrow(function (GatewayApiException $exception) use ($code, $requestId): void {
            expect($exception->errorCode())->toBe($code)
                ->and($exception->requestId())->toBe($requestId)
                ->and($exception->getMessage())->toBe('App request refused.');
        });
    })->with([
        'reserved app' => [new AddInstanceWebSocketAppRequest(7, 'gateway'), 'websocket.app_reserved', 409],
        'app in use' => [new DestroyWebSocketAppRequest('hauzer'), 'websocket.app_in_use', 409],
        'missing attachment' => [new RemoveInstanceWebSocketAppRequest(7, 'hauzer'), 'websocket.attachment_missing', 404],
    ]);

    it('maps an app, a list of apps, and an attachment', function (): void {
        $meta = ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'];
        $connector = new GatewayConnector('https://10.44.0.1', '/tmp/orbit-ca.pem');
        $connector->withMockClient(new MockClient([
            ShowWebSocketAppRequest::class => MockResponse::make(['data' => websocket_app_sdk_payload(), 'meta' => $meta]),
            ListWebSocketAppsRequest::class => MockResponse::make(['data' => [websocket_app_sdk_payload()], 'meta' => $meta]),
            AddInstanceWebSocketAppRequest::class => MockResponse::make(['data' => websocket_attachment_sdk_payload(), 'meta' => $meta]),
        ]));

        $app = $connector->send(new ShowWebSocketAppRequest('hauzer'))->dto();
        $apps = $connector->send(new ListWebSocketAppsRequest)->dto();
        $attachment = $connector->send(new AddInstanceWebSocketAppRequest(7, 'hauzer'))->dto();

        expect($app)->toBeInstanceOf(WebSocketAppResponse::class)
            ->and($app->toArray())->toBe([...websocket_app_sdk_payload(), 'request_id' => $meta['request_id']])
            ->and($apps)->toBeInstanceOf(WebSocketAppsResponse::class)
            ->and($apps->toArray())->toBe(['apps' => [websocket_app_sdk_payload()], 'request_id' => $meta['request_id']])
            ->and($attachment)->toBeInstanceOf(WebSocketAppAttachmentResponse::class)
            ->and($attachment->toArray())->toBe([...websocket_attachment_sdk_payload(), 'request_id' => $meta['request_id']]);
    });

    it('rejects a malformed app', function (array $payload): void {
        expect(fn () => WebSocketAppResponse::fromGatewayData($payload, 'request-1'))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'bad id' => [websocket_app_sdk_payload(['id' => 0])],
        'bad name' => [websocket_app_sdk_payload(['name' => 'Hauzer'])],
        'empty app id' => [websocket_app_sdk_payload(['app_id' => ''])],
        'long key' => [websocket_app_sdk_payload(['key' => str_repeat('k', 65)])],
        'bad instance id' => [websocket_app_sdk_payload(['instance_ids' => [0]])],
        'instance map' => [websocket_app_sdk_payload(['instance_ids' => ['a' => 7]])],
    ]);

    it('rejects a malformed attachment', function (array $payload): void {
        expect(fn () => WebSocketAppAttachmentResponse::fromGatewayData($payload, 'request-1'))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'bad instance' => [websocket_attachment_sdk_payload(['instance_id' => 0])],
        'bad operation' => [websocket_attachment_sdk_payload(['operation' => 'replace'])],
        'bad key' => [websocket_attachment_sdk_payload(['keys' => ['REVERB APP']])],
        'negative count' => [websocket_attachment_sdk_payload(['key_count' => -1])],
        'missing changed' => [websocket_attachment_sdk_payload(['changed' => null])],
    ]);
});

function websocket_apply_sdk_payload(): array
{
    return ['id' => '11111111-1111-4111-8111-111111111111', 'epoch' => '22222222-2222-4222-8222-222222222222',
        'revision' => 2, 'state' => 'pending', 'processes' => [], 'missing_node_ids' => []];
}

it('preserves explicit operation keys across a transport retry', function (): void {
    $request = new CreateWebSocketAppRequest('alpha', 'retry-key');
    expect($request->headers()->get('Idempotency-Key'))->toBe('retry-key')
        ->and($request->headers()->get('Idempotency-Key'))->toBe('retry-key');
});

it('maps pending applied and superseded operations without secret extras', function (string $state): void {
    $secret = 'registry-secret-extra-9f7e';
    $transport = [...websocket_apply_sdk_payload(), 'state' => $state, 'secret' => $secret, 'status_url' => 'https://attacker.test',
        'processes' => [['id' => '33333333-3333-4333-8333-333333333333', 'node_id' => 2, 'boot_id' => null, 'applied_revision' => null, 'state' => 'stale', 'error' => 'registry.unreachable', 'secret' => $secret]]];
    $dto = WebSocketApplyResponse::fromTransport($transport);
    expect($dto->processes[0]['error'])->toBe('registry.unreachable');
    expect($dto->state)->toBe($state)->and(serialize($dto))->not->toContain($secret)
        ->and($dto->toArray()['status_url'])->toBe('/api/v1/websocket-operations/'.$dto->id);
})->with(['pending', 'applied', 'superseded']);

it('rejects malformed apply reports at the SDK boundary', function (array $overrides): void {
    expect(fn () => WebSocketApplyResponse::fromTransport([...websocket_apply_sdk_payload(), ...$overrides]))->toThrow(InvalidArgumentException::class);
})->with([
    [['revision' => '2']], [['state' => 'saved']], [['id' => 'secret-invalid']], [['missing_node_ids' => [0]]],
    [['processes' => [['id' => '33333333-3333-4333-8333-333333333333', 'node_id' => 2, 'state' => 'applied']]]],
    [['processes' => [['id' => '33333333-3333-4333-8333-333333333333', 'node_id' => 2, 'boot_id' => null, 'applied_revision' => null, 'state' => 'stale', 'error' => 'credential-sentinel']]]],
]);

it('preserves separate Instance rollout status in a rotation response', function (): void {
    $connector = new GatewayConnector('https://10.44.0.1', '/tmp/orbit-ca.pem');
    $request = new RotateWebSocketAppRequest('hauzer', 'rotate-once');
    $connector->withMockClient(new MockClient([RotateWebSocketAppRequest::class => MockResponse::make([
        'data' => [...websocket_app_sdk_payload(), 'instance_rollout' => ['state' => 'configuration_stored', 'instance_ids' => [7], 'requires_env_sync' => true, 'requires_reload' => true]],
        'operation' => websocket_apply_sdk_payload(), 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 202)]));
    $dto = $connector->send($request)->dto();
    expect($request->headers()->get('Idempotency-Key'))->toBe('rotate-once')
        ->and($request)->not->toBeInstanceOf(HasBody::class)
        ->and($dto->instanceRolloutIds)->toBe([7])->and($dto->operation->state)->toBe('pending');
});

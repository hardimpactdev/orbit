<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\WebSocket\AddInstanceWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\CreateWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\DestroyWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\ListWebSocketAppsRequest;
use Orbit\Sdk\Requests\WebSocket\RemoveInstanceWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\ShowWebSocketAppRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppAttachmentResponse;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppResponse;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppsResponse;
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

<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AiRouter\DisableAiRouterAccountRequest;
use Orbit\Sdk\Requests\AiRouter\EnableAiRouterAccountRequest;
use Orbit\Sdk\Requests\AiRouter\ListAiRouterModelsRequest;
use Orbit\Sdk\Requests\AiRouter\ListAiRouterProvidersRequest;
use Orbit\Sdk\Requests\AiRouter\SetupAiRouterRequest;
use Orbit\Sdk\Requests\AiRouter\ShowAiRouterProviderRequest;
use Orbit\Sdk\Requests\AiRouter\ShowAiRouterStatusRequest;
use Orbit\Sdk\Requests\AiRouter\TeardownAiRouterRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('exposes ai-router read and account routes', function (string $class, Method $method, string $path): void {
    $request = match ($class) {
        SetupAiRouterRequest::class => new $class(7, 'valkey', 'http://127.0.0.1:8317', 'management-key'),
        ShowAiRouterProviderRequest::class => new $class('codex'),
        DisableAiRouterAccountRequest::class => new $class('plus.json'),
        default => new $class,
    };

    expect($request->getMethod())->toBe($method)->and($request->resolveEndpoint())->toBe($path);
})->with([
    [SetupAiRouterRequest::class, Method::POST, '/api/v1/ai-router'],
    [TeardownAiRouterRequest::class, Method::DELETE, '/api/v1/ai-router'],
    [ShowAiRouterStatusRequest::class, Method::GET, '/api/v1/ai-router'],
    [ListAiRouterProvidersRequest::class, Method::GET, '/api/v1/ai-router/providers'],
    [ListAiRouterModelsRequest::class, Method::GET, '/api/v1/ai-router/models'],
    [ShowAiRouterProviderRequest::class, Method::GET, '/api/v1/ai-router/providers/codex'],
    [DisableAiRouterAccountRequest::class, Method::POST, '/api/v1/ai-router/accounts/plus.json/disable'],
]);

it('sends setup and account payloads without extra keys', function (): void {
    expect(new SetupAiRouterRequest(7, 'valkey', 'http://127.0.0.1:8317', 'management-key')->body()->all())
        ->toBe([
            'node_id' => 7,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ]);
});

it('returns admitted Operations from both bodyless account actions', function (string $class): void {
    $connector = new GatewayConnector('https://gateway.orbit', '/test/ca.pem');
    $connector->withMockClient(new MockClient([
        $class => MockResponse::make(['data' => [
            'id' => 71, 'kind' => 'ai_router.account_update', 'target_type' => 'ai_router', 'target_id' => 17,
            'status' => 'queued', 'sequence' => 1, 'input' => ['account' => 'auth-index-42', 'disabled' => $class === DisableAiRouterAccountRequest::class],
        ], 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111']], 202),
    ]));
    $response = $connector->send(new $class('auth-index-42'))->dtoOrFail();
    expect($response)->toBeInstanceOf(OperationResponse::class)
        ->and($response->kind)->toBe('ai_router.account_update')
        ->and($response->input['account'])->toBe('auth-index-42');
})->with([EnableAiRouterAccountRequest::class, DisableAiRouterAccountRequest::class]);

<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\AiRouter\AiRouterAccountResponse;
use Orbit\Sdk\Responses\AiRouter\AiRouterModelResponse;
use Orbit\Sdk\Responses\AiRouter\AiRouterModelsResponse;
use Orbit\Sdk\Responses\AiRouter\AiRouterProviderResponse;
use Orbit\Sdk\Responses\AiRouter\AiRouterStatusResponse;

it('maps a fleet status without tokens or keys', function (): void {
    $response = AiRouterStatusResponse::fromGatewayData([
        'enabled' => true,
        'cutover_pending' => true,
        'hostname' => 'collector.ai-router.orbit',
        'node_id' => 7,
        'cache_connection' => 'valkey',
        'collected_at' => '2026-09-20T12:00:00Z',
        'read_token' => 'should-not-be-kept',
        'cliproxy_management_key' => 'secret',
    ], 'req');

    expect($response->toArray())->toBe([
        'enabled' => true,
        'cutover_pending' => true,
        'hostname' => 'collector.ai-router.orbit',
        'node_id' => 7,
        'cache_connection' => 'valkey',
        'collected_at' => '2026-09-20T12:00:00Z',
        'request_id' => 'req',
    ])->and(serialize($response))->not->toContain('secret');
});

it('maps provider windows in the order the Gateway sent them', function (): void {
    $response = AiRouterProviderResponse::fromGatewayData([
        'provider' => 'codex',
        'windows' => [
            ['label' => '7d', 'used_percent' => 40.0, 'remaining_percent' => 60.0, 'resets_at' => '2026-09-27T00:00:00Z'],
            ['label' => '5h', 'used_percent' => 10.0, 'remaining_percent' => 90.0, 'resets_at' => null],
        ],
        'accounts' => [[
            'id' => 'plus.json',
            'provider' => 'codex',
            'label' => 'plus',
            'disabled' => false,
            'status' => 'ok',
            'windows' => [
                ['label' => '7d', 'used_percent' => 40.0, 'remaining_percent' => 60.0, 'resets_at' => '2026-09-27T00:00:00Z'],
            ],
            'error' => null,
        ]],
    ], 'req');

    expect($response->windows[0]->label)->toBe('7d')
        ->and($response->windows[1]->label)->toBe('5h')
        ->and($response->accounts[0]->id)->toBe('plus.json')
        ->and($response->toArray()['windows'])->not->toHaveKey('primary');
});

it('rejects a missing window shown as an invented zero row', function (): void {
    expect(fn () => AiRouterAccountResponse::fromGatewayData([
        'id' => 'plus.json',
        'provider' => 'codex',
        'label' => 'plus',
        'disabled' => false,
        'status' => null,
        'windows' => [['used_percent' => 0, 'remaining_percent' => 100]],
        'error' => null,
    ], 'req'))->toThrow(GatewayApiException::class, 'invalid ai-router window');
});

it('maps a model list and drops fields the collector does not store', function (): void {
    $response = new AiRouterModelsResponse([
        AiRouterModelResponse::fromGatewayData([
            'id' => 'gpt-5.6-luna',
            'provider' => 'codex',
            'display_name' => 'Luna',
            'type' => 'chat',
        ], 'req'),
    ], 'req');

    expect($response->toArray())->toBe([
        'models' => [
            ['id' => 'gpt-5.6-luna', 'provider' => 'codex'],
        ],
        'request_id' => 'req',
    ]);
});

it('rejects a model that has no provider', function (): void {
    expect(fn () => AiRouterModelResponse::fromGatewayData([
        'id' => 'gpt-5.6-luna',
    ], 'req'))->toThrow(GatewayApiException::class, 'invalid ai-router model');
});

it('rejects an incomplete status payload', function (): void {
    expect(fn () => AiRouterStatusResponse::fromGatewayData([
        'enabled' => true,
    ], 'req'))->toThrow(GatewayApiException::class, 'invalid ai-router status');
});

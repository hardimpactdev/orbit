<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\UpdateNodeSettingsRequest;
use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\NodeSettingsMutationResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('returns the admitted Operation and preserves the settings idempotency key', function (): void {
    $request = new UpdateNodeSettingsRequest(2, true, new AppsSettings('/srv/apps'), idempotencyKey: 'settings-proof');
    $mock = new MockClient([UpdateNodeSettingsRequest::class => MockResponse::make([
        'data' => ['id' => 7, 'kind' => 'node.settings', 'target_type' => 'node', 'target_id' => 2,
            'status' => 'queued', 'sequence' => 1, 'input' => [], 'steps' => []],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);

    $response = $connector->send($request)->dto();

    expect($response)->toBeInstanceOf(NodeSettingsMutationResponse::class);
    expect($response->result)->toBeInstanceOf(OperationResponse::class);
    expect($response->result->kind)->toBe('node.settings');
    $mock->assertSent(fn ($pending): bool => $pending->headers()->get('Idempotency-Key') === 'settings-proof'
        && $pending->body()->all() === ['apps' => ['path' => '/srv/apps']]);
});

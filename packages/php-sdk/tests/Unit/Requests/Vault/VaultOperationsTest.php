<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Vault\SetupVaultRequest;
use Orbit\Sdk\Requests\Vault\SyncVaultRequest;
use Orbit\Sdk\Requests\Vault\TeardownVaultRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('returns the admitted Vault Operation and preserves the retry key without exposing credentials', function (string $action): void {
    $request = match ($action) {
        'setup' => new SetupVaultRequest('https://vault.orbit', 'client', 'client-secret-sentinel', 'password-sentinel', 'Orbit', 'Database', 'vault-retry'),
        'sync' => new SyncVaultRequest('vault-retry'),
        'teardown' => new TeardownVaultRequest(false, 'vault-retry'),
    };
    $mock = new MockClient([$request::class => MockResponse::make([
        'data' => ['id' => 12, 'kind' => 'vault.'.$action, 'target_type' => 'vault', 'target_id' => 7, 'input' => [], 'status' => 'queued', 'sequence' => 1],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);

    $response = $connector->send($request)->dto();

    expect($response)->toBeInstanceOf(OperationResponse::class)->and($response->kind)->toBe('vault.'.$action)->and($response->status)->toBe('queued')
        ->and($mock->getLastPendingRequest()?->headers()->get('Idempotency-Key'))->toBe('vault-retry');
    expect(print_r($request, true))->not->toContain('client-secret-sentinel', 'password-sentinel');
})->with(['setup', 'sync', 'teardown']);

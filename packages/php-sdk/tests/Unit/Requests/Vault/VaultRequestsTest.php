<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Vault\SetupVaultRequest;
use Orbit\Sdk\Requests\Vault\ShowVaultStatusRequest;
use Orbit\Sdk\Requests\Vault\SyncVaultRequest;
use Orbit\Sdk\Requests\Vault\TeardownVaultRequest;
use Orbit\Sdk\Responses\Vault\VaultStatusResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @return array<string, mixed> */
function vault_status_payload(bool $configured = true): array
{
    return [
        'data' => $configured ? [
            'configured' => true,
            'url' => 'https://vault.orbit',
            'email' => 'gateway@orbit.test',
            'organization' => ['id' => 'org-1', 'name' => 'Orbit'],
            'collection' => ['id' => 'col-1', 'name' => 'Databases'],
            'items' => 3,
            'pending' => 1,
            'skipped' => [
                ['source' => 'database-user:4', 'slug' => 'shop', 'username' => 'reader', 'reason' => 'password_unknown'],
            ],
            'last_synced_at' => '2026-10-11T12:00:00Z',
            'last_error' => null,
        ] : [
            'configured' => false,
            'url' => null,
            'email' => null,
            'organization' => null,
            'collection' => null,
            'items' => 0,
            'pending' => 0,
            'skipped' => [],
            'last_synced_at' => null,
            'last_error' => null,
        ],
        'meta' => ['request_id' => node_role_request_id()],
    ];
}

function vault_setup_request(string $clientSecret = 'client-secret', string $masterPassword = 'master-password'): SetupVaultRequest
{
    return new SetupVaultRequest('https://vault.orbit', 'user.3f0c', $clientSecret, $masterPassword, 'Orbit', 'Databases');
}

describe('vault requests', function (): void {
    it('exposes the vault routes', function (string $class, Method $method, string $endpoint): void {
        $request = $class === SetupVaultRequest::class ? vault_setup_request() : new $class;

        expect($request->getMethod())->toBe($method)->and($request->resolveEndpoint())->toBe($endpoint);
    })->with([
        'status' => [ShowVaultStatusRequest::class, Method::GET, '/api/v1/vault'],
        'setup' => [SetupVaultRequest::class, Method::PUT, '/api/v1/vault'],
        'sync' => [SyncVaultRequest::class, Method::POST, '/api/v1/vault/sync'],
        'teardown' => [TeardownVaultRequest::class, Method::DELETE, '/api/v1/vault'],
    ]);

    it('sends the setup body and hides the secrets from debug output', function (): void {
        $clientSecret = 'vault-client-secret-sentinel';
        $masterPassword = 'vault-master-password-sentinel';
        $mockClient = new MockClient([
            SetupVaultRequest::class => MockResponse::make(vault_status_payload()),
        ]);
        $request = vault_setup_request($clientSecret, $masterPassword);

        $response = node_role_gateway_connector($mockClient)->send($request)->dto();

        expect($mockClient->getLastPendingRequest()?->body()->all())->toBe([
            'url' => 'https://vault.orbit',
            'client_id' => 'user.3f0c',
            'client_secret' => $clientSecret,
            'master_password' => $masterPassword,
            'organization' => 'Orbit',
            'collection' => 'Databases',
        ])
            ->and($response)->toBeInstanceOf(VaultStatusResponse::class)
            ->and($response->configured)->toBeTrue()
            ->and(print_r($request, true))->not->toContain($clientSecret)->not->toContain($masterPassword)
            ->and(fn () => serialize($request))->toThrow(LogicException::class);
    });

    it('sends keep_items on teardown, false by default', function (): void {
        expect((new TeardownVaultRequest)->body()->all())->toBe(['keep_items' => false])
            ->and((new TeardownVaultRequest(true))->body()->all())->toBe(['keep_items' => true]);
    });

    it('keeps status and sync bodyless', function (string $class): void {
        expect(new $class)->not->toBeInstanceOf(HasBody::class);
    })->with([ShowVaultStatusRequest::class, SyncVaultRequest::class]);

    it('returns the status from status, sync, and teardown', function (string $class, bool $configured): void {
        $mockClient = new MockClient([
            $class => MockResponse::make(vault_status_payload($configured)),
        ]);

        $response = node_role_gateway_connector($mockClient)->send(new $class)->dto();

        expect($response)->toBeInstanceOf(VaultStatusResponse::class)
            ->and($response->configured)->toBe($configured)
            ->and($response->requestId)->toBe(node_role_request_id());
    })->with([
        [ShowVaultStatusRequest::class, true],
        [SyncVaultRequest::class, true],
        [TeardownVaultRequest::class, false],
    ]);

    it('preserves a Gateway vault error', function (): void {
        $mockClient = new MockClient([
            SyncVaultRequest::class => MockResponse::make([
                'status' => 409,
                'error' => ['code' => 'vault.not_configured', 'message' => 'Vaultwarden is not set up.', 'details' => []],
                'meta' => ['request_id' => node_role_request_id()],
            ], 409),
        ]);

        try {
            node_role_gateway_connector($mockClient)->send(new SyncVaultRequest);
            throw new RuntimeException('The refusal did not throw.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())->toBe('vault.not_configured')
                ->and($exception->getMessage())->toBe('Vaultwarden is not set up.');
        }
    });
});

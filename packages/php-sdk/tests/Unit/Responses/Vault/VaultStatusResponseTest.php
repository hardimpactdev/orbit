<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Vault\VaultStatusResponse;

/** @return array<string, mixed> */
function vault_status_data(array $overrides = []): array
{
    return array_replace([
        'configured' => true,
        'url' => 'https://vault.orbit',
        'email' => 'gateway@orbit.test',
        'organization' => ['id' => 'org-1', 'name' => 'Orbit'],
        'collection' => ['id' => 'col-1', 'name' => 'Databases'],
        'items' => 3,
        'pending' => 1,
        'skipped' => [
            ['source' => 'database-connection:2', 'slug' => 'legacy', 'username' => null, 'reason' => 'incomplete'],
            ['source' => 'database-user:4', 'slug' => 'shop', 'username' => 'reader', 'reason' => 'password_unknown'],
        ],
        'last_synced_at' => '2026-10-11T12:00:00Z',
        'last_error' => ['code' => 'vault.unreachable', 'message' => 'Vaultwarden did not answer.'],
    ], $overrides);
}

describe('vault status response', function (): void {
    it('maps the status and drops fields the contract does not define', function (): void {
        $response = VaultStatusResponse::fromGatewayData(vault_status_data([
            'client_secret' => 'vault-secret-sentinel',
            'organization' => ['id' => 'org-1', 'name' => 'Orbit', 'key' => 'vault-secret-sentinel'],
        ]), 'req');

        expect($response->toArray())->toBe([
            'configured' => true,
            'url' => 'https://vault.orbit',
            'email' => 'gateway@orbit.test',
            'organization' => ['id' => 'org-1', 'name' => 'Orbit'],
            'collection' => ['id' => 'col-1', 'name' => 'Databases'],
            'items' => 3,
            'pending' => 1,
            'skipped' => [
                ['source' => 'database-connection:2', 'slug' => 'legacy', 'username' => null, 'reason' => 'incomplete'],
                ['source' => 'database-user:4', 'slug' => 'shop', 'username' => 'reader', 'reason' => 'password_unknown'],
            ],
            'last_synced_at' => '2026-10-11T12:00:00Z',
            'last_error' => ['code' => 'vault.unreachable', 'message' => 'Vaultwarden did not answer.'],
            'request_id' => 'req',
        ])->and(serialize($response))->not->toContain('vault-secret-sentinel');
    });

    it('maps a status that is not set up', function (): void {
        $response = VaultStatusResponse::fromGatewayData([
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
        ], 'req');

        expect($response->configured)->toBeFalse()
            ->and($response->organization)->toBeNull()
            ->and($response->skipped)->toBe([])
            ->and($response->lastError)->toBeNull();
    });

    it('rejects an invalid status', function (array $overrides): void {
        expect(fn () => VaultStatusResponse::fromGatewayData(vault_status_data($overrides), 'req'))
            ->toThrow(GatewayApiException::class, 'invalid vault status');
    })->with([
        'configured missing' => [['configured' => null]],
        'configured not bool' => [['configured' => 'yes']],
        'url empty' => [['url' => '']],
        'email not string' => [['email' => 5]],
        'organization without name' => [['organization' => ['id' => 'org-1']]],
        'collection not object' => [['collection' => 'Databases']],
        'items negative' => [['items' => -1]],
        'pending not int' => [['pending' => '1']],
        'skipped missing' => [['skipped' => null]],
        'skipped not list' => [['skipped' => ['a' => ['source' => 'x', 'slug' => 'y', 'username' => null, 'reason' => 'incomplete']]]],
        'skipped unknown reason' => [['skipped' => [['source' => 'x', 'slug' => 'y', 'username' => null, 'reason' => 'other']]]],
        'skipped without slug' => [['skipped' => [['source' => 'x', 'username' => null, 'reason' => 'incomplete']]]],
        'last error without code' => [['last_error' => ['message' => 'failed']]],
        'last synced not string' => [['last_synced_at' => 12]],
    ]);
});

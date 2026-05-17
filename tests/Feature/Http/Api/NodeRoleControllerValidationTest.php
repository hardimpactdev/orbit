<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const NODE_ROLE_API_CALLER_WG_IP = '10.6.0.90';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiNodeRoleRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.7',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createNodeRoleApiCaller(string $role = 'control'): int
{
    return (int) DB::table('nodes')->insertGetId(apiNodeRoleRow([
        'name' => "{$role}-caller",
        'role' => $role,
        'host' => NODE_ROLE_API_CALLER_WG_IP,
        'environment' => $role === 'app' ? 'development' : null,
        'wireguard_address' => NODE_ROLE_API_CALLER_WG_IP,
    ]));
}

function createNodeRoleApiGateway(): int
{
    return (int) DB::table('nodes')->insertGetId(apiNodeRoleRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'host' => '10.6.0.2',
        'environment' => null,
        'wireguard_address' => '10.6.0.2',
    ]));
}

function grantNodeRoleApiGatewayAccess(int $callerId, int $gatewayId): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $callerId,
        'serving_node_id' => $gatewayId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function postNodeRoleApiJson(string $uri, array $data, array $server = []): TestResponse
{
    /** @phpstan-ignore-next-line Pest resolves call() on the bound Laravel test case at runtime. */
    return test()->call(
        'POST',
        $uri,
        $data,
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server),
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function patchNodeRoleApiJson(string $uri, array $data, array $server = []): TestResponse
{
    /** @phpstan-ignore-next-line Pest resolves call() on the bound Laravel test case at runtime. */
    return test()->call(
        'PATCH',
        $uri,
        $data,
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server),
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function deleteNodeRoleApiJson(string $uri, array $data, array $server = []): TestResponse
{
    /** @phpstan-ignore-next-line Pest resolves call() on the bound Laravel test case at runtime. */
    return test()->call(
        'DELETE',
        $uri,
        $data,
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server),
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

beforeEach(function (): void {
    $callerId = createNodeRoleApiCaller();
    $gatewayId = createNodeRoleApiGateway();
    grantNodeRoleApiGatewayAccess($callerId, $gatewayId);

    DB::table('nodes')->insert(apiNodeRoleRow([
        'name' => 'target-1',
        'wireguard_address' => '10.6.0.20',
    ]));
});

describe('node role api validation envelopes', function (): void {
    it('returns the orbit error envelope for missing role', function (): void {
        $response = postNodeRoleApiJson('/api/nodes/target-1/roles', [], ['REMOTE_ADDR' => NODE_ROLE_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'role')
            ->assertJsonPath('error.message', 'Role is required.')
            ->assertJsonMissingPath('success');
    });

    it('returns the orbit error envelope for non-array settings on add', function (): void {
        $response = postNodeRoleApiJson('/api/nodes/target-1/roles', [
            'role' => 'database',
            'settings' => 'invalid',
        ], ['REMOTE_ADDR' => NODE_ROLE_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'settings')
            ->assertJsonPath('error.message', 'Settings must be an object.')
            ->assertJsonMissingPath('success');
    });

    it('returns the orbit error envelope for non-array settings on update', function (): void {
        $response = patchNodeRoleApiJson('/api/nodes/target-1/roles/database', [
            'settings' => 'invalid',
        ], ['REMOTE_ADDR' => NODE_ROLE_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'settings')
            ->assertJsonPath('error.message', 'Settings must be an object.')
            ->assertJsonMissingPath('success');
    });

    it('returns the orbit error envelope for invalid force on remove', function (): void {
        $response = deleteNodeRoleApiJson('/api/nodes/target-1/roles/database', [
            'force' => 'invalid',
        ], ['REMOTE_ADDR' => NODE_ROLE_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force')
            ->assertJsonPath('error.message', 'force must be true or false.')
            ->assertJsonMissingPath('success');
    });

    it('returns the orbit error envelope for invalid purge_data on remove', function (): void {
        $response = deleteNodeRoleApiJson('/api/nodes/target-1/roles/database', [
            'force' => true,
            'purge_data' => 'invalid',
        ], ['REMOTE_ADDR' => NODE_ROLE_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'purge_data')
            ->assertJsonPath('error.message', 'purge_data must be true or false.')
            ->assertJsonMissingPath('success');
    });
});

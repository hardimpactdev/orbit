<?php

declare(strict_types=1);

use App\Models\GatewayExtension;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const EXTENSION_API_CALLER_WG_IP = '10.6.0.84';

function createExtensionApiCallerNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => "extension-api-{$role}",
        'host' => EXTENSION_API_CALLER_WG_IP,
        'wireguard_address' => EXTENSION_API_CALLER_WG_IP,
        'platform' => 'ubuntu',
        'status' => 'active',
    ]);

    if ($role === 'gateway') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
    }

    return $node;
}

/**
 * @param  list<string>  $permissions
 */
function grantExtensionApiAccess(Node $consumer, Node $gateway, array $permissions): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $gateway->id,
        'permissions' => $permissions,
        'custom_permissions' => [],
    ]);
}

function extensionApiRequest(
    string $method,
    string $uri,
    string $wireguardAddress = EXTENSION_API_CALLER_WG_IP,
): TestResponse {
    return test()->call(
        $method,
        $uri,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => $wireguardAddress,
        ],
    );
}

describe('Extension API controllers', function (): void {
    it('lists built-in extensions in registry order with disabled default state', function (): void {
        createExtensionApiCallerNode();

        $response = extensionApiRequest('GET', '/api/extensions');

        $response
            ->assertOk()
            ->assertJsonPath('success.data.extensions.0.slug', 'cloudflare')
            ->assertJsonPath('success.data.extensions.0.enabled', false)
            ->assertJsonPath('success.data.extensions.0.enabled_at', null)
            ->assertJsonPath('success.data.extensions.1.slug', 'codex')
            ->assertJsonPath('success.data.extensions.1.enabled', false)
            ->assertJsonPath('success.data.extensions.2.slug', 'solo')
            ->assertJsonPath('success.data.extensions.2.enabled', false);

        expect($response->json('success.data.extensions'))->toHaveCount(3);
    });

    it('requires extension:read for listing extensions', function (): void {
        $gateway = createTestGatewayNode(['name' => 'gateway-1']);
        createExtensionApiCallerNode('control');

        extensionApiRequest('GET', '/api/extensions')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'extension:read');
    });

    it('allows callers with extension:read grants to list extensions', function (): void {
        $gateway = createTestGatewayNode(['name' => 'gateway-1']);
        $caller = createExtensionApiCallerNode('control');
        grantExtensionApiAccess($caller, $gateway, ['extension:read']);

        extensionApiRequest('GET', '/api/extensions')
            ->assertOk()
            ->assertJsonPath('success.data.extensions.0.slug', 'cloudflare');
    });

    it('allows callers with extension:* grants to list extensions', function (): void {
        $gateway = createTestGatewayNode(['name' => 'gateway-1']);
        $caller = createExtensionApiCallerNode('control');
        grantExtensionApiAccess($caller, $gateway, ['extension:*']);

        extensionApiRequest('GET', '/api/extensions')->assertOk();
    });

    it('enables an extension idempotently', function (): void {
        createExtensionApiCallerNode();

        $first = extensionApiRequest('POST', '/api/extensions/cloudflare/enable');
        $second = extensionApiRequest('POST', '/api/extensions/cloudflare/enable');

        $first
            ->assertOk()
            ->assertJsonPath('success.data.extension.slug', 'cloudflare')
            ->assertJsonPath('success.data.extension.enabled', true)
            ->assertJsonPath(
                'success.data.extension.enabled_at',
                fn (mixed $value): bool => is_string($value) && $value !== '',
            );

        $second
            ->assertOk()
            ->assertJsonPath('success.data.extension.enabled', true);

        expect(GatewayExtension::query()->where('slug', 'cloudflare')->count())->toBe(1);
    });

    it('disables an extension idempotently', function (): void {
        createExtensionApiCallerNode();

        GatewayExtension::query()->create([
            'slug' => 'codex',
            'enabled' => true,
            'enabled_at' => now(),
        ]);

        $first = extensionApiRequest('POST', '/api/extensions/codex/disable');
        $second = extensionApiRequest('POST', '/api/extensions/codex/disable');

        $first
            ->assertOk()
            ->assertJsonPath('success.data.extension.slug', 'codex')
            ->assertJsonPath('success.data.extension.enabled', false)
            ->assertJsonPath('success.data.extension.enabled_at', null);

        $second
            ->assertOk()
            ->assertJsonPath('success.data.extension.enabled', false);
    });

    it('requires extension:enable to enable an extension', function (): void {
        $gateway = createTestGatewayNode(['name' => 'gateway-1']);
        createExtensionApiCallerNode('control');

        extensionApiRequest('POST', '/api/extensions/solo/enable')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'extension:enable');
    });

    it('requires extension:disable to disable an extension', function (): void {
        $gateway = createTestGatewayNode(['name' => 'gateway-1']);
        createExtensionApiCallerNode('control');

        extensionApiRequest('POST', '/api/extensions/solo/disable')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'extension:disable');
    });

    it('allows extension:* grants to enable and disable extensions', function (): void {
        $gateway = createTestGatewayNode(['name' => 'gateway-1']);
        $caller = createExtensionApiCallerNode('control');
        grantExtensionApiAccess($caller, $gateway, ['extension:*']);

        extensionApiRequest('POST', '/api/extensions/solo/enable')->assertOk();
        extensionApiRequest('POST', '/api/extensions/solo/disable')->assertOk();
    });

    it('returns extension_unknown for unknown extension slugs', function (string $method, string $uri): void {
        createExtensionApiCallerNode();

        extensionApiRequest($method, $uri)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'extension_unknown')
            ->assertJsonPath('error.meta.extension', 'missing');
    })->with([
        'enable' => ['POST', '/api/extensions/missing/enable'],
        'disable' => ['POST', '/api/extensions/missing/disable'],
    ]);
});

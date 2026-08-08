<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\GatewayExtension;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CLOUDFLARE_API_CALLER_WG_IP = '10.6.0.88';

beforeEach(function (): void {
    config()->set('orbit.cloudflare.api_token', 'test-token');
    GatewayExtension::query()->updateOrCreate(
        ['slug' => 'cloudflare'],
        ['enabled' => true, 'enabled_at' => now()],
    );
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Http::allowStrayRequests();
});

function createCloudflareApiCallerNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => "cf-api-{$role}",
        'host' => CLOUDFLARE_API_CALLER_WG_IP,
        'wireguard_address' => CLOUDFLARE_API_CALLER_WG_IP,
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

    if ($role === 'app-dev') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
    }

    return $node;
}

/**
 * @param  list<string>  $permissions
 */
function grantCloudflareApiAccess(Node $consumer, Node $gateway, array $permissions): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $gateway->id,
        'permissions' => $permissions,
        'custom_permissions' => [],
    ]);
}

// A rejected token is the operator's to fix, so the error has to name where the
// token lives and what it needs -- not just repeat the provider's message.
it('returns actionable remediation when Cloudflare rejects the token', function (): void {
    createCloudflareApiCallerNode();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => false,
            'errors' => [['code' => 10000, 'message' => 'Invalid access token']],
        ], 403),
    ]);

    $response = $this->call(
        'GET',
        '/api/cloudflare/zones',
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'cloudflare_unavailable')
        ->assertJsonPath('error.meta.reason', 'token_rejected')
        ->assertJsonPath('error.meta.provider_status', 403)
        ->assertJsonPath('error.meta.provider_message', 'Invalid access token')
        ->assertJsonPath('error.meta.env_var', 'CLOUDFLARE_API_TOKEN')
        ->assertJsonPath('error.meta.config_key', 'orbit.cloudflare.api_token')
        ->assertJsonPath('error.meta.required_scopes.0', 'Zone:Read');

    expect($response->json('error.message'))
        ->toContain('Cloudflare rejected the gateway API token (HTTP 403)')
        ->toContain('Invalid access token')
        ->and($response->json('error.meta.remediation'))
        ->toContain('Rotate CLOUDFLARE_API_TOKEN')
        ->toContain('https://dash.cloudflare.com/profile/api-tokens');
});

it('does not blame credentials for a Cloudflare provider outage', function (): void {
    createCloudflareApiCallerNode();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => false,
            'errors' => [['message' => 'Internal error']],
        ], 500),
    ]);

    $response = $this->call(
        'GET',
        '/api/cloudflare/zones',
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'cloudflare_unavailable')
        ->assertJsonPath('error.meta.provider_status', 500)
        ->assertJsonMissingPath('error.meta.remediation')
        ->assertJsonMissingPath('error.meta.reason');
});

it('reports a missing token with the configuration location', function (): void {
    createCloudflareApiCallerNode();
    config()->set('orbit.cloudflare.api_token', null);

    $response = $this->call(
        'GET',
        '/api/cloudflare/zones',
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertStatus(503)
        ->assertJsonPath('error.meta.reason', 'token_missing')
        ->assertJsonPath('error.meta.env_var', 'CLOUDFLARE_API_TOKEN');

    expect($response->json('error.meta.remediation'))->toContain('Set CLOUDFLARE_API_TOKEN');

    Http::assertNothingSent();
});

it('lists Cloudflare zones through the gateway API', function (): void {
    createCloudflareApiCallerNode();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [
                [
                    'id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    'name' => 'lindaretel.nl',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $response = $this->call(
        'GET',
        '/api/cloudflare/zones',
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.zones.0.name', 'lindaretel.nl')
        ->assertJsonPath('success.meta.count', 1);
});

it('lists Cloudflare zones for a caller with a gateway grant', function (): void {
    $gateway = createTestGatewayNode(['name' => 'gateway-1']);
    $caller = createCloudflareApiCallerNode('control');
    grantCloudflareApiAccess($caller, $gateway, ['cf:zone:list']);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [
                [
                    'id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    'name' => 'lindaretel.nl',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $response = $this->call(
        'GET',
        '/api/cloudflare/zones',
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.zones.0.name', 'lindaretel.nl')
        ->assertJsonPath('success.meta.count', 1);
});

it('denies callers without the required Cloudflare grant before provider requests', function (): void {
    $gateway = createTestGatewayNode(['name' => 'gateway-1']);
    createCloudflareApiCallerNode('app-dev');

    $response = $this->call(
        'GET',
        '/api/cloudflare/zones',
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', 'cf:zone:list')
        ->assertJsonPath('error.meta.serving_node', $gateway->name);

    Http::assertNothingSent();
});

it('requires the disable permission for the Cloudflare SSL disable API route', function (): void {
    $gateway = createTestGatewayNode(['name' => 'gateway-1']);
    $caller = createCloudflareApiCallerNode('control');
    grantCloudflareApiAccess($caller, $gateway, ['cf:ssl:enable']);

    $response = $this->call(
        'PUT',
        '/api/cloudflare/zones/lindaretel.nl/ssl/disable',
        [
            'destructive_consent' => true,
        ],
        [],
        [],
        [
            'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
        ],
    );

    $response
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', 'cf:ssl:disable');

    Http::assertNothingSent();
});

it('adds a cache rule for an app without exposing project vocabulary', function (): void {
    $gateway = createCloudflareApiCallerNode();
    App::factory()->for($gateway, 'node')->create([
        'name' => 'docs',
        'domain' => 'docs.example.com',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [[
                'id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'name' => 'example.com',
                'status' => 'active',
            ]],
        ]),
        'https://api.cloudflare.com/client/v4/zones/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/rulesets' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push(['success' => true, 'result' => ['id' => 'cache-rules']]),
    ]);

    $response = $this->call(
        'POST',
        '/api/cloudflare/cache-rules/docs',
        [],
        [],
        [],
        ['REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.rule.app', 'docs')
        ->assertJsonMissingPath('success.data.rule.project');
});

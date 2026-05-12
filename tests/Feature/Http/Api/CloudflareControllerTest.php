<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CLOUDFLARE_API_CALLER_WG_IP = '10.6.0.88';

beforeEach(function (): void {
    config()->set('orbit.cloudflare.api_token', 'test-token');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Http::allowStrayRequests();
});

function createCloudflareApiCallerNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "cf-api-{$role}",
        'role' => $role,
        'host' => CLOUDFLARE_API_CALLER_WG_IP,
        'wireguard_address' => CLOUDFLARE_API_CALLER_WG_IP,
        'platform' => 'ubuntu',
        'status' => 'active',
    ]);
}

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

    $response = $this->call('GET', '/api/cloudflare/zones', [], [], [], [
        'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
    ]);

    $response->assertOk()
        ->assertJsonPath('success.data.zones.0.name', 'lindaretel.nl')
        ->assertJsonPath('success.meta.count', 1);
});

it('denies app callers before provider requests', function (): void {
    createCloudflareApiCallerNode('app');

    $response = $this->call('GET', '/api/cloudflare/zones', [], [], [], [
        'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
    ]);

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'caller_role_not_allowed')
        ->assertJsonPath('error.meta.caller_role', 'app');

    Http::assertNothingSent();
});

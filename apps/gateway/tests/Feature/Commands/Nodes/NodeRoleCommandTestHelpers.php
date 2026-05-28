<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function nodeRoleNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'node-1',
        'host' => '10.6.0.10',
        'wireguard_address' => '10.6.0.10',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeRoleGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeRoleNodeRow([
        'name' => 'gateway-1',
    ]));
}

function setupNodeRoleControlCaller(): void
{
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->insert(nodeRoleNodeRow([
        'name' => 'control-1',
    ]));

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

function createHostedNode(array $overrides = []): Node
{
    return Node::query()->create(nodeRoleNodeRow(array_merge([
        'name' => 'host-1',
        'host' => '10.6.0.20',
        'wireguard_address' => '10.6.0.20',
    ], $overrides)));
}

function assignNodeRole(Node $node, string $role, string $status = 'active', array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
        'last_error' => null,
        'converged_at' => $status === 'active' ? now() : null,
    ]);
}

function nodeRoleAssignmentPayload(
    string $role,
    string $status = 'active',
    array $settings = [],
    ?string $lastError = null,
    ?string $convergedAt = null,
): array {
    return [
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
        'last_error' => $lastError,
        'converged_at' => $convergedAt,
    ];
}

function fakeNodeRoleGateway(string $requestClass, array|string $body, int $status = 200): MockClient
{
    MockClient::destroyGlobal();

    return MockClient::global([
        $requestClass => MockResponse::make($body, $status),
    ]);
}

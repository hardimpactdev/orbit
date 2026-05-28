<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\CreateNodeRequest;
use App\Models\LocalGatewaySettings;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeNewInteractiveRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'control-1',
        'host' => '127.0.0.1',
        'wireguard_address' => '10.6.0.3',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'macos_15-4',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeNewInteractiveControlCaller(): void
{
    DB::table('nodes')->insert(nodeNewInteractiveRow());

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

function setupNodeNewInteractiveUnconfiguredControlCaller(): void
{
    DB::table('nodes')->insert(nodeNewInteractiveRow());
}

function setupNodeNewInteractiveAppCaller(): void
{
    DB::table('nodes')->insert(nodeNewInteractiveRow([
        'name' => 'local-app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
    ]));
}

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeNewGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        CreateNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:new interactive input mode', function (): void {
    it('prompts for missing name but does not prompt for role when omitted', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeNewInteractiveUnconfiguredControlCaller();

        $this->artisan('node:new')
            ->expectsQuestion('Node name', 'control-2')
            ->expectsOutputToContain('Gateway connection is required before creating client identities.')
            ->assertFailed();
    });

    it('omitted role forwards the joined client path without a role prompt', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeNewInteractiveControlCaller();
        fakeNodeNewGateway([
            'success' => [
                'data' => [
                    'result' => ['action' => 'enrolled'],
                    'node' => [
                        'name' => 'control-2',
                        'tld' => null,
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'wireguard',
                        'host' => null,
                        'status' => 'enrolled',
                    ],
                    'wireguard' => [
                        'config' => "[Interface]\nPrivateKey = control-private-key\n",
                    ],
                    'next_steps' => [
                        'Install the WireGuard configuration on the operator node.',
                        'Join the Orbit WireGuard network.',
                        'Run `orbit gateway:add` on the operator node.',
                    ],
                ],
            ],
        ]);

        $this->artisan('node:new')
            ->expectsQuestion('Node name', 'control-2')
            ->expectsOutputToContain('Enrolled client node control-2.')
            ->assertSuccessful();
    });

    it('prompts for app inputs in documented order and forwards the resolved request', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeNewInteractiveControlCaller();
        fakeNodeNewGateway([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'node' => [
                        'name' => 'app-1',
                        'tld' => 'test',
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'ssh',
                        'host' => '192.0.2.20',
                        'status' => 'complete',
                    ],
                    'next_steps' => [],
                ],
            ],
        ]);

        $this->artisan('node:new --roles=app-dev')
            ->expectsQuestion('Node name', 'app-1')
            ->expectsQuestion('Host', '192.0.2.20')
            ->expectsQuestion('Development TLD', 'test')
            ->expectsQuestion('SSH user', 'deployer')
            ->expectsOutputToContain('Created node app-1.')
            ->assertSuccessful();
    });

    it('does not prompt for ssh user on standalone canonical database role', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeNewInteractiveControlCaller();
        fakeNodeNewGateway([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'node' => [
                        'name' => 'db-1',
                        'tld' => null,
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'none',
                        'host' => null,
                        'status' => 'created',
                    ],
                    'next_steps' => [],
                ],
            ],
        ]);

        $this->artisan('node:new --roles=database')
            ->expectsQuestion('Node name', 'db-1')
            ->expectsOutputToContain('Created node db-1.')
            ->assertSuccessful();
    });

    it('does not prompt for tld when app-prod is selected', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeNewInteractiveControlCaller();
        fakeNodeNewGateway([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'node' => [
                        'name' => 'app-1',
                        'tld' => null,
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'ssh',
                        'host' => '192.0.2.20',
                        'status' => 'complete',
                    ],
                    'next_steps' => [],
                ],
            ],
        ]);

        $this->artisan('node:new --roles=app-prod')
            ->expectsQuestion('Node name', 'app-1')
            ->expectsQuestion('Host', '192.0.2.20')
            ->expectsQuestion('SSH user', 'root')
            ->expectsConfirmation('Serve public traffic from this node?', 'yes')
            ->expectsOutputToContain('Created node app-1.')
            ->assertSuccessful();
    });

    it('prompts for an active ingress node when app-prod uses separate ingress placement', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeNewInteractiveControlCaller();

        $edgeOneId = DB::table('nodes')->insertGetId(nodeNewInteractiveRow([
            'name' => 'edge-1',
            'host' => '10.6.0.10',
            'wireguard_address' => '10.6.0.10',
            'platform' => 'ubuntu_24-04',
        ]));
        $edgeTwoId = DB::table('nodes')->insertGetId(nodeNewInteractiveRow([
            'name' => 'edge-2',
            'host' => '10.6.0.11',
            'wireguard_address' => '10.6.0.11',
            'platform' => 'ubuntu_24-04',
        ]));

        NodeRoleAssignment::factory()->create([
            'node_id' => $edgeOneId,
            'role' => 'ingress',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $edgeTwoId,
            'role' => 'ingress',
            'status' => 'active',
        ]);

        fakeNodeNewGateway([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'node' => [
                        'name' => 'app-1',
                        'tld' => null,
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'ssh',
                        'host' => '192.0.2.20',
                        'status' => 'complete',
                    ],
                    'next_steps' => [],
                ],
            ],
        ]);

        $this->artisan('node:new --roles=app-prod')
            ->expectsQuestion('Node name', 'app-1')
            ->expectsQuestion('Host', '192.0.2.20')
            ->expectsQuestion('SSH user', 'root')
            ->expectsConfirmation('Serve public traffic from this node?', 'no')
            ->expectsChoice('Ingress node', 'edge-2', ['edge-1', 'edge-2'])
            ->expectsOutputToContain('Created node app-1.')
            ->assertSuccessful();
    });

    it('rejects invalid host prompt input before later prompts or forwarding', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeNewInteractiveControlCaller();
        $gateway = fakeNodeNewGateway([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'node' => [
                        'name' => 'app-1',
                        'tld' => null,
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'ssh',
                        'host' => '192.0.2.20',
                        'status' => 'complete',
                    ],
                    'next_steps' => [],
                ],
            ],
        ]);

        $this->artisan('node:new --roles=app-prod')
            ->expectsQuestion('Node name', 'app-1')
            ->expectsQuestion('Host', 'incorrect-host')
            ->assertFailed();

        $gateway->assertNothingSent();
    });

    it('does not prompt when json forces non-interactive mode', function (): void {
        setupNodeNewInteractiveControlCaller();

        $this->artisan('node:new --json')
            ->expectsOutputToContain('Node name is required.')
            ->assertFailed();
    });

});

<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\SetNodeAgentIdeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);
    MockClient::destroyGlobal();
});

afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeAgentIdeHumanGateway(array|string $body, int $status = 200): void
{
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    MockClient::global([
        SetNodeAgentIdeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:agent-ide human renderer contract', function (): void {
    it('renders success prose for a newly set adapter', function (): void {
        fakeNodeAgentIdeHumanGateway([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'node',
                    ],
                    'action' => 'set',
                ],
            ],
        ]);

        \Pest\Laravel\artisan('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
        ])
            ->expectsOutputToContain("Node 'app-1' agent IDE set to 'opencode'")
            ->assertSuccessful();
    });

    it('renders validation failure prose for missing required input', function (): void {
        \Pest\Laravel\artisan('node:agent-ide', ['--no-interaction' => true])
            ->expectsOutputToContain('Node name is required.')
            ->assertFailed();
    });

    it('renders validation failure prose for missing adapter input', function (): void {
        \Pest\Laravel\artisan('node:agent-ide', [
            'name' => 'app-1',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Agent IDE adapter is required.')
            ->assertFailed();
    });

    it('preserves documented gateway error prose', function (array $error): void {
        fakeNodeAgentIdeHumanGateway(['error' => $error], 422);

        \Pest\Laravel\artisan('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => $error['meta']['adapter'] ?? 'opencode',
        ])
            ->expectsOutputToContain($error['message'])
            ->doesntExpectOutputToContain('Gateway connection is required to update node configuration.')
            ->assertFailed();
    })->with([
        'caller_role_not_allowed' => [[
            'code' => 'caller_role_not_allowed',
            'message' => 'This command may only be run from a control or gateway node.',
            'meta' => [
                'caller_role' => 'app',
            ],
        ]],
        'authorization_failed' => [[
            'code' => 'authorization_failed',
            'message' => 'This control node is not authorized to update node configuration.',
            'meta' => [
                'required_node' => 'gateway-1',
                'caller_role' => 'control',
            ],
        ]],
        'node.not_found' => [[
            'code' => 'node.not_found',
            'message' => "Node 'missing-node' not found.",
            'meta' => [
                'name' => 'missing-node',
            ],
        ]],
        'node.unsupported_adapter' => [[
            'code' => 'node.unsupported_adapter',
            'message' => "Adapter 'unknown-ide' is not supported.",
            'meta' => [
                'adapter' => 'unknown-ide',
            ],
        ]],
    ]);
});

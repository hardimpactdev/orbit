<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\AgentIde\ListAgentIdeAdaptersRequest;
use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Http\Gateway\Requests\Nodes\SetNodeAgentIdeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    MockClient::destroyGlobal();
    Process::preventStrayProcesses();
});

afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeAgentIdeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'public_ipv4' => null,
        'public_ipv6' => null,
        'agent_ide_config' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeAgentIdeGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeAgentIdeRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
    ]));
}

function setupNodeAgentIdeControlCaller(): void
{
    DB::table('nodes')->insert(nodeAgentIdeRow([
        'name' => 'control-1',
        'role' => 'control',
        'environment' => null,
    ]));

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeAgentIdeGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        SetNodeAgentIdeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:agent-ide command', function (): void {
    it('sets a node agent IDE default on the gateway', function (): void {
        setupNodeAgentIdeGatewayCaller();
        DB::table('nodes')->insert(nodeAgentIdeRow());

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $config = json_decode((string) DB::table('nodes')->where('name', 'app-1')->value('agent_ide_config'), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toBe([
                'name' => 'app-1',
                'agent_ide' => [
                    'adapter' => 'opencode',
                    'source' => 'node',
                ],
                'action' => 'set',
            ])
            ->and($config)->toBe(['adapter' => 'opencode']);
    });

    it('clears a node agent IDE default with none', function (): void {
        setupNodeAgentIdeGatewayCaller();
        DB::table('nodes')->insert(nodeAgentIdeRow([
            'agent_ide_config' => json_encode(['adapter' => 'opencode'], JSON_THROW_ON_ERROR),
        ]));

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'none',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['agent_ide'])->toBe([
                'adapter' => null,
                'source' => 'default',
            ])
            ->and($payload['success']['data']['action'])->toBe('set')
            ->and(DB::table('nodes')->where('name', 'app-1')->value('agent_ide_config'))->toBeNull();
    });

    it('preserves adapter-specific node config when changing the node default', function (): void {
        setupNodeAgentIdeGatewayCaller();
        DB::table('nodes')->insert(nodeAgentIdeRow([
            'agent_ide_config' => json_encode([
                'adapter' => 'opencode',
                'polyscope' => [
                    'server_id' => 'server-123',
                ],
            ], JSON_THROW_ON_ERROR),
        ]));

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'polyscope',
            '--json' => true,
        ]);

        $config = json_decode((string) DB::table('nodes')->where('name', 'app-1')->value('agent_ide_config'), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);
        expect($config)->toBe([
            'adapter' => 'polyscope',
            'polyscope' => [
                'server_id' => 'server-123',
            ],
        ]);
    });

    it('preserves adapter-specific node config when clearing the node default', function (): void {
        setupNodeAgentIdeGatewayCaller();
        DB::table('nodes')->insert(nodeAgentIdeRow([
            'agent_ide_config' => json_encode([
                'adapter' => 'polyscope',
                'polyscope' => [
                    'server_id' => 'server-123',
                ],
            ], JSON_THROW_ON_ERROR),
        ]));

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'none',
            '--json' => true,
        ]);

        $config = json_decode((string) DB::table('nodes')->where('name', 'app-1')->value('agent_ide_config'), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);
        expect($config)->toBe([
            'polyscope' => [
                'server_id' => 'server-123',
            ],
        ]);
    });

    it('returns converged when the requested adapter already matches', function (): void {
        setupNodeAgentIdeGatewayCaller();
        DB::table('nodes')->insert(nodeAgentIdeRow([
            'agent_ide_config' => json_encode(['adapter' => 'opencode'], JSON_THROW_ON_ERROR),
        ]));

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('converged')
            ->and($payload['success']['data']['agent_ide']['adapter'])->toBe('opencode');
    });

    it('renders human output for set clear and converged actions', function (): void {
        setupNodeAgentIdeGatewayCaller();
        DB::table('nodes')->insert(nodeAgentIdeRow());

        $setExitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
        ]);

        expect($setExitCode)->toBe(0)
            ->and(Artisan::output())->toContain("Node 'app-1' agent IDE set to 'opencode'");

        $convergedExitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
        ]);

        expect($convergedExitCode)->toBe(0)
            ->and(Artisan::output())->toContain("Node 'app-1' agent IDE already set to 'opencode'");

        $clearExitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'none',
        ]);

        expect($clearExitCode)->toBe(0)
            ->and(Artisan::output())->toContain("Node 'app-1' agent IDE cleared");
    });

    it('validates required command inputs', function (array $arguments, string $field, string $message): void {
        setupNodeAgentIdeGatewayCaller();

        $exitCode = Artisan::call('node:agent-ide', array_merge($arguments, [
            '--json' => true,
        ]));

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe($message)
            ->and($payload['error']['meta']['field'])->toBe($field);
    })->with([
        'name' => [[], 'name', 'Node name is required.'],
        'agent_ide' => [['name' => 'app-1'], 'agent_ide', 'Agent IDE adapter is required.'],
    ]);

    it('fails when the node is not found', function (): void {
        setupNodeAgentIdeGatewayCaller();

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'missing-node',
            'agent_ide' => 'opencode',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found')
            ->and($payload['error']['message'])->toBe("Node 'missing-node' not found.")
            ->and($payload['error']['meta']['name'])->toBe('missing-node');
    });

    it('fails when the adapter is unsupported', function (): void {
        setupNodeAgentIdeGatewayCaller();
        DB::table('nodes')->insert(nodeAgentIdeRow());

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'unknown-ide',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.unsupported_adapter')
            ->and($payload['error']['message'])->toBe("Adapter 'unknown-ide' is not supported.")
            ->and($payload['error']['meta']['adapter'])->toBe('unknown-ide');
    });

    it('forwards control callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeAgentIdeControlCaller();
        $mockClient = fakeNodeAgentIdeGateway([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'agent_ide' => [
                        'adapter' => 'polyscope',
                        'source' => 'node',
                    ],
                    'action' => 'set',
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'polyscope',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['agent_ide']['adapter'])->toBe('polyscope');

        $mockClient->assertSent(SetNodeAgentIdeRequest::class);
    });

    it('queries gateway adapter choices before prompting configured control callers', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeAgentIdeControlCaller();

        $mockClient = MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'nodes' => [
                            [
                                'name' => 'app-1',
                                'role' => 'app',
                                'status' => 'active',
                                'environment' => 'development',
                                'host' => '10.6.0.7',
                            ],
                        ],
                    ],
                ],
            ], 200),
            ListAgentIdeAdaptersRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'scope' => 'node',
                        'reserved_tokens' => ['none'],
                        'adapters' => [
                            [
                                'name' => 'custom-agent',
                                'label' => 'Custom Agent',
                                'source' => 'extension',
                                'capabilities' => ['message_delivery'],
                            ],
                        ],
                    ],
                ],
            ], 200),
            SetNodeAgentIdeRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'name' => 'app-1',
                        'agent_ide' => [
                            'adapter' => 'custom-agent',
                            'source' => 'node',
                        ],
                        'action' => 'set',
                    ],
                ],
            ], 200),
        ]);

        DataTablePrompt::fake([Key::ENTER]);

        $this->artisan('node:agent-ide')
            ->expectsChoice('Agent IDE adapter', 'custom-agent', ['none', 'custom-agent'])
            ->expectsOutputToContain("Node 'app-1' agent IDE set to 'custom-agent'")
            ->assertExitCode(0);

        $mockClient->assertSent(ListNodesRequest::class);
        $mockClient->assertSent(ListAgentIdeAdaptersRequest::class);
        $mockClient->assertSent(SetNodeAgentIdeRequest::class);
    });

    it('fails before prompting when configured control callers cannot fetch adapter choices', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeAgentIdeControlCaller();

        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'nodes' => [
                            [
                                'name' => 'app-1',
                                'role' => 'app',
                                'status' => 'active',
                                'environment' => 'development',
                                'host' => '10.6.0.7',
                            ],
                        ],
                    ],
                ],
            ], 200),
            ListAgentIdeAdaptersRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway connection is required to read agent IDE adapters.',
                    'meta' => [],
                ],
            ], 503),
        ]);

        DataTablePrompt::fake([Key::ENTER]);

        $this->artisan('node:agent-ide')
            ->expectsOutputToContain('Gateway connection is required to read agent IDE adapters.')
            ->assertExitCode(1);
    });

    it('surfaces gateway authorization failures for control callers', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeAgentIdeControlCaller();
        fakeNodeAgentIdeGateway([
            'error' => [
                'code' => 'authorization_failed',
                'message' => 'This control node is not authorized to update node configuration.',
                'meta' => [
                    'required_node' => 'gateway-1',
                    'caller_role' => 'control',
                ],
            ],
        ], 403);

        $exitCode = Artisan::call('node:agent-ide', [
            'name' => 'app-1',
            'agent_ide' => 'opencode',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['message'])->toBe('This control node is not authorized to update node configuration.')
            ->and($payload['error']['meta']['required_node'])->toBe('gateway-1');
    });
});

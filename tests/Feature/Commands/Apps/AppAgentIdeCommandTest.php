<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\AgentIde\ListAgentIdeAdaptersRequest;
use App\Http\Gateway\Requests\Apps\ListAppsRequest;
use App\Http\Gateway\Requests\Apps\SetAppAgentIdeRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(RemoteShell::class, new AppAgentIdeCommandRemoteShell);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

if (! class_exists('PruneAppActionTestAdapter')) {
    final class PruneAppActionTestAdapter implements AgentIdeMessageAdapter
    {
        public function activeSession(array $target, string $adapter): ?array
        {
            return null;
        }

        public function deliver(array $target, string $adapter, array $session, string $message): array
        {
            return ['status' => 'failed'];
        }

        public function workspaces(array $target, string $adapter): array
        {
            return ['active-ws'];
        }
    }
}

if (! class_exists('AppAgentIdeCommandRemoteShell')) {
    final class AppAgentIdeCommandRemoteShell implements RemoteShell
    {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    }
}

it('sets an app-level agent ide adapter from a gateway caller', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'opencode',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $config = App::query()->where('name', 'docs')->value('agent_ide_config');

    expect($exitCode)->toBe(0)
        ->and($config)->toBe(['adapter' => 'opencode'])
        ->and($payload['success']['data']['app']['name'])->toBe('docs')
        ->and($payload['success']['data']['agent_ide'])->toBe([
            'adapter' => 'opencode',
            'source' => 'app',
            'effective_adapter' => 'opencode',
        ])
        ->and($payload['success']['data']['cleanup'])->toBe([
            'workspaces_removed' => [],
        ])
        ->and($payload['success']['data']['action'])->toBe('set');
});

it('reports converged when the app-level adapter already matches', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'opencode',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['action'])->toBe('converged')
        ->and($payload['success']['data']['agent_ide']['effective_adapter'])->toBe('opencode');
});

it('preserves adapter-specific app config when setting an app adapter', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => [
            'polyscope' => [
                'repository_id' => 'repo-123',
            ],
        ],
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'polyscope',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBe([
        'polyscope' => [
            'repository_id' => 'repo-123',
        ],
        'adapter' => 'polyscope',
    ]);
});

it('preserves adapter-specific app config when clearing to inheritance', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => [
            'adapter' => 'polyscope',
            'polyscope' => [
                'repository_id' => 'repo-123',
            ],
        ],
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'inherit',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBe([
        'polyscope' => [
            'repository_id' => 'repo-123',
        ],
    ]);
});

it('clears the app override and inherits the owning node default', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'inherit',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBeNull()
        ->and($payload['success']['data']['agent_ide'])->toBe([
            'adapter' => null,
            'source' => 'node',
            'effective_adapter' => 'polyscope',
        ]);
});

it('stores none as an explicit app-level disable', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    App::factory()->create([
        'name' => 'docs',
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'none',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBe(['adapter' => 'none'])
        ->and($payload['success']['data']['agent_ide'])->toBe([
            'adapter' => 'none',
            'source' => 'app',
            'effective_adapter' => null,
        ]);
});

it('does not mutate local app state for non-gateway callers without gateway settings', function (): void {
    config(['orbit.is_gateway' => false]);

    Node::factory()->create([
        'name' => 'app-local',
        'role' => 'app',
    ]);

    App::factory()->create([
        'name' => 'docs',
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'opencode',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('gateway_unavailable')
        ->and(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBeNull();
});

it('rejects unsupported adapters with the supported value list', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    App::factory()->create([
        'name' => 'docs',
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'unknown',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('app.unsupported_adapter')
        ->and($payload['error']['meta'])->toMatchArray([
            'adapter' => 'unknown',
            'supported' => ['inherit', 'none', 'opencode', 'polyscope'],
        ]);
});

it('validates missing required non-interactive inputs', function (?string $app, ?string $agentIde, string $field): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => $app,
        'agent_ide' => $agentIde,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe($field);
})->with([
    'missing app' => [null, 'opencode', 'app'],
    'missing agent ide' => ['docs', null, 'agent_ide'],
]);

it('forwards configured control callers through the typed gateway request', function (): void {
    config(['orbit.is_gateway' => false]);

    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    App::factory()->create([
        'name' => 'docs',
    ]);

    MockClient::global([
        SetAppAgentIdeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'app' => [
                        'name' => 'docs',
                        'node' => 'app-1',
                        'environment' => 'development',
                        'url' => 'https://docs.test',
                        'path' => '/home/orbit/apps/docs',
                        'root' => 'public',
                        'repository' => null,
                        'php_version' => '8.5',
                        'adopted' => false,
                    ],
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'app',
                        'effective_adapter' => 'opencode',
                    ],
                    'cleanup' => [
                        'workspaces_removed' => [],
                    ],
                    'action' => 'set',
                ],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'opencode',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBeNull()
        ->and($payload['success']['data']['app']['name'])->toBe('docs')
        ->and($payload['success']['data']['agent_ide']['effective_adapter'])->toBe('opencode')
        ->and($payload['success']['data']['cleanup']['workspaces_removed'])->toBe([]);
});

it('queries gateway adapter choices before prompting configured control callers', function (): void {
    config(['orbit.is_gateway' => false]);

    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $mockClient = MockClient::global([
        ListAppsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'apps' => [
                        [
                            'name' => 'docs',
                            'node' => 'app-1',
                            'environment' => 'development',
                            'url' => 'https://docs.test',
                            'path' => '/home/orbit/apps/docs',
                            'root' => 'public',
                            'repository' => null,
                            'php_version' => '8.5',
                            'adopted' => false,
                        ],
                    ],
                ],
            ],
        ], 200),
        ListAgentIdeAdaptersRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'scope' => 'app',
                    'reserved_tokens' => ['inherit', 'none'],
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
        SetAppAgentIdeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'app' => [
                        'name' => 'docs',
                        'node' => 'app-1',
                        'environment' => 'development',
                        'url' => 'https://docs.test',
                        'path' => '/home/orbit/apps/docs',
                        'root' => 'public',
                        'repository' => null,
                        'php_version' => '8.5',
                        'adopted' => false,
                    ],
                    'agent_ide' => [
                        'adapter' => 'custom-agent',
                        'source' => 'app',
                        'effective_adapter' => 'custom-agent',
                    ],
                    'cleanup' => [
                        'workspaces_removed' => [],
                    ],
                    'action' => 'set',
                ],
            ],
        ], 200),
    ]);

    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('app:agent-ide')
        ->expectsChoice('Select agent IDE adapter', 'custom-agent', [
            'inherit' => 'Inherit node default',
            'none' => 'None',
            'custom-agent' => 'custom-agent',
        ])
        ->expectsOutputToContain('App `docs` agent IDE set to `custom-agent` (effective: `custom-agent`)')
        ->assertExitCode(0);

    $mockClient->assertSent(ListAppsRequest::class);
    $mockClient->assertSent(ListAgentIdeAdaptersRequest::class);
    $mockClient->assertSent(SetAppAgentIdeRequest::class);
});

it('fails before adapter prompt when configured control callers cannot fetch adapter choices', function (): void {
    config(['orbit.is_gateway' => false]);

    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    MockClient::global([
        ListAppsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'apps' => [
                        [
                            'name' => 'docs',
                            'node' => 'app-1',
                            'environment' => 'development',
                            'url' => 'https://docs.test',
                            'path' => '/home/orbit/apps/docs',
                            'root' => 'public',
                            'repository' => null,
                            'php_version' => '8.5',
                            'adopted' => false,
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

    $this->artisan('app:agent-ide')
        ->expectsOutputToContain('Gateway connection is required to read agent IDE adapters.')
        ->assertExitCode(1);
});

it('prompts for missing human input and renders the progress tree', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    App::factory()->create([
        'name' => 'docs',
    ]);

    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('app:agent-ide')
        ->expectsChoice('Select agent IDE adapter', 'opencode', [
            'inherit' => 'Inherit node default',
            'none' => 'None',
            'opencode' => 'opencode',
            'polyscope' => 'polyscope',
        ])
        ->expectsOutputToContain('┌  Configuring App Agent IDE')
        ->expectsOutputToContain('○  Validate adapter')
        ->expectsOutputToContain('●  Validated adapter')
        ->expectsOutputToContain('●  Checked for workspace cleanup')
        ->expectsOutputToContain('●  Applied and verified app agent IDE')
        ->expectsOutputToContain("└  App 'docs' agent IDE configured")
        ->expectsOutputToContain('App `docs` agent IDE set to `opencode` (effective: `opencode`)')
        ->assertExitCode(0);
});

it('renders inherited and explicit none human success states', function (string $input, string $expected): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $this->artisan('app:agent-ide docs '.$input)
        ->expectsOutputToContain($expected)
        ->assertExitCode(0);
})->with([
    'inherit' => ['inherit', 'App `docs` agent IDE set to inherit (effective: `polyscope` from node `app-1`)'],
    'none' => ['none', 'App `docs` agent IDE set to none (effective: none)'],
]);

it('renders converged human output', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $this->artisan('app:agent-ide docs opencode')
        ->expectsOutputToContain('App `docs` agent IDE already set to `opencode`')
        ->assertExitCode(0);
});

it('prunes stale workspaces when switching adapters with --force', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'stale-ws',
        'path' => '/home/orbit/apps/docs/stale-ws',
    ]);

    app()->instance(AgentIdeMessageAdapter::class, new PruneAppActionTestAdapter);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'polyscope',
        '--force' => true,
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['action'])->toBe('set')
        ->and($payload['success']['data']['previous_adapter'])->toBe('opencode')
        ->and($payload['success']['data']['cleanup']['workspaces_removed'])->toBe(['stale-ws'])
        ->and(Workspace::query()->where('name', 'stale-ws')->exists())->toBeFalse();
});

it('skips workspace cleanup when no previous adapter', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    App::factory()->create([
        'name' => 'docs',
    ]);

    $exitCode = Artisan::call('app:agent-ide', [
        'app' => 'docs',
        'agent_ide' => 'opencode',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['previous_adapter'])->toBeNull()
        ->and($payload['success']['data']['cleanup']['workspaces_removed'])->toBe([]);
});

it('prompts for workspace cleanup consent in interactive mode', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'stale-ws',
        'path' => '/home/orbit/apps/docs/stale-ws',
    ]);

    app()->instance(AgentIdeMessageAdapter::class, new PruneAppActionTestAdapter);

    $this->artisan('app:agent-ide docs polyscope')
        ->expectsConfirmation("This will remove 1 workspace(s) managed by the previous adapter 'opencode'. Continue?", 'yes')
        ->expectsOutputToContain('Removed 1 stale workspaces during adapter switch:')
        ->expectsOutputToContain('- stale-ws')
        ->assertExitCode(0);

    expect(Workspace::query()->where('name', 'stale-ws')->exists())->toBeFalse();
});

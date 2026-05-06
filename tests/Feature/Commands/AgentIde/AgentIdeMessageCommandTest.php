<?php

declare(strict_types=1);

use App\Console\Commands\AgentIdeMessageCommand;
use App\Contracts\AgentIdeMessageAdapter;
use App\Http\Gateway\Requests\AgentIde\SendAgentIdeMessageRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

final class FakeAgentIdeMessageAdapter implements AgentIdeMessageAdapter
{
    public array $deliveries = [];

    public ?array $session = [
        'id' => 'sess_123',
        'status' => 'active',
    ];

    public function activeSession(array $target, string $adapter): ?array
    {
        return $this->session;
    }

    public function deliver(array $target, string $adapter, array $session, string $message): array
    {
        $this->deliveries[] = compact('target', 'adapter', 'session', 'message');

        return [
            'status' => 'sent',
            'session' => $session,
        ];
    }
}

it('delivers a JSON message to an explicit app target on the gateway', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toBe([
            'success' => [
                'data' => [
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'app',
                        'target' => [
                            'app' => 'docs',
                            'workspace' => null,
                            'node' => 'app-1',
                        ],
                        'session' => [
                            'id' => 'sess_123',
                            'status' => 'active',
                        ],
                        'delivery' => [
                            'status' => 'sent',
                            'message_bytes' => 13,
                            'input' => 'argument',
                        ],
                    ],
                ],
            ],
        ])
        ->and($adapter->deliveries)->toHaveCount(1)
        ->and($adapter->deliveries[0]['message'])->toBe('Ship the docs')
        ->and($adapter->deliveries[0]['target'])->toBe([
            'app' => 'docs',
            'workspace' => null,
            'node' => 'app-1',
        ]);
});

it('renders the human progress tree for an app target', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    app()->instance(AgentIdeMessageAdapter::class, new FakeAgentIdeMessageAdapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('┌ Sending Agent IDE message to docs')
        ->and($output)->toContain('● Resolved target')
        ->and($output)->toContain('● Resolved effective adapter')
        ->and($output)->toContain('● Found active session')
        ->and($output)->toContain('● Delivered message')
        ->and($output)->toContain('└ Sent Agent IDE message to docs through opencode')
        ->and($output)->toContain('Sent message to docs through opencode.')
        ->and($output)->not->toContain('"success"');
});

it('delivers a stdin message to an explicit app target', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $command = app(AgentIdeMessageCommand::class);
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->setInputs(["Ship the docs\nwith context"]);

    $exitCode = $tester->execute([
        '--stdin' => true,
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['agent_ide']['delivery']['message_bytes'])->toBe(26)
        ->and($adapter->deliveries[0]['message'])->toBe("Ship the docs\nwith context");
});

it('fails when stdin is combined with a positional message', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $command = app(AgentIdeMessageCommand::class);
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->setInputs(['from stdin']);

    $exitCode = $tester->execute([
        'message' => 'from argument',
        '--stdin' => true,
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload)->toMatchArray([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Pass either [message] or --stdin, not both.',
                'meta' => [
                    'field' => 'message',
                    'reason' => 'conflicting_message_inputs',
                ],
            ],
        ]);
});

it('delivers a JSON message to an explicit workspace target on the gateway', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    Workspace::factory()->create([
        'name' => 'feature-docs',
        'app_id' => $app->id,
        'agent_ide' => 'polyscope',
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--workspace' => 'feature-docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['agent_ide']['adapter'])->toBe('polyscope')
        ->and($payload['success']['data']['agent_ide']['source'])->toBe('workspace')
        ->and($payload['success']['data']['agent_ide']['target'])->toBe([
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'node' => 'app-1',
        ])
        ->and($adapter->deliveries)->toHaveCount(1)
        ->and($adapter->deliveries[0]['target'])->toBe([
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'node' => 'app-1',
        ]);
});

it('renders the human progress tree for a workspace target', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
    ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    Workspace::factory()->create([
        'name' => 'feature-docs',
        'app_id' => $app->id,
        'agent_ide' => 'polyscope',
    ]);

    app()->instance(AgentIdeMessageAdapter::class, new FakeAgentIdeMessageAdapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--workspace' => 'feature-docs',
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('┌ Sending Agent IDE message to docs/feature-docs')
        ->and($output)->toContain('└ Sent Agent IDE message to docs/feature-docs through polyscope')
        ->and($output)->toContain('Sent message to docs/feature-docs through polyscope.')
        ->and($output)->not->toContain('"success"');
});

it('fails when the target workspace has no effective adapter', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    Workspace::factory()->create([
        'name' => 'feature-docs',
        'app_id' => $app->id,
        'agent_ide' => 'none',
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--workspace' => 'feature-docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload)->toMatchArray([
            'error' => [
                'code' => 'no_effective_adapter',
                'message' => 'No Agent IDE adapter is configured for workspace feature-docs.',
                'meta' => [
                    'app' => 'docs',
                    'workspace' => 'feature-docs',
                ],
            ],
        ])
        ->and($adapter->deliveries)->toBeEmpty();
});

it('infers a workspace target from the current directory on gateway callers', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
    ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $workspacePath = sys_get_temp_dir().'/orbit-agent-ide-workspace-cwd-'.bin2hex(random_bytes(4));
    $childPath = "{$workspacePath}/nested";
    File::ensureDirectoryExists($childPath);

    Workspace::factory()->create([
        'name' => 'feature-docs',
        'app_id' => $app->id,
        'path' => $workspacePath,
        'agent_ide' => 'polyscope',
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $previousCwd = getcwd();
    chdir($childPath);

    try {
        $exitCode = Artisan::call('agent-ide:message', [
            'message' => 'Ship the docs',
            '--json' => true,
        ]);
    } finally {
        if (is_string($previousCwd)) {
            chdir($previousCwd);
        }

        File::deleteDirectory($workspacePath);
    }

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['agent_ide']['source'])->toBe('workspace')
        ->and($payload['success']['data']['agent_ide']['target'])->toMatchArray([
            'app' => 'docs',
            'workspace' => 'feature-docs',
        ])
        ->and($adapter->deliveries)->toHaveCount(1);
});

it('infers an app target from the current directory on gateway callers', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
    ]);

    $appPath = sys_get_temp_dir().'/orbit-agent-ide-app-cwd-'.bin2hex(random_bytes(4));
    $childPath = "{$appPath}/current";
    File::ensureDirectoryExists($childPath);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => $appPath,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $previousCwd = getcwd();
    chdir($childPath);

    try {
        $exitCode = Artisan::call('agent-ide:message', [
            'message' => 'Ship the docs',
            '--json' => true,
        ]);
    } finally {
        if (is_string($previousCwd)) {
            chdir($previousCwd);
        }

        File::deleteDirectory($appPath);
    }

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['agent_ide']['target'])->toMatchArray([
            'app' => 'docs',
            'workspace' => null,
        ])
        ->and($adapter->deliveries)->toHaveCount(1);
});

it('fails when the target app has no effective adapter', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'none'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload)->toMatchArray([
            'error' => [
                'code' => 'no_effective_adapter',
                'message' => 'No Agent IDE adapter is configured for docs.',
                'meta' => [
                    'app' => 'docs',
                    'workspace' => null,
                ],
            ],
        ])
        ->and($adapter->deliveries)->toBeEmpty();
});

it('fails when the adapter cannot find an active session', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    $adapter->session = null;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload)->toMatchArray([
            'error' => [
                'code' => 'no_active_session',
                'message' => 'No active Agent IDE session found for docs.',
                'meta' => [
                    'app' => 'docs',
                    'workspace' => null,
                    'adapter' => 'opencode',
                ],
            ],
        ])
        ->and($adapter->deliveries)->toBeEmpty();
});

it('does not mutate app node or process state while messaging', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(App::query()->findOrFail($app->id)->agent_ide_config)->toBe(['adapter' => 'opencode'])
        ->and(Node::query()->findOrFail($node->id)->agent_ide_config)->toBe(['adapter' => 'polyscope'])
        ->and(DB::table('processes')->count())->toBe(0)
        ->and(DB::table('workspaces')->count())->toBe(0);
});

it('fails for non-gateway callers without configured gateway settings', function (): void {
    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload)->toMatchArray([
            'error' => [
                'code' => 'gateway_unavailable',
                'message' => 'Gateway connection is required to send Agent IDE messages.',
                'meta' => [],
            ],
        ])
        ->and($adapter->deliveries)->toBeEmpty();
});

it('forwards configured control callers through the typed gateway request', function (): void {
    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'is_local' => true,
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $mockClient = MockClient::global([
        SendAgentIdeMessageRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'app',
                        'target' => [
                            'app' => 'docs',
                            'workspace' => null,
                            'node' => 'app-1',
                        ],
                        'session' => [
                            'id' => 'sess_123',
                            'status' => 'active',
                        ],
                        'delivery' => [
                            'status' => 'sent',
                            'message_bytes' => 13,
                            'input' => 'argument',
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['agent_ide']['target']['app'])->toBe('docs')
        ->and($adapter->deliveries)->toBeEmpty();

    $mockClient->assertSent(SendAgentIdeMessageRequest::class);
});

it('forwards configured app callers through the typed gateway request', function (): void {
    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'is_local' => true,
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $mockClient = MockClient::global([
        SendAgentIdeMessageRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'app',
                        'target' => [
                            'app' => 'docs',
                            'workspace' => null,
                            'node' => 'app-2',
                        ],
                        'session' => [
                            'id' => 'sess_789',
                            'status' => 'active',
                        ],
                        'delivery' => [
                            'status' => 'sent',
                            'message_bytes' => 13,
                            'input' => 'argument',
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['agent_ide']['target']['node'])->toBe('app-2')
        ->and($adapter->deliveries)->toBeEmpty();

    $mockClient->assertSent(SendAgentIdeMessageRequest::class);
});

it('forwards configured workspace targets through the typed gateway request', function (): void {
    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'is_local' => true,
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $mockClient = MockClient::global([
        SendAgentIdeMessageRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'workspace',
                        'target' => [
                            'app' => 'docs',
                            'workspace' => 'feature-docs',
                            'node' => 'app-1',
                        ],
                        'session' => [
                            'id' => 'sess_999',
                            'status' => 'active',
                        ],
                        'delivery' => [
                            'status' => 'sent',
                            'message_bytes' => 13,
                            'input' => 'argument',
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--workspace' => 'feature-docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['agent_ide']['target']['workspace'])->toBe('feature-docs')
        ->and($adapter->deliveries)->toBeEmpty();

    $mockClient->assertSent(fn (SendAgentIdeMessageRequest $request): bool => $request->workspace === 'feature-docs'
        && $request->app === null);
});

it('forwards current working directory context through the typed gateway request', function (): void {
    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'is_local' => true,
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    $directory = sys_get_temp_dir().'/orbit-agent-ide-forward-cwd-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($directory);
    $expectedPath = realpath($directory) ?: $directory;

    $mockClient = MockClient::global([
        SendAgentIdeMessageRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'workspace',
                        'target' => [
                            'app' => 'docs',
                            'workspace' => 'feature-docs',
                            'node' => 'app-1',
                        ],
                        'session' => [
                            'id' => 'sess_cwd',
                            'status' => 'active',
                        ],
                        'delivery' => [
                            'status' => 'sent',
                            'message_bytes' => 13,
                            'input' => 'argument',
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $previousCwd = getcwd();
    chdir($directory);

    try {
        $exitCode = Artisan::call('agent-ide:message', [
            'message' => 'Ship the docs',
            '--json' => true,
        ]);
    } finally {
        if (is_string($previousCwd)) {
            chdir($previousCwd);
        }

        File::deleteDirectory($directory);
    }

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['agent_ide']['target']['workspace'])->toBe('feature-docs');

    $mockClient->assertSent(fn (SendAgentIdeMessageRequest $request): bool => $request->path === $expectedPath);
});

<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Tools\StartToolRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createToolStartLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

describe('tool:start command contract', function (): void {
    it('updates gateway intent and starts the managed tool on the selected node', function (): void {
        createToolStartLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'stopped',
        ]);
        $shell = new ToolStartRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($tool->refresh()->expected_state)->toBe('running')
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'caddy',
                'node' => 'app-1',
                'expected_state' => 'running',
            ])
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain('docker start')
            ->and($shell->scripts[0])->toContain('orbit-caddy')
            ->and($shell->scripts[0])->not->toContain('systemctl');
    });

    it('renders the documented human progress tree', function (): void {
        createToolStartLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'stopped',
        ]);
        app()->instance(RemoteShell::class, new ToolStartRecordingShell);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--node' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('┌  Starting Tool')
            ->and($output)->toContain('○  Resolve target')
            ->and($output)->toContain('○  Read gateway tool configuration')
            ->and($output)->toContain('○  Run command action')
            ->and($output)->toContain('●  Resolved target')
            ->and($output)->toContain('●  Read gateway tool configuration')
            ->and($output)->toContain('●  Ran command action')
            ->and($output)->toContain('└  Tool started')
            ->and($output)->toContain('Started caddy on app-1.');
    });

    it('rejects tools without a start action before changing intent', function (): void {
        createToolStartLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'gh',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolStartRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:start', ['tool' => 'gh', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'gh',
                'action' => 'start',
            ])
            ->and($tool->refresh()->expected_state)->toBe('installed')
            ->and($shell->scripts)->toBe([]);
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolStartLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            StartToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'caddy',
                            'node' => 'app-1',
                            'expected_state' => 'running',
                            'observed_state' => null,
                            'version' => null,
                            'managed' => true,
                            'endpoints' => [],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['expected_state'])->toBe('running');
    });

    it('resolves app slug selectors to the owning node', function (): void {
        createToolStartLocalNode('gateway');
        $node = createToolStartTargetWithApp('app-from-slug', 'testabc', 'testabc.dev1');
        app()->instance(RemoteShell::class, new ToolStartRecordingShell);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--app' => 'testabc', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe($node->name);
    });

    it('resolves app domain selectors to the owning node', function (): void {
        createToolStartLocalNode('gateway');
        $node = createToolStartTargetWithApp('app-from-domain', 'testabc', 'testabc.example.test');
        app()->instance(RemoteShell::class, new ToolStartRecordingShell);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--app' => 'testabc.example.test', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe($node->name);
    });

    it('resolves app slug plus node tld selectors to the owning node', function (): void {
        createToolStartLocalNode('gateway');
        $node = createToolStartTargetWithApp('app-from-tld', 'testabc', null, 'dev1');
        app()->instance(RemoteShell::class, new ToolStartRecordingShell);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--app' => 'testabc.dev1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe($node->name);
    });

    it('allows matching app and node selectors', function (): void {
        createToolStartLocalNode('gateway');
        $node = createToolStartTargetWithApp('app-matching-node', 'testabc');
        app()->instance(RemoteShell::class, new ToolStartRecordingShell);

        $exitCode = Artisan::call('tool:start', [
            'tool' => 'caddy',
            '--app' => 'testabc',
            '--node' => $node->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe($node->name);
    });

    it('rejects mismatched app and node selectors before side effects', function (): void {
        createToolStartLocalNode('gateway');
        createToolStartTargetWithApp('app-mismatch-owner', 'testabc');
        $otherNode = createToolStartTargetWithApp('app-mismatch-other', 'otherabc');
        $shell = new ToolStartRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:start', [
            'tool' => 'caddy',
            '--app' => 'testabc',
            '--node' => $otherNode->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toMatchArray([
                'field' => 'app',
                'value' => 'testabc',
                'node' => $otherNode->name,
            ])
            ->and($shell->scripts)->toBe([]);
    });

    it('uses explicit node selectors when app is absent', function (): void {
        createToolStartLocalNode('gateway');
        $node = createToolStartTargetWithApp('app-explicit-node', 'testabc');
        app()->instance(RemoteShell::class, new ToolStartRecordingShell);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--node' => $node->name, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe($node->name);
    });

    it('uses node default when no explicit target is supplied', function (): void {
        createToolStartLocalNode('gateway');
        $node = createToolStartTargetWithApp('app-default-node', 'testabc');
        LocalNodeDefault::query()->create(['default_node_name' => $node->name]);
        app()->instance(RemoteShell::class, new ToolStartRecordingShell);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe($node->name);
    });

    it('uses gateway-known caller identity when no explicit target or node default exists', function (): void {
        config(['orbit.is_gateway' => false]);
        createToolStartLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            ShowGatewayIdentityRequest::class => MockResponse::make(toolStartGatewayIdentityEnvelope('control-self'), 200),
            StartToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'caddy',
                            'node' => 'control-self',
                            'expected_state' => 'running',
                            'observed_state' => null,
                            'version' => null,
                            'managed' => true,
                            'endpoints' => [],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('control-self');

        $mock->assertSent(fn (StartToolRequest $request): bool => $request->body()->all() === [
            'node' => 'control-self',
        ]);
    });

    it('fails through gateway authorization when self target cannot manage the selected tool', function (): void {
        config(['orbit.is_gateway' => false]);
        createToolStartLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowGatewayIdentityRequest::class => MockResponse::make(toolStartGatewayIdentityEnvelope('control-self'), 200),
            StartToolRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This node is not authorized to manage tools.',
                    'meta' => ['node' => 'control-self'],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['meta'])->toBe(['node' => 'control-self']);
    });

    it('does not fall back to the only visible app node without an explicit source, node default, or self identity', function (): void {
        createToolStartLocalNode('gateway');
        createToolStartTargetWithApp('app-only-visible', 'testabc');
        $shell = new ToolStartRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['fields' => ['target']])
            ->and($shell->scripts)->toBe([]);
    });

    it('shows remote action diagnostics and log and doctor recovery guidance in human mode', function (): void {
        createToolStartLocalNode('gateway');
        createToolStartTargetWithApp('app-failing-caddy', 'testabc');
        app()->instance(RemoteShell::class, new ToolStartRecordingShell(exitCode: 7, stderr: 'docker start orbit-caddy failed'));

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--node' => 'app-failing-caddy']);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("Tool 'caddy' start failed on node 'app-failing-caddy'.")
            ->and($output)->toContain('Exit code: 7')
            ->and($output)->toContain('docker start orbit-caddy failed')
            ->and($output)->toContain('orbit tool:logs caddy --node=app-failing-caddy')
            ->and($output)->toContain('orbit doctor --family=tool --restore');
    });
});

function createToolStartTargetWithApp(string $nodeName, string $appName, ?string $domain = null, ?string $tld = null): Node
{
    $node = Node::factory()->create([
        'name' => $nodeName,
        'role' => 'control',
        'status' => 'active',
        'tld' => $tld,
    ]);

    assignToolStartAppHostRole(
        $node,
        $domain === null ? 'app-development' : 'app-production',
        $domain === null ? ['tld' => $tld ?? 'test'] : [],
    );

    App::factory()->create([
        'name' => $appName,
        'domain' => $domain,
        'node_id' => $node->id,
    ]);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'caddy',
        'expected_state' => 'installed',
    ]);

    return $node;
}

function assignToolStartAppHostRole(Node $node, string $role = 'app-development', array $settings = ['tld' => 'test']): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

/**
 * @return array<string, mixed>
 */
function toolStartGatewayIdentityEnvelope(string $selfName): array
{
    return [
        'success' => [
            'data' => [
                'self' => [
                    'name' => $selfName,
                    'role' => 'control',
                    'status' => 'active',
                ],
                'gateway' => [
                    'name' => 'gateway-1',
                    'role' => 'gateway',
                    'status' => 'active',
                ],
            ],
        ],
    ];
}

final class ToolStartRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: '', stderr: $this->stderr, durationMs: 1);
    }
}

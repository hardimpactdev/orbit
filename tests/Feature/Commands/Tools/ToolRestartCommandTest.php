<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\RestartToolRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createToolRestartLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

describe('tool:restart command contract', function (): void {
    it('restarts the managed tool without changing gateway intent', function (): void {
        createToolRestartLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'running',
            'expected_version' => '2.10.2',
        ]);
        $shell = new ToolRestartRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($tool->refresh()->expected_state)->toBe('running')
            ->and($tool->expected_version)->toBe('2.10.2')
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'caddy',
                'node' => 'app-1',
                'expected_state' => 'running',
                'version' => '2.10.2',
            ])
            ->and($shell->scripts)->toBe(['sudo systemctl restart caddy']);
    });

    it('falls back to stop then start when no native restart command exists', function (): void {
        createToolRestartLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'supervisor',
            'expected_state' => 'running',
        ]);
        $shell = new ToolRestartRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'supervisor', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($tool->refresh()->expected_state)->toBe('running')
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'supervisor',
                'node' => 'app-1',
                'expected_state' => 'running',
            ])
            ->and($shell->scripts)->toBe([
                'sudo systemctl stop supervisor',
                'sudo systemctl start supervisor',
            ]);
    });

    it('renders the documented human progress tree and concise success prose', function (): void {
        createToolRestartLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'running',
        ]);
        app()->instance(RemoteShell::class, new ToolRestartRecordingShell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--node' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('┌  Restarting Tool')
            ->and($output)->toContain('○  Resolve target')
            ->and($output)->toContain('○  Read gateway tool configuration')
            ->and($output)->toContain('○  Run command action')
            ->and($output)->toContain('●  Resolved target')
            ->and($output)->toContain('●  Read gateway tool configuration')
            ->and($output)->toContain('●  Ran command action')
            ->and($output)->toContain('└  Tool restarted')
            ->and($output)->toContain('Restarted caddy on app-1.')
            ->and($output)->not->toContain('expected_state')
            ->and($output)->not->toContain('observed_state')
            ->and($output)->not->toContain('managed')
            ->and($output)->not->toContain('version');
    });

    it('rejects tools without a restart path before changing intent', function (): void {
        createToolRestartLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'gh',
            'expected_state' => 'running',
        ]);
        $shell = new ToolRestartRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'gh', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'gh',
                'action' => 'restart',
            ])
            ->and($tool->refresh()->expected_state)->toBe('running')
            ->and($shell->scripts)->toBe([]);
    });

    it('preserves gateway configuration and diagnostics when restart application fails', function (): void {
        createToolRestartLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'running',
            'expected_version' => '2.10.2',
            'config' => ['endpoints' => [['name' => 'http', 'url' => 'https://example.test']]],
        ]);
        app()->instance(RemoteShell::class, new ToolRestartRecordingShell(exitCode: 7, stderr: 'systemctl restart caddy failed'));

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.remote_action_failed')
            ->and($payload['error']['meta'])->toMatchArray([
                'exit_code' => 7,
                'stderr' => 'systemctl restart caddy failed',
            ])
            ->and($tool->refresh()->expected_state)->toBe('running')
            ->and($tool->expected_version)->toBe('2.10.2')
            ->and($tool->config)->toBe(['endpoints' => [['name' => 'http', 'url' => 'https://example.test']]]);
    });

    it('shows remote action diagnostics and log and doctor recovery guidance in human mode', function (): void {
        createToolRestartLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'running',
        ]);
        app()->instance(RemoteShell::class, new ToolRestartRecordingShell(exitCode: 7, stderr: 'systemctl restart caddy failed'));

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--node' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("Tool 'caddy' restart failed on node 'app-1'.")
            ->and($output)->toContain('Exit code: 7')
            ->and($output)->toContain('systemctl restart caddy failed')
            ->and($output)->toContain('orbit tool:logs caddy --node=app-1')
            ->and($output)->toContain('orbit doctor --fix --family=tool --restore')
            ->and($output)->toContain('Retry with orbit tool:restart caddy --node=app-1');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolRestartLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            RestartToolRequest::class => MockResponse::make([
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

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['expected_state'])->toBe('running');
    });
});

final class ToolRestartRecordingShell implements RemoteShell
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

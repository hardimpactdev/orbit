<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\StartToolRequest;
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

function createToolStartLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('tool:start command contract', function (): void {
    it('updates gateway intent and starts the managed tool on the selected node', function (): void {
        createToolStartLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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
            ->and($shell->scripts[0])->toContain('systemctl start caddy');
    });

    it('renders the documented human progress tree', function (): void {
        createToolStartLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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
            ->and($output)->toContain('○  Read gateway tool intent')
            ->and($output)->toContain('○  Run command action')
            ->and($output)->toContain('●  Resolved target')
            ->and($output)->toContain('●  Read gateway tool intent')
            ->and($output)->toContain('●  Ran command action')
            ->and($output)->toContain('└  Tool started')
            ->and($output)->toContain('Started caddy on app-1.');
    });

    it('rejects tools without a start action before changing intent', function (): void {
        createToolStartLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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
});

final class ToolStartRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

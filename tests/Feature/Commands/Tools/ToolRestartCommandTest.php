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
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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

    it('rejects tools without a restart path before changing intent', function (): void {
        createToolRestartLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

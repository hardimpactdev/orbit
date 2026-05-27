<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\ReconfigureToolRequest;
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

function createToolReconfigureLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

describe('tool:reconfigure command contract', function (): void {
    it('rejects tools without a reconfigure action', function (): void {
        createToolReconfigureLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        $shell = new ToolReconfigureRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:reconfigure', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'redis',
                'action' => 'reconfigure',
            ])
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects uninstalled tools', function (): void {
        createToolReconfigureLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $shell = new ToolReconfigureRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:reconfigure', ['tool' => 'polyscope-server', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.not_found')
            ->and($shell->scripts)->toBe([]);
    });

    it('reconfigures opencode-server with password and config', function (): void {
        createToolReconfigureLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'expected_state' => 'running',
            'config' => ['port' => 4096, 'hostname' => '127.0.0.1'],
        ]);
        $shell = new ToolReconfigureRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:reconfigure', ['tool' => 'opencode-server', '--node' => 'app-1', '--password' => 'newpass', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'opencode-server',
                'node' => 'app-1',
                'action' => 'reconfigured',
            ])
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain('opencode-server.service')
            ->and($shell->scripts[0])->toContain('OPENCODE_SERVER_USERNAME=')
            ->and($shell->scripts[0])->toContain('OPENCODE_SERVER_PASSWORD=newpass')
            ->and($shell->scripts[0])->toContain('systemctl --user restart opencode-server');
    });

    it('reconfigures polyscope-server with unit restart', function (): void {
        createToolReconfigureLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'polyscope-server',
            'expected_state' => 'running',
        ]);
        $shell = new ToolReconfigureRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:reconfigure', ['tool' => 'polyscope-server', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'polyscope-server',
                'node' => 'app-1',
                'action' => 'reconfigured',
            ])
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain('polyscope-server.service')
            ->and($shell->scripts[0])->toContain('systemctl --user daemon-reload')
            ->and($shell->scripts[0])->toContain('systemctl --user restart polyscope-server');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolReconfigureLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ReconfigureToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'polyscope-server',
                            'node' => 'app-1',
                            'action' => 'reconfigured',
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:reconfigure', ['tool' => 'polyscope-server', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('polyscope-server');
    });
});

final class ToolReconfigureRecordingShell implements RemoteShell
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

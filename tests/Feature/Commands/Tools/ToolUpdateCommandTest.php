<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\UpdateToolRequest;
use App\Http\Gateway\Requests\Tools\UpdateToolsBulkRequest;
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

function createToolUpdateLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('tool:update command contract', function (): void {
    it('updates a managed tool on a node', function (): void {
        createToolUpdateLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
            'expected_version' => '6.0',
        ]);
        $shell = new ToolUpdateRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:update', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'redis',
                'node' => 'app-1',
            ])
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain('docker compose')
            ->and($shell->scripts[0])->toContain('pull')
            ->and($shell->scripts[0])->toContain('up -d');
    });

    it('rejects tools without an update action', function (): void {
        createToolUpdateLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'docker',
            'expected_state' => 'running',
        ]);
        $shell = new ToolUpdateRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:update', ['tool' => 'docker', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'docker',
                'action' => 'update',
            ])
            ->and($shell->scripts)->toBe([]);
    });

    it('updates expected version when specified', function (): void {
        createToolUpdateLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
            'expected_version' => '6.0',
        ]);
        $shell = new ToolUpdateRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:update', ['tool' => 'redis', '--node' => 'app-1', '--expected-version' => '7.0', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['version'])->toBe('7.0')
            ->and($tool->fresh()->expected_version)->toBe('7.0');
    });

    it('keeps requested version intent when remote update fails', function (): void {
        createToolUpdateLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
            'expected_version' => '6.0',
        ]);
        $shell = new ToolUpdateRecordingShell(exitCode: 42, stderr: 'pull failed');
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:update', ['tool' => 'redis', '--node' => 'app-1', '--expected-version' => '7.0', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.remote_action_failed')
            ->and($tool->fresh()->expected_version)->toBe('7.0');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createToolUpdateLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            UpdateToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'redis',
                            'node' => 'app-1',
                            'version' => '7.0',
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:update', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('redis');
    });

    it('bulk updates skip tools without a latest supported version', function (): void {
        createToolUpdateLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
            'expected_version' => '6.0',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'docker',
            'expected_state' => 'running',
        ]);
        $shell = new ToolUpdateRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:update', ['--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['updated'])->toBe([])
            ->and($payload['success']['data']['skipped'])->toHaveCount(2)
            ->and($payload['success']['data']['failed'])->toBe([])
            ->and($shell->scripts)->toBe([]);
    });

    it('forwards bulk update for non-gateway callers', function (): void {
        createToolUpdateLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            UpdateToolsBulkRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'updated' => [],
                        'skipped' => [
                            ['tool' => 'redis', 'node' => 'app-1', 'reason' => 'null_latest_version'],
                        ],
                        'failed' => [],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:update', ['--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['skipped'])->toHaveCount(1);
    });
});

final class ToolUpdateRecordingShell implements RemoteShell
{
    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
    ) {}

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

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: '', stderr: $this->stderr, durationMs: 1);
    }
}

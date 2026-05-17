<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\InstallToolRequest;
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

function createToolInstallLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

describe('tool:install command contract', function (): void {
    it('installs a managed tool on a node', function (): void {
        createToolInstallLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $shell = new ToolInstallRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'redis')->exists())->toBeTrue()
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'redis',
                'node' => 'app-1',
                'state' => 'installed',
            ])
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain('docker compose')
            ->and($shell->scripts[0])->toContain('pull')
            ->and($shell->scripts[0])->toContain('up -d');
    });

    it('renders the documented human progress tree', function (): void {
        createToolInstallLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        app()->instance(RemoteShell::class, new ToolInstallRecordingShell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'redis', '--node' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('┌  Installing Tool')
            ->and($output)->toContain('○  Resolve target')
            ->and($output)->toContain('○  Read gateway tool intent')
            ->and($output)->toContain('○  Run command action')
            ->and($output)->toContain('●  Resolved target')
            ->and($output)->toContain('●  Read gateway tool intent')
            ->and($output)->toContain('●  Ran command action')
            ->and($output)->toContain('└  Tool installed')
            ->and($output)->toContain('Installed redis on app-1 (installed).');
    });

    it('rejects tools without an install action', function (): void {
        createToolInstallLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $shell = new ToolInstallRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'caddy', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'caddy',
                'action' => 'install',
            ])
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects invalid status values', function (): void {
        createToolInstallLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $shell = new ToolInstallRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'redis', '--node' => 'app-1', '--status' => 'invalid', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe([
                'field' => 'status',
                'value' => 'invalid',
                'reason' => 'unsupported_value',
            ])
            ->and(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('generates and stores credentials for credential-bearing tools', function (): void {
        createToolInstallLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $shell = new ToolInstallCredentialRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'opencode-server', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'opencode-server')->exists())->toBeTrue()
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'opencode-server',
                'node' => 'app-1',
                'state' => 'installed',
            ])
            ->and($shell->scripts)->toHaveCount(2)
            ->and($shell->scripts[0])->toContain('opencode-server.service')
            ->and($shell->scripts[1])->toContain('cat <<EOF');

        $row = NodeTool::query()->where('node_id', $node->id)->where('name', 'opencode-server')->first();
        expect($row->credentials)->toBe([
            'fields' => [
                'Host' => '127.0.0.1',
                'Port' => '4096',
                'Username' => '(no auth)',
                'Password' => '(no auth)',
            ],
        ]);
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolInstallLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            InstallToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'redis',
                            'node' => 'app-1',
                            'state' => 'installed',
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:install', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('redis');
    });

    it('requires an explicit target source in non-interactive mode', function (): void {
        createToolInstallLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $shell = new ToolInstallRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'redis', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['fields' => ['target']])
            ->and(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });
});

final class ToolInstallRecordingShell implements RemoteShell
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

final class ToolInstallCredentialRecordingShell implements RemoteShell
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

        if (str_contains($script, 'cat <<EOF')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'Host' => '127.0.0.1',
                    'Port' => '4096',
                    'Username' => '(no auth)',
                    'Password' => '(no auth)',
                ]),
                stderr: '',
                durationMs: 1,
            );
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

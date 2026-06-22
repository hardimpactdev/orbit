<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_CODEX_CALLER_WG_IP = '10.44.0.90';

/**
 * @param  list<string>  $permissions
 */
function grantAppCodexAccess(Node $caller, Node $servingNode, array $permissions = ['app:codex']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('AppCodexController', function (): void {
    it('adds an app project to Codex App config on a macOS non-gateway target', function (): void {
        $caller = Node::factory()->operator()->create([
            'name' => 'caller',
            'host' => APP_CODEX_CALLER_WG_IP,
            'wireguard_address' => APP_CODEX_CALLER_WG_IP,
        ]);
        $appNode = createTestAppHostNode(['name' => 'app-node', 'wireguard_address' => '10.44.0.20']);
        $target = Node::factory()->operator()->create([
            'name' => 'mini',
            'platform' => 'macos_15-5',
            'wireguard_address' => '10.44.0.24',
            'user' => 'nicky',
        ]);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
        ]);
        grantAppCodexAccess($caller, $appNode);
        grantAppCodexAccess($caller, $target);
        $shell = new AppCodexRecordingShell('{}');
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', "/api/apps/{$app->name}/codex", [
            'node' => 'mini',
        ], [], [], ['REMOTE_ADDR' => APP_CODEX_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.codex_project.app', 'docs')
            ->assertJsonPath('success.data.codex_project.node', 'mini')
            ->assertJsonPath('success.data.codex_project.remote_path', '/home/orbit/apps/docs')
            ->assertJsonPath('success.data.codex_project.ssh_alias', 'app-node')
            ->assertJsonPath('success.data.codex_project.added', true);

        $writtenConfig = json_decode($shell->writes[0] ?? '', associative: true, flags: JSON_THROW_ON_ERROR);

        expect($writtenConfig['remoteConnections'])->toBe([
            [
                'sshAlias' => 'app-node',
                'projects' => [
                    [
                        'remotePath' => '/home/orbit/apps/docs',
                        'label' => 'docs',
                    ],
                ],
            ],
        ])
            ->and($shell->scripts)->sequence(
                fn ($script) => $script->toContain('cat ~/.codex/codex-app/config.json'),
                fn ($script) => $script->toContain('config="$HOME/.codex/codex-app/config.json"'),
                fn ($script) => $script->toContain('codex://codex-app/apply-config'),
            );
    });

    it('rejects non-macOS Codex App targets before remote shell work', function (): void {
        $caller = Node::factory()->operator()->create([
            'name' => 'caller',
            'host' => APP_CODEX_CALLER_WG_IP,
            'wireguard_address' => APP_CODEX_CALLER_WG_IP,
        ]);
        $appNode = createTestAppHostNode(['name' => 'app-node']);
        $target = Node::factory()->operator()->create([
            'name' => 'linux-operator',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        grantAppCodexAccess($caller, $appNode);
        grantAppCodexAccess($caller, $target);
        $shell = new AppCodexRecordingShell('{}');
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', "/api/apps/{$app->name}/codex", [
            'node' => 'linux-operator',
        ], [], [], ['REMOTE_ADDR' => APP_CODEX_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'tool.unsupported_on_node');

        expect($shell->scripts)->toBe([]);
    });

    it('rejects malformed Codex App config before writing', function (): void {
        $caller = Node::factory()->operator()->create([
            'name' => 'caller',
            'host' => APP_CODEX_CALLER_WG_IP,
            'wireguard_address' => APP_CODEX_CALLER_WG_IP,
        ]);
        $appNode = createTestAppHostNode(['name' => 'app-node', 'wireguard_address' => '10.44.0.20']);
        $target = Node::factory()->operator()->create([
            'name' => 'mini',
            'platform' => 'macos_15-5',
            'wireguard_address' => '10.44.0.24',
            'user' => 'nicky',
        ]);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
        ]);
        grantAppCodexAccess($caller, $appNode);
        grantAppCodexAccess($caller, $target);
        $shell = new AppCodexRecordingShell('{not-json');
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', "/api/apps/{$app->name}/codex", [
            'node' => 'mini',
        ], [], [], ['REMOTE_ADDR' => APP_CODEX_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'codex_app.config_read_failed')
            ->assertJsonPath('error.meta.path', '~/.codex/codex-app/config.json');

        expect($shell->writes)->toBe([]);
    });

    it('returns a warning when the Codex App apply callback fails after writing config', function (): void {
        $caller = Node::factory()->operator()->create([
            'name' => 'caller',
            'host' => APP_CODEX_CALLER_WG_IP,
            'wireguard_address' => APP_CODEX_CALLER_WG_IP,
        ]);
        $appNode = createTestAppHostNode(['name' => 'app-node', 'wireguard_address' => '10.44.0.20']);
        $target = Node::factory()->operator()->create([
            'name' => 'mini',
            'platform' => 'macos_15-5',
            'wireguard_address' => '10.44.0.24',
            'user' => 'nicky',
        ]);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
        ]);
        grantAppCodexAccess($caller, $appNode);
        grantAppCodexAccess($caller, $target);
        $shell = new AppCodexRecordingShell('{}', failApply: true);
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', "/api/apps/{$app->name}/codex", [
            'node' => 'mini',
        ], [], [], ['REMOTE_ADDR' => APP_CODEX_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.codex_project.added', true)
            ->assertJsonPath('success.meta.warnings.0.code', 'codex_app.apply_failed')
            ->assertJsonPath('success.meta.warnings.0.meta.node', 'mini');

        expect($shell->writes)->toHaveCount(1);
    });
});

final class AppCodexRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<string> */
    public array $writes = [];

    public function __construct(
        private readonly string $config,
        private readonly bool $failApply = false,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        if (is_string($options['input'] ?? null)) {
            $this->writes[] = $options['input'];
        }

        if (str_contains($script, 'cat ~/.codex/codex-app/config.json')) {
            return new RemoteShellResult(exitCode: 0, stdout: $this->config, stderr: '', durationMs: 1);
        }

        if ($this->failApply && str_contains($script, 'codex://codex-app/apply-config')) {
            return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'callback unavailable', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

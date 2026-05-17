<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\ToolsFixer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('ToolsFixer', function (): void {
    it('starts service-backed tools when lifecycle intent expects running', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'running',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.lifecycle_state_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool caddy lifecycle state differs from gateway intent.',
            detail: [
                'tool' => 'caddy',
                'expected_state' => 'running',
                'observed_state' => 'stopped',
            ],
        ));

        expect($action)->toMatchArray([
            'family' => 'tool',
            'node' => 'app-1',
            'key' => 'tool.lifecycle_state_mismatch',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts)->toBe(['sudo systemctl start caddy']);
    });

    it('skips issue codes without catalog-declared repair commands', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool caddy managed configuration differs from gateway intent.',
            detail: ['tool' => 'caddy'],
        ));

        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('rewrites managed config when the row contains complete content intent', function (): void {
        $content = "port 6379\n";
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/redis/redis.conf',
                    'hash' => hash('sha256', $content),
                    'content' => $content,
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool redis managed configuration differs from gateway intent.',
            detail: [
                'tool' => 'redis',
                'path' => '/etc/redis/redis.conf',
            ],
        ));

        expect($action)->toMatchArray([
            'family' => 'tool',
            'node' => 'app-1',
            'key' => 'tool.config_mismatch',
            'status' => 'completed',
        ])->and($shell->scripts[0])->toContain("sudo install -d -m 0755 '/etc/redis'")
            ->and($shell->scripts[0])->toContain("base64 -d | sudo tee '/etc/redis/redis.conf' >/dev/null");
    });

    it('does not repair managed config when content does not match declared hash', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/redis/redis.conf',
                    'hash' => str_repeat('a', 64),
                    'content' => "port 6379\n",
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_missing',
            kind: DriftKind::Missing,
            summary: 'Tool redis managed configuration is missing.',
            detail: ['tool' => 'redis'],
        ));

        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('rewrites managed secret material when the row contains complete secret intent', function (): void {
        $secret = 'generated-password';
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => hash('sha256', $secret),
                    'content' => $secret,
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.credentials_missing',
            kind: DriftKind::Missing,
            summary: 'Tool opencode-server managed credential material is missing.',
            detail: [
                'tool' => 'opencode-server',
                'path' => '/home/orbit/.config/opencode-server/password',
            ],
        ));

        expect($action)->toMatchArray([
            'family' => 'tool',
            'node' => 'app-1',
            'key' => 'tool.credentials_missing',
            'status' => 'completed',
        ])->and($shell->scripts[0])->toContain("sudo install -d -m 0700 '/home/orbit/.config/opencode-server'")
            ->and($shell->scripts[0])->toContain("base64 -d | sudo tee '/home/orbit/.config/opencode-server/password' >/dev/null")
            ->and($shell->scripts[0])->toContain("sudo chmod 0600 '/home/orbit/.config/opencode-server/password'");
    });

    it('does not repair managed secret material when content does not match declared hash', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => str_repeat('a', 64),
                    'content' => 'generated-password',
                ],
            ],
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.credentials_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool opencode-server managed credential material differs from gateway intent.',
            detail: ['tool' => 'opencode-server'],
        ));

        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('installs missing docker-managed tools through catalog install script', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.capability_missing',
            kind: DriftKind::Missing,
            summary: 'Tool redis is missing on the target node.',
            detail: ['tool' => 'redis'],
        ));

        expect($action)->toMatchArray([
            'family' => 'tool',
            'node' => 'app-1',
            'key' => 'tool.capability_missing',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts[0])->toContain("docker compose -f '/opt/orbit/docker-compose.yml' pull 'redis'")
            ->and($shell->scripts[0])->toContain("docker compose -f '/opt/orbit/docker-compose.yml' up -d 'redis'");
    });

    it('returns null for capability missing when no install script exists', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'gh',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.capability_missing',
            kind: DriftKind::Missing,
            summary: 'Tool gh is missing on the target node.',
            detail: ['tool' => 'gh'],
        ));

        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });
});

final class ToolsFixerRemoteShell implements RemoteShell
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

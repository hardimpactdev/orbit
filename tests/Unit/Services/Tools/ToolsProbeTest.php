<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\ToolsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function toolProbeIssue(array $drift, string $key): mixed
{
    return collect($drift)->first(fn ($entry): bool => $entry->key === $key);
}

describe('ToolsProbe', function (): void {
    it('has key and label', function (): void {
        $probe = new ToolsProbe;

        expect($probe->key())->toBe('tool')
            ->and($probe->label())->toBe('Tools');
    });

    it('detects incomplete tool records', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => '',
            'expected_state' => '',
        ]);

        $drift = (new ToolsProbe)->diff($tool, (new ToolsProbe)->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('requires active app or gateway nodes', function (): void {
        $node = Node::factory()->create(['role' => 'control', 'status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'redis']);

        $drift = (new ToolsProbe)->diff($tool, (new ToolsProbe)->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.node_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('requires known tool catalog definitions', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'not-a-tool']);

        $drift = (new ToolsProbe)->diff($tool, (new ToolsProbe)->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.definition_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects missing live capabilities', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'redis']);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 1));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.capability_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('passes when live capability exists', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'redis']);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/bin/redis\n"));

        $snapshot = $probe->introspect($tool);

        expect($probe->diff($tool, $snapshot))->toBe([]);
    });

    it('detects version drift when the catalog tracks versions', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_version' => '7.2',
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/bin/redis-server\t6.0.16\n"));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.version_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.version_mismatch')?->detail)->toMatchArray([
                'expected_version' => '7.2',
                'observed_version' => '6.0.16',
            ]);
    });

    it('detects lifecycle state drift for running tools', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/bin/redis-server\t7.2.0\tstopped\n"));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.lifecycle_state_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.lifecycle_state_mismatch')?->detail)->toMatchArray([
                'expected_state' => 'running',
                'observed_state' => 'stopped',
            ]);
    });

    it('detects missing managed config files when config intent declares a path and hash', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/redis/redis.conf',
                    'hash' => str_repeat('a', 64),
                ],
            ],
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/bin/redis-server\t7.2.0\trunning\t0\t\n"));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.config_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects managed config hash mismatches', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/redis/redis.conf',
                    'hash' => str_repeat('a', 64),
                ],
            ],
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/bin/redis-server\t7.2.0\trunning\t1\t".str_repeat('b', 64)."\n"));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.config_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.config_mismatch')?->detail)->toMatchArray([
                'path' => '/etc/redis/redis.conf',
                'expected_hash' => str_repeat('a', 64),
                'observed_hash' => str_repeat('b', 64),
            ]);
    });
});

final readonly class ToolsProbeRemoteShell implements RemoteShell
{
    public function __construct(
        private int $exitCode = 0,
        private string $stdout = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}

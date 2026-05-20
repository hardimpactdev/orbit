<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Tools\ToolsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function toolProbeIssue(array $drift, string $key): mixed
{
    return collect($drift)->first(fn ($entry): bool => $entry->key === $key);
}

function createToolsProbeAppHostNode(array $attributes = []): Node
{
    return createTestAppHostNode([
        'role' => 'app',
        'status' => 'active',
        ...$attributes,
    ]);
}

function createToolsProbeAgentNode(): Node
{
    $node = Node::factory()->create([
        'role' => 'control',
        'status' => 'active',
        'tld' => 'agent',
    ]);
    $node->roleAssignments()->create([
        'role' => 'agent',
        'status' => 'active',
        'settings' => ['tld' => 'agent'],
    ]);

    return $node;
}

/**
 * @return array{target: array{type: string, value: string}, upstream: string, owner_name: string}
 */
function toolsProbeAgentRouteConfig(string $tool): array
{
    $upstream = 'http://127.0.0.1:8080';

    return [
        'target' => ['type' => 'upstream', 'value' => $upstream],
        'upstream' => $upstream,
        'owner_name' => $tool,
    ];
}

function toolsProbeAgentRouteSourceHash(Node $node, string $tool): string
{
    return app(ProxyRouteRenderer::class)->sourceHash(new ProxyRoute([
        'node_id' => $node->id,
        'domain' => "{$tool}.agent",
        'kind' => 'proxy',
        'owner_type' => 'tool',
        'config' => toolsProbeAgentRouteConfig($tool),
    ]));
}

describe('ToolsProbe', function (): void {
    it('has key and label', function (): void {
        $probe = new ToolsProbe;

        expect($probe->key())->toBe('tool')
            ->and($probe->label())->toBe('Tools');
    });

    it('detects incomplete tool records', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => '',
            'expected_state' => '',
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('requires active app or gateway nodes', function (): void {
        $node = Node::factory()->create(['role' => 'control', 'status' => 'active']);
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'redis']);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.node_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('requires known tool catalog definitions', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'not-a-tool']);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.definition_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects missing live capabilities', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'redis']);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 1));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.capability_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('passes when live capability exists', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'redis']);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/bin/redis\n"));

        $snapshot = $probe->introspect($tool);

        expect($probe->diff($tool, $snapshot))->toBe([]);
    });

    it('detects version drift when the catalog tracks versions', function (): void {
        $node = createToolsProbeAppHostNode();
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
        $node = createToolsProbeAppHostNode();
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
        $node = createToolsProbeAppHostNode();
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
        $node = createToolsProbeAppHostNode();
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

    it('detects missing managed credential material when credential intent declares a path and hash', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => str_repeat('a', 64),
                ],
            ],
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/bin/opencode-server\t\trunning\t\t\t0\t\n"));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.credentials_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects managed credential hash mismatches', function (): void {
        $node = createToolsProbeAppHostNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => str_repeat('a', 64),
                ],
            ],
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 0, stdout: "/usr/bin/opencode-server\t\trunning\t\t\t1\t".str_repeat('b', 64)."\n"));

        $snapshot = $probe->introspect($tool);
        $drift = $probe->diff($tool, $snapshot);

        expect(toolProbeIssue($drift, 'tool.credentials_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.credentials_mismatch')?->detail)->toMatchArray([
                'path' => '/home/orbit/.config/opencode-server/password',
                'expected_hash' => str_repeat('a', 64),
                'observed_hash' => str_repeat('b', 64),
            ]);
    });

    it('detects missing agent tool proxy route', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)->toBe(DriftKind::Missing)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
            ]);
    });

    it('passes when agent tool proxy route exists', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => toolsProbeAgentRouteSourceHash($node, 'openclaw'),
            'config' => toolsProbeAgentRouteConfig('openclaw'),
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing'))->toBeNull();
    });

    it('detects drift when agent tool proxy route is owned by a different tool', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'config' => ['owner_name' => 'hermes'],
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
                'route_owner' => 'hermes',
            ]);
    });

    it('detects drift when agent tool proxy route has the wrong kind', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'upstream',
            'source_hash' => str_repeat('a', 64),
            'config' => toolsProbeAgentRouteConfig('openclaw'),
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
                'expected_kind' => 'proxy',
                'observed_kind' => 'upstream',
            ]);
    });

    it('detects drift when agent tool proxy route config or source hash is stale', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => str_repeat('b', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:9999'],
                'upstream' => 'http://127.0.0.1:9999',
                'owner_name' => 'openclaw',
            ],
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_route_missing')?->kind)->toBe(DriftKind::Divergent)
            ->and(toolProbeIssue($drift, 'tool.agent_route_missing')?->detail)->toMatchArray([
                'tool' => 'openclaw',
                'domain' => 'openclaw.agent',
                'expected_upstream' => 'http://127.0.0.1:8080',
                'observed_upstream' => 'http://127.0.0.1:9999',
            ]);
    });

    it('detects missing agent tool credentials metadata', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
            'credentials' => null,
        ]);

        $drift = (new ToolsProbe)->diff($tool, new ProbeSnapshot([]));

        expect(toolProbeIssue($drift, 'tool.agent_credentials_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('passes when agent tool credentials metadata exists', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
            'credentials' => ['fields' => ['url' => 'https://openclaw.agent']],
        ]);

        $drift = (new ToolsProbe)->diff($tool, (new ToolsProbe)->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.agent_credentials_missing'))->toBeNull();
    });

    it('detects missing agent user for agent tools', function (): void {
        $node = createToolsProbeAgentNode();
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'running',
        ]);
        $probe = new ToolsProbe(new ToolsProbeRemoteShell(exitCode: 1));

        $drift = $probe->diff($tool, $probe->introspect($tool));

        expect(toolProbeIssue($drift, 'tool.agent_user_missing')?->kind)->toBe(DriftKind::Missing);
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

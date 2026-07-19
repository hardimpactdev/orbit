<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\Nodes\InstalledAgentArtifact;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\AdoptAction;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\WireGuardPeer;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Nodes\NodesProbe;
use App\Services\Nodes\Roles\NodeToolBaselineConfigRenderer;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Platform\PlatformDetector;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Security\OperationTokenSigner;
use RuntimeException;
use Tests\Fakes\RemoteShellBackedInternalExecutor;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = nodesProbeWithRemoteShell(new NodesProbeRecordingRemoteShell([]));
});

/** @param array<string, mixed> $attributes */
function nodes_probe_node(array $attributes): Node
{
    $attributes['tld'] ??= $attributes['name'];

    return Node::create($attributes);
}

function assignNodesProbeAppHostRole(Node $node, array $settings = []): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => $settings,
    ]);
}

function nodesProbeUseAgentPush(): void {}

function assignNodesProbeGatewayRole(Node $node): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

function assignNodesProbeAgentRole(Node $node, array $settings = []): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'agent',
        'status' => 'active',
        'settings' => $settings,
    ]);
}

function nodesProbeWithRemoteShell(NodesProbeRecordingRemoteShell $remoteShell): NodesProbe
{
    return new NodesProbe(
        localExecutor: nodesProbeLocalExecutor($remoteShell),
    );
}

function nodesProbeLocalExecutor(NodesProbeRecordingRemoteShell $remoteShell): RunsInternalCommands
{
    return new RemoteShellBackedInternalExecutor(new LocalExecutorCommandBuilder, $remoteShell);
}

function nodesProbeWithAgentPush(): NodesProbe
{
    return new NodesProbe(localExecutor: new RemoteLocalExecutor(
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: 'gateway-secret',
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        applicationKey: 'gateway-secret',
    ));
}

function createNodesProbeGatewayNode(): Node
{
    $node = nodes_probe_node([
        'name' => 'gateway',
        'tld' => 'gateway',
        'host' => '10.0.0.2',
        'orbit_path' => '/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.2',
    ]);

    assignNodesProbeGatewayRole($node);

    return $node;
}

describe('interface contract', function (): void {
    it('has key and label', function (): void {
        expect($this->probe->key())->toBe('node');
        expect($this->probe->label())->toBe('Node');
    });

    it('returns empty snapshot from introspect', function (): void {
        $node = new Node(['name' => 'test']);
        $snapshot = $this->probe->introspect($node);

        expect($snapshot->isEmpty())->toBeTrue();
    });

    it('declares reconcile and adopt support', function (): void {
        expect($this->probe->canReconcile())->toBeTrue();
        expect($this->probe->canAdopt())->toBeTrue();
    });
});

describe('record completeness', function (): void {
    it('detects incomplete records', function (): void {
        $id = DB::table('nodes')->insertGetId([
            'name' => 'incomplete',
            'tld' => 'incomplete',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $node = Node::find($id);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));

        expect($drift)->toHaveCount(1);
        expect($drift[0]->key)->toBe('node.record_incomplete');
        expect($drift[0]->kind)->toBe(DriftKind::Missing);
    });

    it('passes complete records', function (): void {
        $node = nodes_probe_node([
            'name' => 'complete',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $recordIncomplete = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.record_incomplete');

        expect($recordIncomplete)->toHaveCount(0);
    });

    it('reports active nodes with missing, invalid, or reserved tlds as incomplete', function (string $tld): void {
        $node = nodes_probe_node([
            'name' => 'legacy-tld',
            'tld' => $tld,
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'inactive',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->forceFill(['status' => NodeStatus::Active]);

        $recordIncomplete = array_values(array_filter(
            $this->probe->diff($node, new ProbeSnapshot([])),
            fn (DriftEntry $entry): bool => $entry->key === 'node.record_incomplete',
        ));

        expect($recordIncomplete)
            ->toHaveCount(1)
            ->and($recordIncomplete[0]->kind)
            ->toBe(DriftKind::Missing);
    })->with([
        'missing' => '',
        'invalid' => 'Invalid_TLD!',
        'reserved' => 'orbit',
    ]);

    it('does not run dependent app live checks when required transport metadata is missing', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'should not run', durationMs: 1),
        ]);
        $probe = nodesProbeWithRemoteShell($remoteShell);

        $node = nodes_probe_node([
            'name' => 'incomplete-prod',
            'host' => '46.225.89.66',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
        ]);
        assignNodesProbeAppHostRole($node, []);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $keys = array_map(fn (DriftEntry $entry): string => $entry->key, $drift);

        expect($keys)
            ->toContain('node.record_incomplete')
            ->and($keys)
            ->not->toContain('node.transport_unreachable')->and($keys)
            ->not->toContain('node.runtime_missing')->and($remoteShell->scripts)->toBe([]);
    });

    it('does not synthesize missing role drift for unassigned nodes', function (): void {
        $node = nodes_probe_node([
            'name' => 'app-no-env',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $missingRoleAssignment = array_filter(
            $drift,
            fn (DriftEntry $e): bool => $e->key === 'node.role_assignment_missing',
        );

        expect($missingRoleAssignment)->toHaveCount(0);
    });

    it('does not require environment for non-app nodes', function (): void {
        $node = nodes_probe_node([
            'name' => 'gateway-no-env',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $recordIncomplete = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.record_incomplete');

        expect($recordIncomplete)->toHaveCount(0);
    });
});

describe('managed Agent intent', function (): void {
    it('detects and clears roleless-only managed intent on a role-bearing node', function (): void {
        $node = Node::factory()
            ->appDev()
            ->create([
                'name' => 'app-1',
                'tld' => 'app-one',
                'managed' => true,
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.6.0.41',
            ]);

        $entry = collect($this->probe->diff($node, new ProbeSnapshot([])))
            ->firstWhere('key', 'node.managed_agent_intent_invalid');

        expect($entry)
            ->toBeInstanceOf(DriftEntry::class)
            ->and($entry?->kind)
            ->toBe(DriftKind::Divergent)
            ->and($entry?->detail['reason'] ?? null)
            ->toBe('role_bearing_node');

        $this->probe->reconcile($node, $entry);

        expect($node->fresh()->managed)->toBeFalse();
    });

    it('detects and clears a stale installed Agent expectation after the last workload role is removed', function (): void {
        $node = Node::factory()
            ->operator()
            ->create([
                'name' => 'former-workload',
                'tld' => 'former-workload',
                'managed' => false,
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.6.0.42',
                'installed_agent' => InstalledAgentArtifact::record([
                    'version' => '1.0.0',
                    'platform' => 'linux-amd64',
                    'sha256' => str_repeat('a', 64),
                    'source' => 'github-release',
                    'build_id' => null,
                    'artifact_url' => 'https://artifacts.orbit.test/orbit-agent',
                    'installed_path' => '/home/orbit/.local/bin/orbit-agent',
                    'operation_run_id' => 'agent-install-run',
                ]),
            ]);

        $entry = collect($this->probe->diff($node, new ProbeSnapshot([])))
            ->firstWhere('key', 'node.agent_expectation_stale');

        expect($entry)
            ->toBeInstanceOf(DriftEntry::class)
            ->and($entry?->kind)
            ->toBe(DriftKind::Extra)
            ->and($entry?->detail['reason'] ?? null)
            ->toBe('no_agent_intent');

        $this->probe->reconcile($node, $entry);

        expect($node->fresh()->installed_agent)->toBeNull();
    });
});

describe('agent IDE default', function (): void {
    it('passes when no config is set', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $agentIde = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.agent_ide_default_invalid');

        expect($agentIde)->toHaveCount(0);
    });

    it('detects unsupported adapter', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $node->forceFill(['agent_ide_config' => ['adapter' => 'unsupported']]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $agentIde = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.agent_ide_default_invalid');

        expect($agentIde)->toHaveCount(1);
        expect($agentIde[array_key_first($agentIde)]->kind)->toBe(DriftKind::Divergent);
    });

    it('passes for supported adapter', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $node->forceFill(['agent_ide_config' => ['adapter' => 'opencode']]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $agentIde = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.agent_ide_default_invalid');

        expect($agentIde)->toHaveCount(0);
    });
});

describe('access grants', function (): void {
    it('passes when no grants exist', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $access = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_grant_invalid');

        expect($access)->toHaveCount(0);
    });

    it('detects stale consuming grants', function (): void {
        $consumer = nodes_probe_node([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = nodes_probe_node([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
        ]);

        $serving->update(['status' => 'decommissioned']);

        $drift = $this->probe->diff($consumer, new ProbeSnapshot([]));
        $access = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_grant_invalid');

        expect($access)->toHaveCount(1);
    });

    it('detects stale serving grants', function (): void {
        $consumer = nodes_probe_node([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = nodes_probe_node([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
        ]);

        $consumer->update(['status' => 'decommissioned']);

        $drift = $this->probe->diff($serving, new ProbeSnapshot([]));
        $access = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_grant_invalid');

        expect($access)->toHaveCount(1);
    });
});

describe('external service stubs', function (): void {
    it('detects missing WireGuard peer material for active non-gateway nodes', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $wireguard = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => $e->key === 'node.wireguard_peer_missing',
        ));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->kind)->toBe(DriftKind::Missing);
    });

    it('accepts matching WireGuard peer material', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $wireguard = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.wireguard'));

        expect($wireguard)->toHaveCount(0);
    });

    it('detects WireGuard peer address mismatches', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.8/32',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $wireguard = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => $e->key === 'node.wireguard_address_mismatch',
        ));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('detects WireGuard peers attached to non-active nodes as extra', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'decommissioned',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $wireguard = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => $e->key === 'node.wireguard_peer_extra',
        ));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->kind)->toBe(DriftKind::Extra);
    });

    it('returns empty for platform reality checks on remote nodes', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $platform = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.platform'));

        expect($platform)->toHaveCount(0);
    });

    it('detects local platform record mismatches', function (): void {
        $probe = new NodesProbe(new class extends PlatformDetector {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
        ]);
        assignNodesProbeGatewayRole($node);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $platform = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => $e->key === 'node.platform_record_mismatch',
        ));

        expect($platform)->toHaveCount(1);
        expect($platform[0]->kind)->toBe(DriftKind::Divergent);
        expect($platform[0]->detail)->toBe([
            'recorded' => 'macos_14-0',
            'observed' => 'macos_15-4',
        ]);
    });

    it('detects unsupported local platform detection', function (): void {
        $probe = new NodesProbe(new class extends PlatformDetector {
            public function detectLocal(): string
            {
                throw new RuntimeException('Unsupported platform family: Solaris');
            }
        });

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'solaris_11',
            'wireguard_address' => '10.6.0.2',
        ]);
        assignNodesProbeGatewayRole($node);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $platform = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => $e->key === 'node.platform_unsupported',
        ));

        expect($platform)->toHaveCount(1);
        expect($platform[0]->kind)->toBe(DriftKind::Unverifiable);
        expect($platform[0]->summary)
            ->toBe('Could not detect local platform for test: Unsupported platform family: Solaris');
    });

    it('accepts reachable app nodes over agent transport', function (): void {
        nodesProbeUseAgentPush();

        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.51:9477/v1/commands' => nodes_probe_executor_verify_response(),
        ]);
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        $probe = nodesProbeWithAgentPush();

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.51',
            'managed' => true,
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.44.0.51/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $transport = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.transport_unreachable');

        expect($transport)->toHaveCount(0);
        expect($remoteShell->scripts)->not->toContain('true');
        Http::assertSent(fn (Request $request): bool => nodes_probe_executor_verify_request_matches(
            request: $request,
            url: 'http://10.44.0.51:9477/v1/commands',
        ));
    });

    it('detects unreachable app nodes over agent transport', function (): void {
        nodesProbeUseAgentPush();

        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.52:9477/v1/commands' => nodes_probe_executor_verify_response(
                exitCode: 255,
                stderr: 'connection refused',
            ),
        ]);
        $probe = nodesProbeWithAgentPush();

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.52',
            'managed' => true,
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.44.0.52/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $transport = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => $e->key === 'node.transport_unreachable',
        ));

        expect($transport)->toHaveCount(1);
        expect($transport[0]->kind)->toBe(DriftKind::Unverifiable);
        expect($transport[0]->detail)->toBe([
            'exit_code' => 255,
            'output' => 'connection refused',
        ]);
        Http::assertSent(fn (Request $request): bool => nodes_probe_executor_verify_request_matches(
            request: $request,
            url: 'http://10.44.0.52:9477/v1/commands',
        ));
    });

    it('detects unreachable agent nodes over agent transport', function (): void {
        nodesProbeUseAgentPush();

        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.53:9477/v1/commands' => nodes_probe_executor_verify_response(
                exitCode: 255,
                stderr: 'agent connection timed out',
            ),
        ]);
        $probe = nodesProbeWithAgentPush();

        $node = nodes_probe_node([
            'name' => 'agent',
            'host' => '10.44.0.53',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.53',
            'tld' => 'agent',
            'managed' => true,
        ]);
        assignNodesProbeAgentRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.44.0.53/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $transport = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => $e->key === 'node.transport_unreachable',
        ));

        expect($transport)->toHaveCount(1);
        expect($transport[0]->kind)->toBe(DriftKind::Unverifiable);
        expect($transport[0]->summary)->toBe('Gateway cannot reach node agent over node transport.');
        expect($transport[0]->detail)->toBe([
            'exit_code' => 255,
            'output' => 'agent connection timed out',
        ]);
        Http::assertSent(fn (Request $request): bool => nodes_probe_executor_verify_request_matches(
            request: $request,
            url: 'http://10.44.0.53:9477/v1/commands',
        ));
    });

    it('skips node transport reachability for non-app nodes', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'should not run', durationMs: 1),
        ]);
        $probe = nodesProbeWithRemoteShell($remoteShell);

        $node = nodes_probe_node([
            'name' => 'gateway',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
        ]);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $transport = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.transport_unreachable');

        expect($transport)->toHaveCount(0);
        expect($remoteShell->scripts)->not->toContain('true');
    });

    it('skips node transport reachability for database nodes', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'should not run', durationMs: 1),
        ]);
        $probe = nodesProbeWithRemoteShell($remoteShell);

        $node = nodes_probe_node([
            'name' => 'database',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.3',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => 'active',
        ]);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.3/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $transport = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.transport_unreachable');

        expect($transport)->toHaveCount(0);
        expect($remoteShell->scripts)->not->toContain('true');
    });

    it('returns empty for gateway service checks', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.gateway_runtime_unready');

        expect($runtime)->toHaveCount(0);
    });

    it('accepts available app runtime backend', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]);
        $probe = nodesProbeWithRemoteShell($remoteShell);

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.runtime_missing');

        expect($runtime)->toHaveCount(0);
        expect($remoteShell->scripts[0])->toContain('internal:executor:verify');
        expect($remoteShell->scripts[1])->toContain('internal:runtime-backend:probe');
        expect($remoteShell->scripts)->toHaveCount(2);
    });

    it('detects missing app runtime backend', function (): void {
        $probe = nodesProbeWithRemoteShell(new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'missing systemctl', durationMs: 1),
        ]));

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.runtime_missing'));

        expect($runtime)->toHaveCount(1);
        expect($runtime[0]->kind)->toBe(DriftKind::Unverifiable);
        expect($runtime[0]->detail)->toBe([
            'exit_code' => 127,
            'output' => 'missing systemctl',
        ]);
    });

    it('skips app runtime checks for non-app nodes', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'should not run', durationMs: 1),
        ]);
        $probe = nodesProbeWithRemoteShell($remoteShell);

        $node = nodes_probe_node([
            'name' => 'gateway',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
        ]);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.runtime_missing');

        expect($runtime)->toHaveCount(0);
        expect($remoteShell->scripts)->toHaveCount(0);
    });

    it('accepts a development app role without role-owned tld settings', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => [],
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $tld = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_settings_invalid'));

        expect($tld)->toHaveCount(0);
    });

    it('does not report app role baseline drift when optional VitePlus inventory is absent', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => [],
        ]);
        nodes_probe_create_caddy_tool($node);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $baselineDrift = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.role_'));

        expect(
            NodeTool::query()
                ->where('node_id', $node->id)
                ->where('name', 'viteplus')
                ->exists(),
        )
            ->toBeFalse()
            ->and($baselineDrift)
            ->toHaveCount(0);
    });

    it('detects stale app-dev caddy baseline tool config', function (): void {
        $node = nodes_probe_node([
            'name' => 'nmbp',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'nmbp',
            'platform' => 'macos_26-5-1',
            'user' => 'nckrtl',
            'wireguard_address' => '10.6.0.3',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => [],
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'config' => [
                'container' => [
                    'name' => 'orbit-caddy',
                    'image' => 'caddy:2-alpine',
                    'network' => 'orbit-network',
                    'restart_policy' => 'unless-stopped',
                    'published_ports' => ['10.6.0.3:80:80'],
                    'mounts' => [
                        [
                            'source' => '/private/etc/caddy/sites',
                            'target' => '/etc/caddy/sites',
                            'read_only' => true,
                        ],
                    ],
                    'network_aliases' => ['orbit-caddy'],
                    'extra_hosts' => ['host.docker.internal' => 'host-gateway'],
                ],
            ],
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $baseline = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => (
                $e->key === 'node.role_baseline_mismatch'
                && ($e->detail['component'] ?? null) === 'tool_config'
                && ($e->detail['tool'] ?? null) === 'caddy'
            ),
        ));

        expect($baseline)->toHaveCount(1);
        expect($baseline[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('returns empty for CLI PHP default checks', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $php = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.cli_php_default_mismatch');

        expect($php)->toHaveCount(0);
    });
});

describe('reconciliation', function (): void {
    it('throws for unsupported drift keys', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $entry = new DriftEntry(
            family: 'nodes',
            key: 'node.record_incomplete',
            kind: DriftKind::Missing,
            summary: 'test',
        );

        expect(fn () => $this->probe->reconcile($node, $entry))
            ->toThrow(RuntimeException::class, "NodesProbe cannot reconcile drift key 'node.record_incomplete'.");
    });

    it('does not throw for supported drift keys', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $supportedKeys = [
            'node.wireguard_peer_missing',
            'node.wireguard_address_mismatch',
            'node.gateway_runtime_unready',
            'node.access_grant_invalid',
            'node.role_convergence_failed',
            'node.role_baseline_mismatch',
        ];

        foreach ($supportedKeys as $key) {
            $entry = new DriftEntry(
                family: 'nodes',
                key: $key,
                kind: DriftKind::Divergent,
                summary: 'test',
            );

            expect(fn () => $this->probe->reconcile($node, $entry))->not->toThrow(RuntimeException::class);
        }
    });

    it('keeps missing app runtime report-only', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $entry = new DriftEntry(
            family: 'nodes',
            key: 'node.runtime_missing',
            kind: DriftKind::Missing,
            summary: 'test',
        );

        expect(fn () => $this->probe->reconcile($node, $entry))
            ->toThrow(RuntimeException::class, "NodesProbe cannot reconcile drift key 'node.runtime_missing'.");
    });

    it('removes stale access grants on reconcile', function (): void {
        $consumer = nodes_probe_node([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = nodes_probe_node([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
        ]);

        $serving->update(['status' => 'decommissioned']);

        $entry = new DriftEntry(
            family: 'nodes',
            key: 'node.access_grant_invalid',
            kind: DriftKind::Divergent,
            summary: 'test',
        );

        $this->probe->reconcile($consumer, $entry);

        expect(NodeAccess::query()->count())->toBe(0);
    });

    it('repairs stale app-dev caddy baseline tool config on reconcile', function (): void {
        $node = nodes_probe_node([
            'name' => 'nmbp',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'nmbp',
            'platform' => 'macos_26-5-1',
            'user' => 'nckrtl',
            'wireguard_address' => '10.6.0.3',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => [],
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'config' => [
                'container' => [
                    'mounts' => [
                        [
                            'source' => '/private/etc/caddy/sites',
                            'target' => '/etc/caddy/sites',
                            'read_only' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $entry = new DriftEntry(
            family: 'nodes',
            key: 'node.role_baseline_mismatch',
            kind: DriftKind::Divergent,
            summary: 'test',
            detail: [
                'role' => 'app-dev',
                'component' => 'tool_config',
                'tool' => 'caddy',
            ],
        );

        $this->probe->reconcile($node, $entry);

        $mountSources = collect(
            NodeTool::query()
                ->where('node_id', $node->id)
                ->where('name', 'caddy')
                ->sole()
                ->config['container']['mounts'] ?? [],
        )
            ->pluck('source')->all();

        expect($mountSources)
            ->toContain('/Users/nckrtl/.config/orbit/caddy/Caddyfile')
            ->toContain('/Users/nckrtl/.config/orbit/caddy/sites')
            ->toContain('/Users/nckrtl/.config/orbit')
            ->not->toContain('/private/etc/caddy/sites');
    });
});

describe('adoption', function (): void {
    it('returns empty adopt snapshot when no adoptable node reality is detected', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $snapshot = $this->probe->snapshotForAdopt($node);

        expect($snapshot->isEmpty())->toBeTrue();
    });

    it('snapshots local platform record mismatches for adopt', function (): void {
        $probe = new NodesProbe(new class extends PlatformDetector {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
        ]);
        assignNodesProbeGatewayRole($node);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.platform_record_mismatch'))->toBe([
            'recorded' => 'macos_14-0',
            'observed' => 'macos_15-4',
        ]);
    });

    it('snapshots unambiguous WireGuard address mismatches for adopt', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.8/32',
        ]);

        $snapshot = $this->probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_address_mismatch'))->toBe([
            'recorded' => '10.6.0.5',
            'observed' => '10.6.0.8',
            'allowed_ips' => '10.6.0.8/32',
        ]);
    });

    it('does not snapshot ambiguous WireGuard address mismatches for adopt', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.8/32, fd00::8/128',
        ]);

        $snapshot = $this->probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_address_mismatch'))->toBeNull();
    });

    it('snapshots compatible live WireGuard peer extras for adopt', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "peer-public-key\t10.6.0.8/32\n"),
        ]);

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'decommissioned',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'public_key' => 'peer-public-key',
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $snapshot = $this->probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_extra'))->toBe([
            'recorded_status' => 'decommissioned',
            'public_key' => 'peer-public-key',
            'observed' => '10.6.0.8',
            'allowed_ips' => ['10.6.0.8/32'],
        ]);
    });

    it('does not snapshot unproven live WireGuard peer extras for adopt', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "different-public-key\t10.6.0.8/32\n"),
        ]);

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'decommissioned',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'public_key' => 'peer-public-key',
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $snapshot = $this->probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_extra'))->toBeNull();
    });

    it('snapshots compatible live WireGuard peer missing for adopt', function (): void {
        createNodesProbeGatewayNode();
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = nodesProbeWithRemoteShell(new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "app-public-key\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);
        assignNodesProbeAppHostRole($node);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_missing'))->toBe([
            'public_key' => 'app-public-key',
            'observed' => '10.6.0.8',
            'allowed_ips' => ['10.6.0.8/32'],
            'artifact' => [
                'name' => 'test',
                'role' => 'app-dev',
                'local_role' => 'app-dev',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.6.0.8',
                'registry_public_key' => null,
                'interface_public_key' => 'app-public-key',
            ],
        ]);
    });

    it('does not snapshot app host adoption when identity artifact role disagrees with assignments', function (): void {
        createNodesProbeGatewayNode();
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = nodesProbeWithRemoteShell(new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "app-public-key\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $otherNode = nodes_probe_node([
            'name' => 'other',
            'host' => '10.0.0.2',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.10',
        ]);
        assignNodesProbeGatewayRole($otherNode);
        WireGuardPeer::factory()->create([
            'node_id' => $otherNode->id,
            'public_key' => 'app-public-key',
            'allowed_ips' => '10.6.0.9/32',
        ]);

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.9',
        ]);
        assignNodesProbeAppHostRole($node);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_missing'))
            ->toBeNull()
            ->and($snapshot->get('node.runtime_missing'))
            ->toBeNull();
    });

    it('does not snapshot hosted app adoption for unassigned nodes', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]);
        $probe = nodesProbeWithRemoteShell($remoteShell);

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_missing'))
            ->toBeNull()
            ->and($snapshot->get('node.runtime_missing'))
            ->toBeNull()
            ->and($remoteShell->scripts)
            ->toBe([]);
    });

    it('does not snapshot unproven live WireGuard peer missing for adopt', function (): void {
        createNodesProbeGatewayNode();
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = nodesProbeWithRemoteShell(new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "app-public-key\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $otherNode = nodes_probe_node([
            'name' => 'other',
            'host' => '10.0.0.2',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.9',
        ]);
        assignNodesProbeAppHostRole($otherNode);
        WireGuardPeer::factory()->create([
            'node_id' => $otherNode->id,
            'public_key' => 'app-public-key',
            'allowed_ips' => '10.6.0.8/32',
        ]);

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);
        assignNodesProbeAppHostRole($node);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_missing'))->toBeNull();
    });

    it('does not snapshot available app runtime readiness for adopt', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "systemd 255\n", stderr: '', durationMs: 1),
        ]);
        $probe = nodesProbeWithRemoteShell($remoteShell);
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.runtime_missing'))->toBeNull()->and($remoteShell->scripts)->toBe([]);
    });

    it('does not snapshot unavailable app runtime readiness for adopt', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'command not found: systemctl', durationMs: 1),
        ]);
        $probe = nodesProbeWithRemoteShell($remoteShell);
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.runtime_missing'))->toBeNull()->and($remoteShell->scripts)->toBe([]);
    });

    it('returns skipped results for adoptable keys', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $results = $this->probe->adopt($node, new ProbeSnapshot([]));

        expect($results)->toHaveCount(4);

        $keys = array_map(fn ($r) => $r->key, $results);
        expect($keys)->toContain('node.wireguard_peer_missing');
        expect($keys)->toContain('node.wireguard_peer_extra');
        expect($keys)->toContain('node.wireguard_address_mismatch');
        expect($keys)->toContain('node.platform_record_mismatch');

        foreach ($results as $result) {
            expect($result->action)->toBe(AdoptAction::Skipped);
        }
    });

    it('adopts local platform record mismatches', function (): void {
        $probe = new NodesProbe(new class extends PlatformDetector {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
        ]);
        assignNodesProbeGatewayRole($node);

        $results = $probe->adopt($node, $probe->snapshotForAdopt($node));
        $platform = array_values(array_filter(
            $results,
            fn ($result): bool => $result->key === 'node.platform_record_mismatch',
        ));

        expect($platform)->toHaveCount(1);
        expect($platform[0]->action)->toBe(AdoptAction::Updated);
        expect($platform[0]->detail)->toBe([
            'recorded' => 'macos_14-0',
            'observed' => 'macos_15-4',
        ]);
        expect($node->refresh()->platform)->toBe('macos_15-4');
    });

    it('adopts unambiguous WireGuard address mismatches', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.8/32',
        ]);

        $results = $this->probe->adopt($node, $this->probe->snapshotForAdopt($node));
        $wireguard = array_values(array_filter(
            $results,
            fn ($result): bool => $result->key === 'node.wireguard_address_mismatch',
        ));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->action)->toBe(AdoptAction::Updated);
        expect($wireguard[0]->detail)->toBe([
            'recorded' => '10.6.0.5',
            'observed' => '10.6.0.8',
            'allowed_ips' => '10.6.0.8/32',
        ]);
        expect($node->refresh()->wireguard_address)->toBe('10.6.0.8');
    });

    it('adopts compatible live WireGuard peer extras', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "peer-public-key\t10.6.0.8/32\n"),
        ]);

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'decommissioned',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'public_key' => 'peer-public-key',
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $results = $this->probe->adopt($node, $this->probe->snapshotForAdopt($node));
        $wireguard = array_values(array_filter(
            $results,
            fn ($result): bool => $result->key === 'node.wireguard_peer_extra',
        ));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->action)->toBe(AdoptAction::Updated);
        expect($wireguard[0]->detail)->toBe([
            'recorded_status' => 'decommissioned',
            'public_key' => 'peer-public-key',
            'observed' => '10.6.0.8',
            'allowed_ips' => ['10.6.0.8/32'],
        ]);
        expect($node->refresh()->status)->toBe(NodeStatus::Active);
        expect($node->wireguard_address)->toBe('10.6.0.8');
    });

    it('adopts compatible live WireGuard peer missing', function (): void {
        createNodesProbeGatewayNode();
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = nodesProbeWithRemoteShell(new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "app-public-key\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);
        assignNodesProbeAppHostRole($node);

        $results = $probe->adopt($node, $probe->snapshotForAdopt($node));
        $wireguard = array_values(array_filter(
            $results,
            fn ($result): bool => $result->key === 'node.wireguard_peer_missing',
        ));
        $peer = WireGuardPeer::query()->where('node_id', $node->id)->first();

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->action)->toBe(AdoptAction::Updated);
        expect($peer)->toBeInstanceOf(WireGuardPeer::class);
        expect($peer->public_key)->toBe('app-public-key');
        expect($peer->private_key)->toBe('');
        expect($peer->allowed_ips)->toBe('10.6.0.8/32');
    });

    it('keeps app runtime readiness out of adoption', function (): void {
        $probe = nodesProbeWithRemoteShell(new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $snapshot = $probe->snapshotForAdopt($node);
        $results = $probe->adopt($node, $snapshot);
        $runtime = array_values(array_filter(
            $results,
            fn ($result): bool => $result->key === 'node.runtime_missing',
        ));

        expect($snapshot->get('node.runtime_missing'))->toBeNull()->and($runtime)->toBe([]);
    });
});

describe('public IP metadata exclusion', function (): void {
    it('does not detect public IP drift', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
            'public_ipv4' => '1.2.3.4',
            'public_ipv6' => null,
        ]);
        markNodeSecurityBaselineClean($node);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $ipIssues = array_filter($drift, fn (DriftEntry $e): bool => str_contains($e->key, 'public'));

        expect($ipIssues)->toHaveCount(0);
    });
});

describe('agent role baseline', function (): void {
    it('detects missing caddy baseline tool for agent nodes', function (): void {
        $node = nodes_probe_node([
            'name' => 'agent-1',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
            'tld' => 'agent',
        ]);
        $node->roleAssignments()->create([
            'role' => 'agent',
            'status' => 'active',
            'settings' => [],
        ]);
        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $baseline = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => (
                $e->key === 'node.role_baseline_mismatch'
                && ($e->detail['tool'] ?? null) === 'caddy'
            ),
        ));

        expect($baseline)->toHaveCount(1);
        expect($baseline[0]->kind)->toBe(DriftKind::Missing);
    });

    it('detects missing agent runtime user for agent nodes', function (): void {
        nodesProbeUseAgentPush();

        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.42:9477/v1/commands' => nodes_probe_agent_runtime_response([
                'runtime_user' => false,
                'orbit_cli' => false,
            ]),
        ]);
        $probe = nodesProbeWithAgentPush();

        $node = nodes_probe_node([
            'name' => 'agent-1',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.42',
            'tld' => 'agent',
            'managed' => true,
        ]);
        $node->roleAssignments()->create([
            'role' => 'agent',
            'status' => 'active',
            'settings' => [],
        ]);
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'caddy']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $baseline = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => (
                $e->key === 'node.role_baseline_mismatch'
                && ($e->detail['component'] ?? null) === 'agent_user'
            ),
        ));

        expect($baseline)->toHaveCount(1);
        expect($baseline[0]->kind)->toBe(DriftKind::Missing);
        Http::assertSent(fn (Request $request): bool => nodes_probe_agent_runtime_request_matches($request));
    });

    it('detects an agent runtime user that cannot execute the Orbit CLI', function (): void {
        nodesProbeUseAgentPush();

        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.42:9477/v1/commands' => nodes_probe_agent_runtime_response([
                'runtime_user' => true,
                'orbit_cli' => false,
            ]),
        ]);
        $probe = nodesProbeWithAgentPush();

        $node = nodes_probe_node([
            'name' => 'agent-1',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.42',
            'tld' => 'agent',
            'managed' => true,
        ]);
        $node->roleAssignments()->create([
            'role' => 'agent',
            'status' => 'active',
            'settings' => [],
        ]);
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'caddy']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $baseline = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => (
                $e->key === 'node.role_baseline_mismatch'
                && ($e->detail['component'] ?? null) === 'agent_orbit_cli'
            ),
        ));

        expect($baseline)->toHaveCount(1);
        expect($baseline[0]->kind)->toBe(DriftKind::Divergent);
        Http::assertSent(fn (Request $request): bool => nodes_probe_agent_runtime_request_matches($request));
    });
});

describe('access permission validity', function (): void {
    it('passes when no grants exist', function (): void {
        $node = nodes_probe_node([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $permission = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_permission_invalid');

        expect($permission)->toHaveCount(0);
    });

    it('passes normalized permissions on grants', function (): void {
        $consumer = nodes_probe_node([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = nodes_probe_node([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
            'permissions' => ['doctor:verify', 'node:read', 'tool:read', 'tool:update:agent-tools'],
        ]);

        $drift = $this->probe->diff($consumer, new ProbeSnapshot([]));
        $permission = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_permission_invalid');

        expect($permission)->toHaveCount(0);
    });

    it('detects unknown permissions on grants', function (): void {
        $consumer = nodes_probe_node([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = nodes_probe_node([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
            'permissions' => ['tool:read', 'tool:nope'],
        ]);

        $drift = $this->probe->diff($consumer, new ProbeSnapshot([]));
        $permission = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => $e->key === 'node.access_permission_invalid',
        ));

        expect($permission)->toHaveCount(1);
        expect($permission[0]->kind)->toBe(DriftKind::Divergent);
        expect($permission[0]->detail['unknown_permissions'] ?? null)->toBe(['tool:nope']);
    });

    it('detects redundant permissions on grants', function (): void {
        $consumer = nodes_probe_node([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = nodes_probe_node([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
            'permissions' => ['tool:read', 'tool:list'],
        ]);

        $drift = $this->probe->diff($consumer, new ProbeSnapshot([]));
        $permission = array_values(array_filter(
            $drift,
            fn (DriftEntry $e): bool => $e->key === 'node.access_permission_invalid',
        ));

        expect($permission)->toHaveCount(1);
        expect($permission[0]->kind)->toBe(DriftKind::Divergent);
        expect($permission[0]->detail['stored_permissions'] ?? null)->toBe(['tool:read', 'tool:list']);
    });
});

/**
 * @param  array<string, mixed>  $overrides
 */
function nodeIdentityArtifactPayload(array $overrides = []): string
{
    return json_encode(array_merge([
        'name' => 'test',
        'role' => 'app-dev',
        'local_role' => 'app-dev',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.8',
        'registry_public_key' => null,
        'interface_public_key' => 'app-public-key',
    ], $overrides), JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $data
 */
function nodes_probe_agent_runtime_response(array $data): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'node-agent-runtime.probe',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => $data,
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ]);
}

function nodes_probe_executor_verify_response(int $exitCode = 0, string $stderr = ''): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'node.reachable',
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => $stderr === '' ? 'stdout' : 'stderr',
                'message' => $stderr,
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ]);
}

function nodes_probe_executor_verify_request_matches(Request $request, string $url): bool
{
    return (
        $request->url() === $url
        && $request['binary'] === 'orbit'
        && $request['argv'][0] === 'internal:executor:verify'
        && str_starts_with((string) $request['argv'][1], '--operation-token=')
        && $request['argv'][2] === '--json'
        && agentPushRequestOperationIdMatchesToken($request)
    );
}

function nodes_probe_agent_runtime_request_matches(Request $request): bool
{
    return (
        $request->url() === 'http://10.44.0.42:9477/v1/commands'
        && $request['binary'] === 'orbit'
        && $request['argv'][0] === 'internal:agent-runtime:probe'
        && str_starts_with((string) $request['argv'][1], '--operation-token=')
        && $request['argv'][2] === '--json'
        && agentPushRequestOperationIdMatchesToken($request)
    );
}

function nodes_probe_create_caddy_tool(Node $node): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'caddy',
        'config' => app(NodeToolBaselineConfigRenderer::class)->render('caddy', $node),
    ]);
}

final class NodesProbeRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final readonly class NodesProbeRemoteExecutor implements RemoteExecutor
{
    public function __construct(
        private NodesProbeRecordingRemoteShell $remoteShell,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return $this->remoteShell->run($node, $script, $options);
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('NodesProbeRemoteExecutor does not support start().');
    }
}

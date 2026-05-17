<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\AdoptAction;
use App\Enums\DriftKind;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\Nodes\NodesProbe;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([]));
    File::deleteDirectory(storage_path('app/orbit/node-development-dns.d'));
});

afterEach(function (): void {
    File::deleteDirectory(storage_path('app/orbit/node-development-dns.d'));
});

function assignNodesProbeAppHostRole(Node $node, array $settings = ['tld' => 'test']): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => 'active',
        'settings' => $settings,
    ]);
}

describe('interface contract', function (): void {
    it('has key and label', function (): void {
        expect($this->probe->key())->toBe('nodes');
        expect($this->probe->label())->toBe('Nodes');
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
            'role' => '',
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
        $node = Node::create([
            'name' => 'complete',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $recordIncomplete = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.record_incomplete');

        expect($recordIncomplete)->toHaveCount(0);
    });

    it('reports a missing role assignment for app nodes without compatible active assignments', function (): void {
        $node = Node::create([
            'name' => 'app-no-env',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $missingRoleAssignment = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_assignment_missing');

        expect($missingRoleAssignment)->toHaveCount(1);
    });

    it('does not require environment for non-app nodes', function (): void {
        $node = Node::create([
            'name' => 'gateway-no-env',
            'role' => 'gateway',
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

describe('local default', function (): void {
    it('passes when no default is set', function (): void {
        $node = Node::create([
            'name' => 'control',
            'role' => 'control',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $localDefault = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.local_default_invalid');

        expect($localDefault)->toHaveCount(0);
    });

    it('detects missing default node', function (): void {
        LocalNodeDefault::create(['default_node_name' => 'missing']);

        $node = Node::create([
            'name' => 'control',
            'role' => 'control',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $localDefault = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.local_default_invalid');

        expect($localDefault)->toHaveCount(1);
        expect($localDefault[array_key_first($localDefault)]->kind)->toBe(DriftKind::Divergent);
    });

    it('detects non-development default node', function (): void {
        Node::create([
            'name' => 'prod-app',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'production',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        LocalNodeDefault::create(['default_node_name' => 'prod-app']);

        $node = Node::create([
            'name' => 'control',
            'role' => 'control',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $localDefault = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.local_default_invalid');

        expect($localDefault)->toHaveCount(1);
    });

    it('detects unauthorized default node', function (): void {
        $defaultNode = Node::create([
            'name' => 'dev-app',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        LocalNodeDefault::create(['default_node_name' => 'dev-app']);

        $node = Node::create([
            'name' => 'control',
            'role' => 'control',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $localDefault = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.local_default_invalid');

        expect($localDefault)->toHaveCount(1);
    });

    it('passes for authorized development default', function (): void {
        $defaultNode = Node::create([
            'name' => 'dev-app',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        LocalNodeDefault::create(['default_node_name' => 'dev-app']);

        $node = Node::create([
            'name' => 'control',
            'role' => 'control',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $node->id,
            'serving_node_id' => $defaultNode->id,
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $localDefault = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.local_default_invalid');

        expect($localDefault)->toHaveCount(0);
    });

    it('skips local default check for non-control nodes', function (): void {
        LocalNodeDefault::create(['default_node_name' => 'some-node']);

        $node = Node::create([
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $localDefault = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.local_default_invalid');

        expect($localDefault)->toHaveCount(0);
    });
});

describe('agent IDE default', function (): void {
    it('passes when no config is set', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $agentIde = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.agent_ide_default_invalid');

        expect($agentIde)->toHaveCount(0);
    });

    it('detects unsupported adapter', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
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
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
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
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $access = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_grant_invalid');

        expect($access)->toHaveCount(0);
    });

    it('detects stale consuming grants', function (): void {
        $consumer = Node::create([
            'name' => 'consumer',
            'role' => 'control',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
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
        $consumer = Node::create([
            'name' => 'consumer',
            'role' => 'control',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
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
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $wireguard = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.wireguard_peer_missing'));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->kind)->toBe(DriftKind::Missing);
    });

    it('accepts matching WireGuard peer material', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
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
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.8/32',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $wireguard = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.wireguard_address_mismatch'));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('detects WireGuard peers attached to non-active nodes as extra', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'decommissioned',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $wireguard = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.wireguard_peer_extra'));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->kind)->toBe(DriftKind::Extra);
    });

    it('returns empty for platform reality checks on remote nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $platform = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.platform'));

        expect($platform)->toHaveCount(0);
    });

    it('detects local platform record mismatches', function (): void {
        config(['orbit.is_gateway' => true]);

        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = Node::create([
            'name' => 'test',
            'role' => 'gateway',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
        ]);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $platform = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.platform_record_mismatch'));

        expect($platform)->toHaveCount(1);
        expect($platform[0]->kind)->toBe(DriftKind::Divergent);
        expect($platform[0]->detail)->toBe([
            'recorded' => 'macos_14-0',
            'observed' => 'macos_15-4',
        ]);
    });

    it('detects unsupported local platform detection', function (): void {
        config(['orbit.is_gateway' => true]);

        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                throw new RuntimeException('Unsupported platform family: Solaris');
            }
        });

        $node = Node::create([
            'name' => 'test',
            'role' => 'gateway',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'solaris_11',
            'wireguard_address' => '10.6.0.2',
        ]);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $platform = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.platform_unsupported'));

        expect($platform)->toHaveCount(1);
        expect($platform[0]->kind)->toBe(DriftKind::Unverifiable);
        expect($platform[0]->summary)->toBe('Could not detect local platform for test: Unsupported platform family: Solaris');
    });

    it('accepts reachable app nodes over SSH', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $ssh = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.app_ssh_unreachable');

        expect($ssh)->toHaveCount(0);
        expect($remoteShell->scripts)->toBe([
            'true',
            'command -v supervisorctl >/dev/null 2>&1 && sudo supervisorctl version >/dev/null 2>&1',
        ]);
        expect($remoteShell->options[0]['timeout'])->toBe(10);
    });

    it('detects unreachable app nodes over SSH', function (): void {
        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'connection refused', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $ssh = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.app_ssh_unreachable'));

        expect($ssh)->toHaveCount(1);
        expect($ssh[0]->kind)->toBe(DriftKind::Unverifiable);
        expect($ssh[0]->detail)->toBe([
            'exit_code' => 255,
            'output' => 'connection refused',
        ]);
    });

    it('skips SSH reachability for non-app nodes', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'should not run', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
        ]);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $ssh = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.app_ssh_unreachable');

        expect($ssh)->toHaveCount(0);
        expect($remoteShell->scripts)->toBe([]);
    });

    it('returns empty for gateway runtime checks', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'gateway',
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
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.app_runtime_missing');

        expect($runtime)->toHaveCount(0);
        expect($remoteShell->scripts)->toBe([
            'true',
            'command -v supervisorctl >/dev/null 2>&1 && sudo supervisorctl version >/dev/null 2>&1',
        ]);
    });

    it('detects missing app runtime backend', function (): void {
        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'missing supervisorctl', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.app_runtime_missing'));

        expect($runtime)->toHaveCount(1);
        expect($runtime[0]->kind)->toBe(DriftKind::Unverifiable);
        expect($runtime[0]->detail)->toBe([
            'exit_code' => 127,
            'output' => 'missing supervisorctl',
        ]);
    });

    it('skips app runtime checks for non-app nodes', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'should not run', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
        ]);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.app_runtime_missing');

        expect($runtime)->toHaveCount(0);
        expect($remoteShell->scripts)->toBe([]);
    });

    it('detects missing development TLD for development app nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-development',
            'status' => 'active',
            'settings' => [],
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $tld = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_settings_invalid'));

        expect($tld)->toHaveCount(1);
        expect($tld[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('accepts configured development TLD for development app nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        File::ensureDirectoryExists(storage_path('app/orbit/node-development-dns.d'));
        File::put(storage_path('app/orbit/node-development-dns.d/test.conf'), implode("\n", [
            '# orbit-managed=node-development-dns',
            '# node=test',
            '# bind-scope=orbit_network',
            'address=/.test/10.6.0.5',
            '',
        ]));

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $tld = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.role_'));

        expect($tld)->toHaveCount(0);
    });

    it('detects missing gateway development dns mapping for development app nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $mapping = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_baseline_mismatch'));

        expect($mapping)->toHaveCount(1);
        expect($mapping[0]->kind)->toBe(DriftKind::Missing);
    });

    it('detects wrong gateway development dns mapping targets', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        File::ensureDirectoryExists(storage_path('app/orbit/node-development-dns.d'));
        File::put(storage_path('app/orbit/node-development-dns.d/test.conf'), implode("\n", [
            '# orbit-managed=node-development-dns',
            '# node=test',
            '# bind-scope=orbit_network',
            'address=/.test/10.6.0.99',
            '',
        ]));

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $mapping = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_baseline_mismatch'));

        expect($mapping)->toHaveCount(1);
        expect($mapping[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('detects public gateway development dns resolver exposure', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        File::ensureDirectoryExists(storage_path('app/orbit/node-development-dns.d'));
        File::put(storage_path('app/orbit/node-development-dns.d/test.conf'), implode("\n", [
            '# orbit-managed=node-development-dns',
            '# node=test',
            '# bind-scope=public',
            'address=/.test/10.6.0.5',
            '',
        ]));

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $exposure = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_baseline_mismatch'));

        expect($exposure)->toHaveCount(1);
        expect($exposure[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('does not require development TLD for production app nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'production',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $tld = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.development'));

        expect($tld)->toHaveCount(0);
    });

    it('returns empty for CLI PHP default checks', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
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
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
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
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $supportedKeys = [
            'node.wireguard_peer_missing',
            'node.wireguard_address_mismatch',
            'node.gateway_runtime_unready',
            'node.app_runtime_missing',
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

    it('removes stale access grants on reconcile', function (): void {
        $consumer = Node::create([
            'name' => 'consumer',
            'role' => 'control',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
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

    it('repairs gateway development dns mapping drift on reconcile', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        File::ensureDirectoryExists(storage_path('app/orbit/node-development-dns.d'));
        File::put(storage_path('app/orbit/node-development-dns.d/test.conf'), implode("\n", [
            '# orbit-managed=node-development-dns',
            '# node=test',
            '# bind-scope=public',
            'address=/.test/10.6.0.99',
            '',
        ]));

        $entry = new DriftEntry(
            family: 'nodes',
            key: 'node.role_baseline_mismatch',
            kind: DriftKind::Divergent,
            summary: 'test',
            detail: [
                'role' => 'app-development',
                'tld' => 'test',
            ],
        );

        $this->probe->reconcile($node, $entry);

        expect(File::get(storage_path('app/orbit/node-development-dns.d/test.conf')))
            ->toContain('# bind-scope=orbit_network')
            ->toContain('address=/.test/10.6.0.5');
    });
});

describe('adoption', function (): void {
    it('returns empty adopt snapshot when no adoptable node reality is detected', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'control',
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
        config(['orbit.is_gateway' => true]);

        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = Node::create([
            'name' => 'test',
            'role' => 'gateway',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
        ]);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.platform_record_mismatch'))->toBe([
            'recorded' => 'macos_14-0',
            'observed' => 'macos_15-4',
        ]);
    });

    it('snapshots unambiguous WireGuard address mismatches for adopt', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
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
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
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

        $node = Node::create([
            'name' => 'test',
            'role' => 'control',
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

        $node = Node::create([
            'name' => 'test',
            'role' => 'control',
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
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: nodeIdentityArtifactPayload(), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
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
                'role' => 'app',
                'local_role' => 'app',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.6.0.8',
                'registry_public_key' => null,
                'interface_public_key' => 'app-public-key',
            ],
        ]);
    });

    it('snapshots app host adoption for control-role nodes with active app-host assignments', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: nodeIdentityArtifactPayload(['role' => 'control', 'local_role' => 'control']), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'role' => 'control',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);
        assignNodesProbeAppHostRole($node);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_missing'))->toMatchArray([
            'public_key' => 'app-public-key',
            'observed' => '10.6.0.8',
            'allowed_ips' => ['10.6.0.8/32'],
            'artifact' => [
                'name' => 'test',
                'role' => 'control',
                'local_role' => 'control',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.6.0.8',
                'registry_public_key' => null,
                'interface_public_key' => 'app-public-key',
            ],
        ])
            ->and($snapshot->get('node.app_runtime_missing'))->toMatchArray([
                'available' => true,
                'exit_code' => 0,
            ]);
    });

    it('does not snapshot hosted app adoption for legacy app-only nodes', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_missing'))->toBeNull()
            ->and($snapshot->get('node.app_runtime_missing'))->toBeNull()
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('does not snapshot unproven live WireGuard peer missing for adopt', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: nodeIdentityArtifactPayload(['name' => 'other']), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);
        assignNodesProbeAppHostRole($node);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_missing'))->toBeNull();
    });

    it('snapshots available app runtime readiness for adopt', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.app_runtime_missing'))->toBe([
            'available' => true,
            'exit_code' => 0,
            'output' => 'supervisor OK',
        ]);
        expect($remoteShell->scripts)->toBe([
            'command -v supervisorctl >/dev/null 2>&1 && sudo supervisorctl version >/dev/null 2>&1',
        ]);
    });

    it('snapshots unavailable app runtime readiness for adopt', function (): void {
        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'missing supervisorctl', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.app_runtime_missing'))->toBe([
            'available' => false,
            'exit_code' => 127,
            'output' => 'missing supervisorctl',
        ]);
    });

    it('returns skipped results for adoptable keys', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $results = $this->probe->adopt($node, new ProbeSnapshot([]));

        expect($results)->toHaveCount(5);

        $keys = array_map(fn ($r) => $r->key, $results);
        expect($keys)->toContain('node.wireguard_peer_missing');
        expect($keys)->toContain('node.wireguard_peer_extra');
        expect($keys)->toContain('node.wireguard_address_mismatch');
        expect($keys)->toContain('node.app_runtime_missing');
        expect($keys)->toContain('node.platform_record_mismatch');

        foreach ($results as $result) {
            expect($result->action)->toBe(AdoptAction::Skipped);
        }
    });

    it('adopts local platform record mismatches', function (): void {
        config(['orbit.is_gateway' => true]);

        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = Node::create([
            'name' => 'test',
            'role' => 'gateway',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
        ]);

        $results = $probe->adopt($node, $probe->snapshotForAdopt($node));
        $platform = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.platform_record_mismatch'));

        expect($platform)->toHaveCount(1);
        expect($platform[0]->action)->toBe(AdoptAction::Updated);
        expect($platform[0]->detail)->toBe([
            'recorded' => 'macos_14-0',
            'observed' => 'macos_15-4',
        ]);
        expect($node->refresh()->platform)->toBe('macos_15-4');
    });

    it('adopts unambiguous WireGuard address mismatches', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.8/32',
        ]);

        $results = $this->probe->adopt($node, $this->probe->snapshotForAdopt($node));
        $wireguard = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.wireguard_address_mismatch'));

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

        $node = Node::create([
            'name' => 'test',
            'role' => 'control',
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
        $wireguard = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.wireguard_peer_extra'));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->action)->toBe(AdoptAction::Updated);
        expect($wireguard[0]->detail)->toBe([
            'recorded_status' => 'decommissioned',
            'public_key' => 'peer-public-key',
            'observed' => '10.6.0.8',
            'allowed_ips' => ['10.6.0.8/32'],
        ]);
        expect($node->refresh()->status)->toBe('active');
        expect($node->wireguard_address)->toBe('10.6.0.8');
    });

    it('adopts compatible live WireGuard peer missing', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: nodeIdentityArtifactPayload(), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);
        assignNodesProbeAppHostRole($node);

        $results = $probe->adopt($node, $probe->snapshotForAdopt($node));
        $wireguard = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.wireguard_peer_missing'));
        $peer = WireGuardPeer::query()->where('node_id', $node->id)->first();

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->action)->toBe(AdoptAction::Updated);
        expect($peer)->toBeInstanceOf(WireGuardPeer::class);
        expect($peer->public_key)->toBe('app-public-key');
        expect($peer->private_key)->toBe('');
        expect($peer->allowed_ips)->toBe('10.6.0.8/32');
    });

    it('adopts available app runtime readiness', function (): void {
        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $results = $probe->adopt($node, $probe->snapshotForAdopt($node));
        $runtime = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.app_runtime_missing'));

        expect($runtime)->toHaveCount(1);
        expect($runtime[0]->action)->toBe(AdoptAction::Updated);
        expect($runtime[0]->detail)->toBe([
            'available' => true,
            'exit_code' => 0,
            'output' => 'supervisor OK',
        ]);
    });

    it('conflicts unavailable app runtime readiness during adopt', function (): void {
        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'missing supervisorctl', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $results = $probe->adopt($node, $probe->snapshotForAdopt($node));
        $runtime = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.app_runtime_missing'));

        expect($runtime)->toHaveCount(1);
        expect($runtime[0]->action)->toBe(AdoptAction::Conflict);
        expect($runtime[0]->detail)->toBe([
            'available' => false,
            'exit_code' => 127,
            'output' => 'missing supervisorctl',
        ]);
    });
});

describe('public IP metadata exclusion', function (): void {
    it('does not detect public IP drift', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
            'public_ipv4' => '1.2.3.4',
            'public_ipv6' => null,
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $ipIssues = array_filter($drift, fn (DriftEntry $e): bool => str_contains($e->key, 'public'));

        expect($ipIssues)->toHaveCount(0);
    });
});

/**
 * @param  array<string, mixed>  $overrides
 */
function nodeIdentityArtifactPayload(array $overrides = []): string
{
    return json_encode(array_merge([
        'name' => 'test',
        'role' => 'app',
        'local_role' => 'app',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.8',
        'registry_public_key' => null,
        'interface_public_key' => 'app-public-key',
    ], $overrides), JSON_THROW_ON_ERROR);
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

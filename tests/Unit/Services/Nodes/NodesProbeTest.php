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
use App\Models\WireGuardPeer;
use App\Services\Nodes\NodesProbe;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = new NodesProbe;
});

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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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

    it('requires environment for app nodes', function (): void {
        $node = Node::create([
            'name' => 'app-no-env',
            'role' => 'app',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $recordIncomplete = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.record_incomplete');

        expect($recordIncomplete)->toHaveCount(1);
    });

    it('does not require environment for non-app nodes', function (): void {
        $node = Node::create([
            'name' => 'gateway-no-env',
            'role' => 'gateway',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
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

describe('local caller role', function (): void {
    it('accepts missing local role as control before bootstrap', function (): void {
        $node = Node::create([
            'name' => 'control',
            'role' => 'control',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
        ]);

        // No local node exists with is_local=true and active status... wait, we just created one
        // Let me fix this - delete the node so there's no local active node
        $node->delete();

        $otherNode = Node::create([
            'name' => 'other',
            'role' => 'control',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $drift = $this->probe->diff($otherNode, new ProbeSnapshot([]));
        $localRoleIssues = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.local_role'));

        expect($localRoleIssues)->toHaveCount(0);
    });

    it('detects invalid local role', function (): void {
        Node::create([
            'name' => 'local',
            'role' => 'invalid',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
        ]);

        $node = Node::create([
            'name' => 'probe',
            'role' => 'control',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $invalid = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.local_role_invalid');

        expect($invalid)->toHaveCount(1);
        expect($invalid[array_key_first($invalid)]->kind)->toBe(DriftKind::Divergent);
    });

    it('detects local role mismatch', function (): void {
        Node::create([
            'name' => 'local',
            'role' => 'app',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
            'is_local' => true,
        ]);

        $node = Node::create([
            'name' => 'probe',
            'role' => 'gateway',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
            'is_local' => true,
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $mismatch = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.local_role_mismatch');

        expect($mismatch)->toHaveCount(1);
        expect($mismatch[array_key_first($mismatch)]->kind)->toBe(DriftKind::Divergent);
    });

    it('skips local role check for non-local nodes', function (): void {
        $node = Node::create([
            'name' => 'remote',
            'role' => 'app',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
            'is_local' => false,
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $localRoleIssues = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.local_role'));

        expect($localRoleIssues)->toHaveCount(0);
    });
});

describe('local default', function (): void {
    it('passes when no default is set', function (): void {
        $node = Node::create([
            'name' => 'control',
            'role' => 'control',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
            'is_local' => true,
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'role' => 'app',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'role' => 'app',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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
        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = Node::create([
            'name' => 'test',
            'role' => 'control',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
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
        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                throw new RuntimeException('Unsupported platform family: Solaris');
            }
        });

        $node = Node::create([
            'name' => 'test',
            'role' => 'control',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'solaris_11',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $ssh = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.app_ssh_unreachable');

        expect($ssh)->toHaveCount(0);
        expect($remoteShell->scripts)->toBe([
            'true',
            'command -v supervisorctl >/dev/null 2>&1 && supervisorctl status >/dev/null 2>&1',
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.app_runtime_missing');

        expect($runtime)->toHaveCount(0);
        expect($remoteShell->scripts)->toBe([
            'true',
            'command -v supervisorctl >/dev/null 2>&1 && supervisorctl status >/dev/null 2>&1',
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $tld = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.development_tld_missing'));

        expect($tld)->toHaveCount(1);
        expect($tld[0]->kind)->toBe(DriftKind::Missing);
    });

    it('accepts configured development TLD for development app nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $tld = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.development'));

        expect($tld)->toHaveCount(0);
    });

    it('does not require development TLD for production app nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $supportedKeys = [
            'node.local_role_invalid',
            'node.local_role_mismatch',
            'node.wireguard_peer_missing',
            'node.wireguard_address_mismatch',
            'node.gateway_runtime_unready',
            'node.app_runtime_missing',
            'node.access_grant_invalid',
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
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'role' => 'app',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
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
});

describe('adoption', function (): void {
    it('returns empty adopt snapshot for non-local nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $snapshot = $this->probe->snapshotForAdopt($node);

        expect($snapshot->isEmpty())->toBeTrue();
    });

    it('snapshots local platform record mismatches for adopt', function (): void {
        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = Node::create([
            'name' => 'test',
            'role' => 'control',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
        ]);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.platform_record_mismatch'))->toBe([
            'recorded' => 'macos_14-0',
            'observed' => 'macos_15-4',
        ]);
    });

    it('returns skipped results for adoptable keys', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'environment' => 'development',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $results = $this->probe->adopt($node, new ProbeSnapshot([]));

        expect($results)->toHaveCount(3);

        $keys = array_map(fn ($r) => $r->key, $results);
        expect($keys)->toContain('node.wireguard_peer_extra');
        expect($keys)->toContain('node.wireguard_address_mismatch');
        expect($keys)->toContain('node.platform_record_mismatch');

        foreach ($results as $result) {
            expect($result->action)->toBe(AdoptAction::Skipped);
        }
    });

    it('adopts local platform record mismatches', function (): void {
        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = Node::create([
            'name' => 'test',
            'role' => 'control',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
            'is_local' => true,
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
});

describe('public IP metadata exclusion', function (): void {
    it('does not detect public IP drift', function (): void {
        $node = Node::create([
            'name' => 'test',
            'role' => 'app',
            'host' => '10.0.0.1',
            'ssh_user' => 'user',
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

<?php

declare(strict_types=1);

use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

const FIREWALL_RULE_LIST_CALLER_WG_IP = '10.6.0.98';

function createFirewallRuleListCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => FIREWALL_RULE_LIST_CALLER_WG_IP,
        'wireguard_address' => FIREWALL_RULE_LIST_CALLER_WG_IP,
        'platform' => 'ubuntu',
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantFirewallRuleListAccess(Node $caller, Node $servingNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertFirewallRuleListRow(int $nodeId, array $overrides = []): int
{
    $now = now();
    $row = array_merge([
        'node_id' => $nodeId,
        'name' => 'vite',
        'direction' => 'incoming',
        'action' => 'allow',
        'source' => 'any',
        'destination' => null,
        'port' => '443',
        'protocol' => 'tcp',
        'reason' => 'legacy list row',
        'source_hash' => hash('sha256', 'legacy-list-row'),
        'address_family' => 'v4',
        'interface' => 'public',
        'owner' => 'user',
        'protected' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    if (! Schema::hasColumn('firewall_rules', 'protected')) {
        unset($row['protected']);
    }

    return DB::table('firewall_rules')->insertGetId($row);
}

describe('FirewallRuleListController', function (): void {
    it('lists visible firewall rules with metadata', function (): void {
        $caller = createFirewallRuleListCallerNode();
        $visibleNode = createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);
        $hiddenNode = createTestAppHostNode(['name' => 'app-2', 'platform' => 'ubuntu']);
        grantFirewallRuleListAccess($caller, $visibleNode);

        FirewallRule::factory()->create([
            'node_id' => $visibleNode->id,
            'name' => 'vite',
            'address_family' => 'v4',
            'interface' => 'public',
            'owner' => 'node-security',
            'protected' => true,
        ]);
        FirewallRule::factory()->create(['node_id' => $hiddenNode->id, 'name' => 'hidden']);

        $response = $this->call(
            'GET',
            '/api/firewall-rules',
            [],
            [],
            [],
            ['REMOTE_ADDR' => FIREWALL_RULE_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'success.data.rules')
            ->assertJsonPath('success.data.rules.0.name', 'vite')
            ->assertJsonPath('success.data.rules.0.address_family', 'v4')
            ->assertJsonPath('success.data.rules.0.interface_scope', 'public')
            ->assertJsonPath('success.data.rules.0.owner', 'node-security')
            ->assertJsonPath('success.data.rules.0.protected', true)
            ->assertJsonMissingPath('success.data.rules.0.interface')
            ->assertJsonPath('success.meta.node', null)
            ->assertJsonPath('success.meta.count', 1);
    });

    it('lets gateway callers read all eligible firewall intent', function (): void {
        createFirewallRuleListCallerNode(role: 'gateway');

        $firstNode = createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);
        $secondNode = createTestAppHostNode(['name' => 'app-2', 'platform' => 'ubuntu']);

        FirewallRule::factory()->create(['node_id' => $firstNode->id]);
        FirewallRule::factory()->create(['node_id' => $secondNode->id]);

        $response = $this->call(
            'GET',
            '/api/firewall-rules',
            [],
            [],
            [],
            ['REMOTE_ADDR' => FIREWALL_RULE_LIST_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonCount(2, 'success.data.rules');
    });

    it('returns validation failure for unsupported node scopes', function (): void {
        createFirewallRuleListCallerNode(role: 'gateway');
        Node::factory()->create(['name' => 'control-1', 'platform' => 'ubuntu']);

        $response = $this->call(
            'GET',
            '/api/firewall-rules?node=control-1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => FIREWALL_RULE_LIST_CALLER_WG_IP],
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node');
    });

    it('returns authorization failure when caller has no firewall visibility', function (): void {
        createFirewallRuleListCallerNode([]);

        $response = $this->call(
            'GET',
            '/api/firewall-rules',
            [],
            [],
            [],
            ['REMOTE_ADDR' => FIREWALL_RULE_LIST_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'firewall_rule:read');
    });

    it('serializes protected from ownership even when a stored boolean contradicts owner', function (): void {
        $caller = createFirewallRuleListCallerNode();
        $visibleNode = createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);
        grantFirewallRuleListAccess($caller, $visibleNode);

        insertFirewallRuleListRow($visibleNode->id, [
            'name' => 'vite',
            'owner' => 'system',
            'protected' => false,
        ]);

        $response = $this->call(
            'GET',
            '/api/firewall-rules',
            [],
            [],
            [],
            ['REMOTE_ADDR' => FIREWALL_RULE_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'success.data.rules')
            ->assertJsonPath('success.data.rules.0.owner', 'system')
            ->assertJsonPath('success.data.rules.0.protected', true);
    });
});

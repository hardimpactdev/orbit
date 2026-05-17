<?php

declare(strict_types=1);

use App\Http\Gateway\GatewayApiException;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Firewall\FirewallRuleQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function grantFirewallRuleQueryAccess(Node $caller, Node $servingNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignFirewallRuleQueryAppHostRole(Node $node, string $role = 'app-development', array $settings = ['tld' => 'test']): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

describe('FirewallRuleQuery', function (): void {
    it('normalizes firewall rule entities and sorts them by node then name', function (): void {
        $zNode = Node::factory()->create(['name' => 'z-node', 'role' => 'control', 'platform' => 'ubuntu']);
        $aNode = Node::factory()->create(['name' => 'a-node', 'role' => 'gateway', 'platform' => 'ubuntu']);
        assignFirewallRuleQueryAppHostRole($zNode);

        FirewallRule::factory()->create([
            'node_id' => $zNode->id,
            'name' => 'vite',
            'port' => '5173',
            'reason' => 'local development server',
        ]);
        FirewallRule::factory()->create([
            'node_id' => $aNode->id,
            'name' => 'https',
            'port' => '443',
            'reason' => null,
        ]);

        $result = app(FirewallRuleQuery::class)->list();

        expect(array_map(fn (array $rule): string => "{$rule['node']}:{$rule['name']}", $result['rules']))->toBe([
            'a-node:https',
            'z-node:vite',
        ])
            ->and($result['meta'])->toBe([
                'node' => null,
                'count' => 2,
            ])
            ->and($result['rules'][0])->toMatchArray([
                'name' => 'https',
                'node' => 'a-node',
                'direction' => 'incoming',
                'action' => 'allow',
                'source' => 'any',
                'destination' => null,
                'port' => 443,
                'protocol' => 'tcp',
                'reason' => null,
                'status' => 'expected',
            ]);
    });

    it('filters by visible eligible node and rejects unsupported node scopes', function (): void {
        $caller = Node::factory()->create(['role' => 'app', 'platform' => 'ubuntu']);
        $visibleNode = Node::factory()->create(['name' => 'visible-node', 'role' => 'control', 'platform' => 'ubuntu']);
        $hiddenNode = Node::factory()->create(['name' => 'hidden-node', 'role' => 'control', 'platform' => 'ubuntu']);
        assignFirewallRuleQueryAppHostRole($visibleNode, 'app-production', []);
        assignFirewallRuleQueryAppHostRole($hiddenNode);
        grantFirewallRuleQueryAccess($caller, $visibleNode);

        FirewallRule::factory()->create(['node_id' => $visibleNode->id, 'name' => 'visible']);
        FirewallRule::factory()->create(['node_id' => $hiddenNode->id, 'name' => 'hidden']);

        $query = app(FirewallRuleQuery::class);
        $result = $query->list(node: 'visible-node', caller: $caller);

        expect(array_column($result['rules'], 'name'))->toBe(['visible'])
            ->and($result['meta']['node'])->toBe('visible-node');

        $query->list(node: 'hidden-node', caller: $caller);
    })->throws(GatewayApiException::class, 'The selected node is not a firewall target.');

    it('omits rules for inactive unsupported or role-incompatible nodes', function (): void {
        $eligibleNode = Node::factory()->create(['name' => 'app-1', 'role' => 'control', 'platform' => 'ubuntu']);
        $controlNode = Node::factory()->create(['name' => 'control-1', 'role' => 'control', 'platform' => 'ubuntu']);
        $macNode = Node::factory()->create(['name' => 'mac-1', 'role' => 'app', 'platform' => 'macos']);
        $inactiveNode = Node::factory()->create(['name' => 'inactive-1', 'role' => 'app', 'platform' => 'ubuntu', 'status' => 'inactive']);
        $legacyAppOnlyNode = Node::factory()->create(['name' => 'legacy-app-only', 'role' => 'app', 'platform' => 'ubuntu']);
        assignFirewallRuleQueryAppHostRole($eligibleNode);

        FirewallRule::factory()->create(['node_id' => $eligibleNode->id, 'name' => 'visible']);
        FirewallRule::factory()->create(['node_id' => $controlNode->id, 'name' => 'control']);
        FirewallRule::factory()->create(['node_id' => $macNode->id, 'name' => 'mac']);
        FirewallRule::factory()->create(['node_id' => $inactiveNode->id, 'name' => 'inactive']);
        FirewallRule::factory()->create(['node_id' => $legacyAppOnlyNode->id, 'name' => 'legacy']);

        $result = app(FirewallRuleQuery::class)->list();

        expect(array_column($result['rules'], 'name'))->toBe(['visible']);
    });

    it('fails authorization when non-gateway callers have no visible firewall nodes', function (): void {
        $caller = Node::factory()->create(['role' => 'app', 'platform' => 'ubuntu']);

        app(FirewallRuleQuery::class)->list(caller: $caller);
    })->throws(GatewayApiException::class, 'This node is not authorized to read the firewall rule registry.');
});

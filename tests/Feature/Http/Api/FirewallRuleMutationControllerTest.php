<?php

declare(strict_types=1);

use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const FIREWALL_RULE_MUTATION_CALLER_WG_IP = '10.6.0.99';

function createFirewallRuleMutationCallerNode(array $overrides = []): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => FIREWALL_RULE_MUTATION_CALLER_WG_IP,
        'wireguard_address' => FIREWALL_RULE_MUTATION_CALLER_WG_IP,
        'platform' => 'ubuntu',
    ], $overrides);

    return match ($attributes['role']) {
        'app' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantFirewallRuleMutationAccess(Node $caller, Node $servingNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('FirewallRule mutation controllers', function (): void {
    it('stores firewall rule intent for authorized callers', function (): void {
        $caller = createFirewallRuleMutationCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'platform' => 'ubuntu']);
        grantFirewallRuleMutationAccess($caller, $node);

        $response = $this->call('POST', '/api/firewall-rules', [
            'action' => 'allow',
            'name' => 'local-vite',
            'node' => 'app-1',
            'source' => '10.6.0.0/24',
            'port' => '5173',
            'protocol' => 'tcp',
        ], [], [], ['REMOTE_ADDR' => FIREWALL_RULE_MUTATION_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.rule.name', 'local-vite')
            ->assertJsonPath('success.meta.warnings.0.code', 'firewall_rule.enactment_deferred');

        expect(FirewallRule::query()->where('name', 'local-vite')->exists())->toBeTrue();
    });

    it('rejects unauthorized store requests without mutation', function (): void {
        createFirewallRuleMutationCallerNode(['role' => 'app']);
        createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'platform' => 'ubuntu']);

        $response = $this->call('POST', '/api/firewall-rules', [
            'action' => 'allow',
            'name' => 'local-vite',
            'node' => 'app-1',
            'port' => '5173',
        ], [], [], ['REMOTE_ADDR' => FIREWALL_RULE_MUTATION_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(FirewallRule::query()->count())->toBe(0);
    });

    it('requires destructive consent for delete requests', function (): void {
        createFirewallRuleMutationCallerNode(['role' => 'gateway']);
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $response = $this->call('DELETE', '/api/firewall-rules/local-vite?node=app-1', [], [], [], ['REMOTE_ADDR' => FIREWALL_RULE_MUTATION_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'destructive_consent_required');

        expect(FirewallRule::query()->where('name', 'local-vite')->exists())->toBeTrue();
    });

    it('removes firewall rule intent with deferred cleanup warnings', function (): void {
        createFirewallRuleMutationCallerNode(['role' => 'gateway']);
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $response = $this->call('DELETE', '/api/firewall-rules/local-vite?node=app-1&destructive_consent=1', [], [], [], ['REMOTE_ADDR' => FIREWALL_RULE_MUTATION_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.rule.status', 'removed_with_drift')
            ->assertJsonPath('success.meta.warnings.0.code', 'firewall_rule.cleanup_deferred');

        expect(FirewallRule::query()->where('name', 'local-vite')->exists())->toBeFalse();
    });
});

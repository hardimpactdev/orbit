<?php

declare(strict_types=1);

use App\Http\Gateway\GatewayApiException;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Firewall\FirewallRuleIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 */
function grantFirewallRuleIntentAccess(Node $caller, Node $servingNode, array $permissions = ['*']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'permissions' => json_encode($permissions),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createFirewallRuleIntentAppHostNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'platform' => 'ubuntu',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

describe('FirewallRuleIntent', function (): void {
    it('creates idempotent firewall intent and returns a deferred enactment warning', function (): void {
        $node = createFirewallRuleIntentAppHostNode();

        $result = app(FirewallRuleIntent::class)->store(
            action: 'allow',
            name: 'local-vite',
            nodeName: 'app-1',
            direction: 'incoming',
            source: '10.6.0.0/24',
            destination: null,
            port: '5173',
            protocol: 'tcp',
            reason: 'local development',
        );

        expect(FirewallRule::query()->count())->toBe(1)
            ->and($result['data']['rule']['name'])->toBe('local-vite')
            ->and($result['data']['rule']['node'])->toBe($node->name)
            ->and($result['meta']['action'])->toBe('created')
            ->and($result['meta']['warnings'][0]['code'])->toBe('firewall_rule.enactment_deferred');

        $again = app(FirewallRuleIntent::class)->store('allow', 'local-vite', 'app-1', 'incoming', '10.6.0.0/24', null, '5173', 'tcp', 'local development');

        expect(FirewallRule::query()->count())->toBe(1)
            ->and($again['meta']['action'])->toBe('converged');
    });

    it('rejects same-name different policy before mutation', function (): void {
        $node = createFirewallRuleIntentAppHostNode();
        FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite', 'port' => '5173']);

        app(FirewallRuleIntent::class)->store('allow', 'local-vite', 'app-1', 'incoming', 'any', null, '8080', 'tcp', null);
    })->throws(GatewayApiException::class, 'A different firewall rule already uses this name on the selected node.');

    it('authorizes non-gateway callers through node access grants', function (): void {
        $caller = Node::factory()->create(['role' => 'app', 'platform' => 'ubuntu']);
        $node = createFirewallRuleIntentAppHostNode();
        grantFirewallRuleIntentAccess($caller, $node);

        app(FirewallRuleIntent::class)->store('deny', 'block-redis', 'app-1', 'incoming', 'any', null, '6379', 'tcp', null, $caller);

        expect(FirewallRule::query()->where('name', 'block-redis')->exists())->toBeTrue();
    });

    it('denies non-gateway callers that only have firewall read permission', function (): void {
        $caller = Node::factory()->create(['role' => 'app', 'platform' => 'ubuntu']);
        $node = createFirewallRuleIntentAppHostNode();
        grantFirewallRuleIntentAccess($caller, $node, ['firewall_rule:read']);

        app(FirewallRuleIntent::class)->store('deny', 'block-redis', 'app-1', 'incoming', 'any', null, '6379', 'tcp', null, $caller);
    })->throws(GatewayApiException::class, 'This node is not authorized to manage firewall rules for the selected node.');

    it('removes intent idempotently and reports deferred cleanup', function (): void {
        $node = createFirewallRuleIntentAppHostNode();
        FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $removed = app(FirewallRuleIntent::class)->remove('local-vite', 'app-1');
        $again = app(FirewallRuleIntent::class)->remove('local-vite', 'app-1');

        expect(FirewallRule::query()->count())->toBe(0)
            ->and($removed['meta']['warnings'][0]['code'])->toBe('firewall_rule.cleanup_deferred')
            ->and($again['data']['rule']['status'])->toBe('already_absent');
    });

    it('blocks bootstrap policy mutations', function (): void {
        createFirewallRuleIntentAppHostNode();

        app(FirewallRuleIntent::class)->store('allow', 'ssh-public', 'app-1', 'incoming', 'any', null, '22', 'tcp', null);
    })->throws(GatewayApiException::class, 'The requested rule would mutate node bootstrap policy.');
});

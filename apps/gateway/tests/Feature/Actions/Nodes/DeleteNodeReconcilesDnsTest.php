<?php

declare(strict_types=1);

use App\Actions\Nodes\NodeRemovalFailed;
use App\Actions\Nodes\RemoveNode;
use App\Models\Node;
use App\Services\Dns\DnsmasqReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->reconciler = new class extends DnsmasqReconciler {
        public int $reconciles = 0;

        public ?Throwable $failure = null;

        public function __construct() {}

        public function reconcileRecords(): bool
        {
            $this->reconciles++;

            if ($this->failure instanceof Throwable) {
                throw $this->failure;
            }

            return true;
        }
    };

    app()->instance(DnsmasqReconciler::class, $this->reconciler);
});

it('reconciles dnsmasq after deleting a node', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',

        'tld' => 'app-1',
        'wireguard_address' => '10.6.0.3',
    ]);

    app(RemoveNode::class)->handle($node, removedSelf: false);

    expect($this->reconciler->reconciles)->toBe(1);
});

it('rolls registry state back and preserves the DNS failure as the exception cause', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'app-1',
        'wireguard_address' => '10.6.0.3',
    ]);
    $otherNode = Node::factory()->create([
        'name' => 'gateway-1',
        'tld' => 'gateway-1',
        'wireguard_address' => '10.6.0.2',
    ]);
    DB::table('node_access')->insert([
        'consumer_node_id' => $node->id,
        'serving_node_id' => $otherNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $firewallRuleId = (int) DB::table('firewall_rules')->insertGetId([
        'node_id' => $node->id,
        'name' => 'orbit-deny-public-ssh',
        'direction' => 'in',
        'action' => 'deny',
        'source' => 'any',
        'destination' => null,
        'port' => '22',
        'protocol' => 'tcp',
        'reason' => 'Orbit security baseline',
        'source_hash' => hash(algo: 'sha256', data: 'orbit-deny-public-ssh'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $failure = new RuntimeException('dns activation failed');
    $this->reconciler->failure = $failure;

    try {
        app(RemoveNode::class)->handle($node, removedSelf: false);
        $this->fail('Expected node removal to fail.');
    } catch (NodeRemovalFailed $exception) {
        expect($exception->errorCode)
            ->toBe('node.dns_reconciliation_failed')
            ->and($exception->getPrevious())
            ->toBe($failure);
    }

    expect(DB::table('nodes')->where('id', $node->id)->exists())
        ->toBeTrue()
        ->and(DB::table('node_access')->where('consumer_node_id', $node->id)->exists())
        ->toBeTrue()
        ->and(DB::table('firewall_rules')->where('id', $firewallRuleId)->exists())
        ->toBeTrue();
});

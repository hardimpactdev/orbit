<?php

declare(strict_types=1);

use App\Actions\Nodes\ProvisionNodeAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** Runs the step that ends a provisioning: it publishes the DNS for a Node that is an active Router. */
function publishAfterProvisioning(Node $node, int &$converged): void
{
    $dns = new class($converged) implements PrivateDnsManager
    {
        public function __construct(private int &$converged) {}

        public function converge(): void
        {
            $this->converged++;
        }
    };
    app()->instance(PrivateDnsManager::class, $dns);

    new ReflectionMethod(ProvisionNodeAction::class, 'publishRouterAddress')->invoke(app(ProvisionNodeAction::class), $node);
}

function provisionedNode(string $name, string $address, ?LifecycleStatus $router): Node
{
    $cluster = Cluster::query()->create(['name' => 'dev-'.$name, 'tld' => $name]);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit', 'cluster_id' => $cluster->id, 'wireguard_public_key' => $name.'-key']));

    if ($router instanceof LifecycleStatus) {
        NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::Router, 'status' => $router, 'cluster_id' => $cluster->id]);
    }

    return $node;
}

describe('Provisioning an existing Router', function (): void {
    it('publishes the private DNS, because its Cluster TLD answers with the Router address', function (): void {
        $converged = 0;

        publishAfterProvisioning(provisionedNode('beast', '10.44.0.7', LifecycleStatus::Active), $converged);

        expect($converged)->toBe(1);
    });

    it('publishes nothing for a Node that is no active Router; its roles published their own DNS', function (): void {
        $converged = 0;

        publishAfterProvisioning(provisionedNode('sabre', '10.44.0.8', null), $converged);
        publishAfterProvisioning(provisionedNode('shark', '10.44.0.9', LifecycleStatus::Provisioning), $converged);

        expect($converged)->toBe(0);
    });
});

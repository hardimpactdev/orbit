<?php

declare(strict_types=1);

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Domain\Instances\ContainerNodes;
use App\Domain\Instances\InstanceFleetGateway;
use App\Domain\Instances\NestedFleetLayout;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A Node at an address of the nested fleet's layout, as the nested Gateway records it. */
function fleetNode(string $name, string $address): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $address, 'public_ssh_host' => $address, 'user' => 'orbit',
    ]));
}

beforeEach(function (): void {
    $this->marker = sys_get_temp_dir().'/orbit-instance-fleet-'.getmypid().'-'.bin2hex(random_bytes(4));
    config(['instances.fleet_marker' => $this->marker, 'instances.container_nodes' => []]);
});

afterEach(fn () => @unlink($this->marker));

it('puts the fleet\'s app-dev Node on the build list of the nested Gateway of an Instance fleet only', function (): void {
    $appDev = fleetNode('app-dev', NestedFleetLayout::Layout['app-dev']['address']);
    $appProd = fleetNode('app-prod', NestedFleetLayout::Layout['app-prod']['address']);
    $nodes = app(ContainerNodes::class);
    expect($nodes->includes($appDev))->toBeFalse();

    touch($this->marker);

    expect($nodes->includes($appDev))->toBeTrue()
        ->and($nodes->includes($appProd))->toBeFalse()
        ->and($nodes->ids())->toBe([]);
});

it('reads the fleet\'s egress proxy from HTTPS_PROXY on the nested Gateway of an Instance fleet only', function (): void {
    $environment = $this->marker.'-environment';
    file_put_contents($environment, "PATH=\"/usr/bin\"\nHTTP_PROXY=\"http://10.253.0.1:3128\"\nHTTPS_PROXY=\"http://10.253.0.1:3129\"\n");
    config(['instances.fleet_environment' => $environment]);
    try {
        expect(InstanceFleetGateway::egressProxy())->toBeNull();
        touch($this->marker);
        expect(InstanceFleetGateway::egressProxy())->toBe(['host' => '10.253.0.1', 'port' => 3129]);
        file_put_contents($environment, "HTTPS_PROXY=\"http://proxy.example:3128\"\n");
        expect(InstanceFleetGateway::egressProxy())->toBeNull();
        // Squid's parent is a plain HTTP proxy, so a TLS proxy is no parent.
        file_put_contents($environment, "HTTPS_PROXY=\"https://10.253.0.1:3128\"\n");
        expect(InstanceFleetGateway::egressProxy())->toBeNull();
    } finally {
        unlink($environment);
    }
});

it('gives the fleet Node no Instance bridge that holds the fleet\'s egress proxy', function (): void {
    $environment = $this->marker.'-environment';
    file_put_contents($environment, "PATH=\"/usr/bin\"\nHTTPS_PROXY=\"http://10.253.0.1:3128\"\n");
    config(['instances.fleet_environment' => $environment]);
    $appDev = fleetNode('app-dev', NestedFleetLayout::Layout['app-dev']['address']);
    $other = fleetNode('app-prod', NestedFleetLayout::Layout['app-prod']['address']);
    expect(app(AllocateInstanceBridgeAction::class)->execute($other))->toBe('10.253.0.0/24');
    Node::query()->whereKey($other->id)->update(['instance_bridge' => null]);

    touch($this->marker);
    try {
        expect(app(AllocateInstanceBridgeAction::class)->execute($appDev))->toBe('10.253.1.0/24');
    } finally {
        unlink($environment);
    }
});

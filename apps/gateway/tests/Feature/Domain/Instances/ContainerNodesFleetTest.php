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
    config(['instances.fleet_marker' => $this->marker]);
});

afterEach(fn () => @unlink($this->marker));

it('identifies the fleet\'s app-dev Node only in the nested Gateway of an Instance fleet', function (): void {
    $appDev = fleetNode('app-dev', NestedFleetLayout::Layout['app-dev']['address']);
    $appProd = fleetNode('app-prod', NestedFleetLayout::Layout['app-prod']['address']);
    expect(ContainerNodes::fleetNode($appDev))->toBeFalse();

    touch($this->marker);

    expect(ContainerNodes::fleetNode($appDev))->toBeTrue()
        ->and(ContainerNodes::fleetNode($appProd))->toBeFalse();
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

it('reads the host\'s fleet marker and egress proxy from the Gateway environment projection in a release container', function (): void {
    $projection = $this->marker.'-projection';
    mkdir($projection, 0700);
    // The container's own files say nothing of the fleet; the host's copies in the projection decide.
    $environment = $this->marker.'-environment';
    file_put_contents($environment, "HTTPS_PROXY=\"http://10.99.0.1:3128\"\n");
    touch($this->marker);
    config(['orbit.gateway_container' => true, 'instances.fleet_projection' => $projection, 'instances.fleet_environment' => $environment]);
    $appDev = fleetNode('app-dev', NestedFleetLayout::Layout['app-dev']['address']);
    $appProd = fleetNode('app-prod', NestedFleetLayout::Layout['app-prod']['address']);
    try {
        // An outer Gateway: the host has no marker, so the host refresh publishes none.
        expect(InstanceFleetGateway::holds())->toBeFalse()
            ->and(InstanceFleetGateway::egressProxy())->toBeNull()
            ->and(ContainerNodes::fleetNode($appDev))->toBeFalse();

        touch($projection.'/instance-fleet');
        file_put_contents($projection.'/instance-fleet-environment', "PATH=\"/usr/bin\"\nHTTPS_PROXY=\"http://10.253.0.1:3129\"\n");

        expect(InstanceFleetGateway::holds())->toBeTrue()
            ->and(InstanceFleetGateway::egressProxy())->toBe(['host' => '10.253.0.1', 'port' => 3129])
            ->and(ContainerNodes::fleetNode($appDev))->toBeTrue()
            // The proxy's /24 stays free, so app-prod's bridge no longer holds the fleet's way out.
            ->and(app(AllocateInstanceBridgeAction::class)->execute($appProd))->toBe('10.253.1.0/24');
    } finally {
        array_map(unlink(...), [...glob($projection.'/*') ?: [], $environment]);
        rmdir($projection);
    }
});

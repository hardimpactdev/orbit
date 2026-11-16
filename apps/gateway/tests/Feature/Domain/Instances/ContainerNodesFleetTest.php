<?php

declare(strict_types=1);

use App\Domain\Instances\ContainerNodes;
use App\Domain\TaskVms\TaskVmNestedFleet;
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
    $appDev = fleetNode('app-dev', TaskVmNestedFleet::Layout['app-dev']['address']);
    $appProd = fleetNode('app-prod', TaskVmNestedFleet::Layout['app-prod']['address']);
    $nodes = app(ContainerNodes::class);
    expect($nodes->includes($appDev))->toBeFalse();

    touch($this->marker);

    expect($nodes->includes($appDev))->toBeTrue()
        ->and($nodes->includes($appProd))->toBeFalse()
        ->and($nodes->ids())->toBe([]);
});

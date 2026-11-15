<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeUpdatePolicy;
use App\Domain\TaskVms\TaskVmAptCache;
use App\Models\Node;

// A Gateway without the marker of an Instance fleet, whatever machine runs the suite.
beforeEach(fn () => config(['instances.fleet_marker' => sys_get_temp_dir().'/orbit-no-instance-fleet-'.getmypid()]));

it('names the package cache of the task VM for a Node inside the nested subnet', function (string $address): void {
    expect(TaskVmAptCache::forAddress($address))->toBe('http://10.232.2.1:3142')
        ->and(TaskVmAptCache::for(new Node(['public_ssh_host' => $address])))->toBe('http://10.232.2.1:3142');
})->with(['the Gateway' => '10.232.2.11', 'app-dev' => '10.232.2.12', 'app-prod' => '10.232.2.13']);

it('names no package cache for every other Node', function (?string $address): void {
    expect(TaskVmAptCache::forAddress((string) $address))->toBeNull()
        ->and(TaskVmAptCache::for(new Node(['public_ssh_host' => $address])))->toBeNull();
})->with([
    'a Node on the network of its host' => '10.251.78.178',
    'a Node on the WireGuard network' => '10.44.0.131',
    'a public address' => '85.9.211.193',
    'the next subnet' => '10.232.3.12',
    'a name' => 'node.example.test',
    'no address' => null,
]);

it('names no package cache on the nested Gateway of an Instance fleet, which has the same subnet and no cache', function (string $address): void {
    $marker = tempnam(sys_get_temp_dir(), 'orbit-instance-fleet-');
    config(['instances.fleet_marker' => $marker]);
    $node = new Node(['public_ssh_host' => $address]);

    try {
        expect(TaskVmAptCache::forAddress($address))->toBeNull()
            ->and(TaskVmAptCache::for($node))->toBeNull()
            // The daily timers stay off on every nested Node: its fleet is refreshed by Orbit, not patched.
            ->and(NodeUpdatePolicy::for($node)['daily'])->toBeFalse();
    } finally {
        unlink($marker);
    }
})->with(['the Gateway' => '10.232.2.11', 'app-dev' => '10.232.2.12', 'app-prod' => '10.232.2.13']);

it('keeps the daily timers off for a task VM Node and on for every other Node', function (string $address, bool $daily): void {
    expect(NodeUpdatePolicy::for(new Node(['public_ssh_host' => $address]))['daily'])->toBe($daily);
})->with(['a task VM app-dev Node' => ['10.232.2.12', false], 'a Node on the WireGuard network' => ['10.44.0.131', true]]);

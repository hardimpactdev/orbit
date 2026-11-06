<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmAptCache;
use App\Models\Node;

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

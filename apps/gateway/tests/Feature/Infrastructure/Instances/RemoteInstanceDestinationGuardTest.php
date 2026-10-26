<?php

declare(strict_types=1);

use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteInstanceDestinationGuard;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('refuses a destination that the agent finds occupied and accepts a free one', function (bool $free): void {
    $node = Node::query()->create(['name' => 'destination', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.41', 'wireguard_ip' => '10.44.0.41', 'user' => 'orbit']);
    $agents = new FakeAgentCommandRunner()->answer('instance.check-path-free', ['free' => $free]);
    $guard = new RemoteInstanceDestinationGuard($agents);
    $assert = fn () => $guard->assertUnoccupied($node, StoragePath::parse('/home/orbit/apps/shop/main'));

    if ($free) {
        $assert();
    } else {
        expect($assert)->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.migration_conflict'));
    }
    expect($agents->calls[0]['input'])->toBe(['path' => '/home/orbit/apps/shop/main']);
})->with(['free' => true, 'occupied' => false]);

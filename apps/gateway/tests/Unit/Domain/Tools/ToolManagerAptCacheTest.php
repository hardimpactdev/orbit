<?php

declare(strict_types=1);

use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Models\Node;

it('reads the Ubuntu snapshot through the package cache of the task VM for a nested Node', function (): void {
    $node = new Node(['platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '10.232.2.12']);

    $input = app(ToolManagerIntent::class)->nativeInput($node, ToolManagerName::Composer);

    expect($input['apt']['proxy'])->toBe('http://10.232.2.1:3142');
});

it('names no package cache for every other Node', function (?string $host): void {
    $node = new Node(['platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => $host]);

    $input = app(ToolManagerIntent::class)->nativeInput($node, ToolManagerName::Composer);

    expect($input['apt'])->not->toHaveKey('proxy')->and($input['apt'])->toHaveKeys(['snapshot', 'packages']);
})->with([
    'a Node on the network of its host' => '10.251.78.178',
    'a Node on the WireGuard network' => '10.44.0.131',
    'a public address' => '85.9.211.193',
    'the next subnet' => '10.232.3.12',
    'no address' => null,
]);

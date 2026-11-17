<?php

declare(strict_types=1);

use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Models\Node;

// A Gateway without the marker of an Instance fleet, whatever machine runs the suite.
beforeEach(fn () => config(['instances.fleet_marker' => sys_get_temp_dir().'/orbit-no-instance-fleet-'.getmypid()]));

it('uses the Node apt settings without selecting a VM cache by address', function (?string $host): void {
    $node = new Node(['platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => $host]);

    $input = app(ToolManagerIntent::class)->nativeInput($node, ToolManagerName::Composer);

    expect($input['apt'])->not->toHaveKey('proxy')->and($input['apt'])->toHaveKeys(['snapshot', 'packages']);
})->with([
    'a Node in the nested subnet without a fleet marker' => '10.232.2.12',
    'a Node on the network of its host' => '10.251.78.178',
    'a Node on the WireGuard network' => '10.44.0.131',
    'a public address' => '85.9.211.193',
    'the next subnet' => '10.232.3.12',
    'no address' => null,
]);

it('reads the Ubuntu snapshot through the Node\'s own apt settings on the nested Gateway of an Instance fleet', function (): void {
    $marker = tempnam(sys_get_temp_dir(), 'orbit-instance-fleet-');
    config(['instances.fleet_marker' => $marker]);
    $node = new Node(['platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '10.232.2.12']);

    try {
        expect(app(ToolManagerIntent::class)->nativeInput($node, ToolManagerName::Composer)['apt'])->not->toHaveKey('proxy');
    } finally {
        unlink($marker);
    }
});

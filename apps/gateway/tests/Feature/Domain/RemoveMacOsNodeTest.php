<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAction;
use App\Domain\Nodes\RoleName;
use Tests\Support\Fleet\FleetFixtures;

it('records unmanaged peer removal without any Linux Node commands', function (string $platform, array $retained): void {
    $caller = FleetFixtures::node('unmanaged-removal-gateway', [RoleName::Gateway]);
    $hub = FleetFixtures::node('unmanaged-removal-vpn', [RoleName::Vpn]);
    $node = FleetFixtures::node('unmanaged-removal-target', platform: $platform);
    $node->update(['wireguard_public_key' => 'UNMANAGED_KEY']);
    $operation = app(RemoveNodeAction::class)->start($node, $caller);

    expect($operation->input['_node_result']['retained_on_node'])->toBe($retained);
    expect($operation->input['_node_result']['follow_up'])->toBeNull();
    expect(array_filter($operation->input['_node_plan'], static fn (array $step): bool => $step['type'] === 'run'))->toBe([]);
    expect(array_find($operation->state['child_intents'], static fn (array $child): bool => $child['key'] === 'remove-hub'))
        ->toMatchArray(['kind' => 'node.wireguard_hub_converge', 'node_id' => $hub->id]);
    $this->assertModelExists($node);
})->with([
    ['macos', ['user', 'package-managers', 'host-wireguard']],
    ['ipados', ['host-wireguard']],
]);

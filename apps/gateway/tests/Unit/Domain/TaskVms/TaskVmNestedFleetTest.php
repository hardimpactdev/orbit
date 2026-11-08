<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmNestedFleet;
use App\Domain\TaskVms\TaskVmSize;

it('gives every nested container an address inside the subnet of the nested network and a MAC of its own', function (): void {
    $layout = TaskVmNestedFleet::Layout;

    expect(array_unique(array_column($layout, 'address')))->toHaveCount(3)
        ->and(array_unique(array_column($layout, 'hwaddr')))->toHaveCount(3)
        ->and(array_column($layout, 'address'))->each->toStartWith('10.232.2.')
        ->and(array_column($layout, 'wireguard'))->each->toStartWith('10.45.0.');
});

it('holds the nested containers only in a large VM, because they hold more memory than a medium VM has', function (): void {
    $memory = static fn (string $gib): int => (int) $gib;
    $nested = array_sum(array_map(static fn (array $node): int => $memory($node['memory']), TaskVmNestedFleet::Layout));

    expect($memory(TaskVmSize::Large->resources()->memory))->toBeGreaterThan($nested)
        ->and($memory(TaskVmSize::Medium->resources()->memory))->toBeLessThanOrEqual($nested);
});

it('renders the checkout as a disk device and the state mounts under the names the nested apply step checks', function (): void {
    $checkout = TaskVmNestedFleet::checkout();

    expect($checkout)->not->toHaveKey('server')
        ->and($checkout['path'])->toBe('/home/orbit/orbit')
        ->and($checkout['binds']['app-dev'])->toBe(['home-orbit-orbit-apps-gateway-.env.mount', 'home-orbit-orbit-apps-gateway-storage.mount', 'home-orbit-orbit-apps-gateway-bootstrap-cache.mount'])
        ->and($checkout['binds']['gateway'])->toContain('home-orbit-orbit-swamp-.swamp.mount', 'home-orbit-orbit-swamp-vaults.mount');
    $units = collect($checkout['units'])->groupBy('guest');
    expect($units['nested-gateway'])->toHaveCount(6)
        ->and($units['nested-app-dev'])->toHaveCount(3)
        ->and(collect($checkout['units'])->pluck('name'))->not->toContain('home-orbit-orbit.mount');
    foreach ($checkout['units'] as $unit) {
        expect($unit['content'])->toContain("Type=none\nOptions=bind\n", "[Install]\nWantedBy=multi-user.target\n")->not->toContain('_netdev', 'nfs');
    }
});

it('never starts the state mounts with their requirements, whose device no udev confirms in a container', function (): void {
    $binds = TaskVmNestedFleet::checkout()['binds']['app-dev'];
    $apply = file_get_contents(dirname(base_path(), 2).'/swamp/workflows/workflow-task-nested-apply.yaml');

    // The containers start with the checkout attached, so the boot mounts the state and the apply step only checks it.
    expect($apply)->toContain("'sh', '-c', 'for u; do systemctl is-active --quiet \"\$u\" || { echo \"\$u is not active\" >&2; exit 1; }; done', 'sh', '".implode("', '", $binds)."'")
        ->and(preg_match("/'systemctl', 'start', 'home-orbit-orbit-/", $apply))->toBe(0);
});

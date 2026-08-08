<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Nodes\GatewayCliConfigAccessProbe;
use App\Services\Nodes\PosixReadAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function gatewayCliConfigNode(bool $gateway = true): Node
{
    $factory = Node::factory();

    return ($gateway ? $factory->gateway() : $factory->appProd())->create([
        'name' => $gateway ? 'gateway' : 'app-prod-1',
        'user' => 'orbit',
        'status' => 'active',
    ]);
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-gateway-cli-config-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->root);
    putenv('ORBIT_HOST_PATH_PREFIX');
});

afterEach(function (): void {
    putenv('ORBIT_HOST_PATH_PREFIX');

    if (isset($this->root)) {
        new SymfonyProcess(['chmod', '-R', 'u+rwX', $this->root])->run();
        new SymfonyProcess(['rm', '-rf', $this->root])->run();
    }
});

/**
 * The readability predicate is the discriminating part of this probe: the
 * incident was an owner mismatch, which cannot be reproduced in a test without
 * root. Driving it with explicit stat values covers that shape deterministically.
 */
it('treats an owner-only tree owned by the canonical user as traversable', function (): void {
    expect(PosixReadAccess::permits(
        subject: [1001, 1001],
        owner: [1001, 1001],
        mode: 0o700,
        isDirectory: true,
    ))->toBeTrue();
});

it('treats the incident shape as unreadable: owner-only tree owned by an unrelated account', function (): void {
    // The live gateway: config root owned 999:999 at 0700 while the host CLI
    // runs as uid 1001, so it could not read its own config.
    expect(PosixReadAccess::permits(
        subject: [1001, 1001],
        owner: [999, 999],
        mode: 0o700,
        isDirectory: true,
    ))->toBeFalse();
});

it('accepts a foreign-owned tree when group access grants the canonical user', function (): void {
    expect(PosixReadAccess::permits(
        subject: [1001, 1001],
        owner: [999, 1001],
        mode: 0o750,
        isDirectory: true,
    ))->toBeTrue();
});

it('rejects a directory the canonical user can read but not traverse', function (): void {
    expect(PosixReadAccess::permits(
        subject: [1001, 1001],
        owner: [1001, 1001],
        mode: 0o600,
        isDirectory: true,
    ))->toBeFalse();
});

it('accepts a traversable directory even when it cannot be listed', function (): void {
    // 0711 is traversable by others: opening a known file inside needs only the
    // execute bit. Requiring read here would be a false positive.
    expect(PosixReadAccess::permits(
        subject: [1001, 1001],
        owner: [999, 999],
        mode: 0o711,
        isDirectory: true,
    ))->toBeTrue();
});

it('selects the owner class even when a later class is more permissive', function (): void {
    // Owner has no read; group/other do. POSIX stops at the first matching
    // class, so the canonical owner is still denied.
    expect(PosixReadAccess::permits(
        subject: [1001, 2002],
        owner: [1001, 2002],
        mode: 0o077,
        isDirectory: false,
    ))->toBeFalse();
});

it('accepts an owner-readable file, which needs no traversal bit', function (): void {
    expect(PosixReadAccess::permits(
        subject: [1001, 1001],
        owner: [1001, 1001],
        mode: 0o600,
        isDirectory: false,
    ))->toBeTrue();
});

it('rejects a world-unreadable file owned by an unrelated account', function (): void {
    expect(PosixReadAccess::permits(
        subject: [1001, 1001],
        owner: [999, 999],
        mode: 0o600,
        isDirectory: false,
    ))->toBeFalse();
});

it('reports no drift when the gateway has no host path prefix', function (): void {
    $node = gatewayCliConfigNode();

    expect(new GatewayCliConfigAccessProbe()->diff($node))->toBe([]);
});

it('reports no drift for a node that is not the gateway', function (): void {
    $hostPrefix = "{$this->root}/mnt/orbit-host";
    File::ensureDirectoryExists("{$hostPrefix}/home/orbit/.config/orbit");
    putenv("ORBIT_HOST_PATH_PREFIX={$hostPrefix}");

    $node = gatewayCliConfigNode(gateway: false);

    expect(new GatewayCliConfigAccessProbe()->diff($node))->toBe([]);
});

it('reports drift when the gateway host config root cannot be traversed', function (): void {
    $hostPrefix = "{$this->root}/mnt/orbit-host";
    $configRoot = "{$hostPrefix}/home/orbit/.config/orbit";
    File::ensureDirectoryExists($configRoot);
    File::put("{$configRoot}/config.json", '{}');
    putenv("ORBIT_HOST_PATH_PREFIX={$hostPrefix}");

    chmod($configRoot, 0o600);

    $drift = new GatewayCliConfigAccessProbe()->diff(gatewayCliConfigNode());

    expect($drift)->toHaveCount(1);

    $entry = $drift[0];

    expect($entry->family)
        ->toBe('node')
        ->and($entry->key)
        ->toBe('node.gateway_cli_config_unreadable')
        ->and($entry->detail['path'] ?? null)
        ->toBe('/home/orbit/.config/orbit');
});

it('reports drift when the gateway host config file cannot be read', function (): void {
    $hostPrefix = "{$this->root}/mnt/orbit-host";
    $configRoot = "{$hostPrefix}/home/orbit/.config/orbit";
    File::ensureDirectoryExists($configRoot);
    File::put("{$configRoot}/config.json", '{}');
    putenv("ORBIT_HOST_PATH_PREFIX={$hostPrefix}");

    chmod("{$configRoot}/config.json", 0o200);

    $drift = new GatewayCliConfigAccessProbe()->diff(gatewayCliConfigNode());

    expect($drift)->toHaveCount(1);

    expect($drift[0]->key)
        ->toBe('node.gateway_cli_config_unreadable')
        ->and($drift[0]->detail['path'] ?? null)
        ->toBe('/home/orbit/.config/orbit/config.json');
});

it('reports no drift when the gateway host config is readable by the canonical user', function (): void {
    $hostPrefix = "{$this->root}/mnt/orbit-host";
    $configRoot = "{$hostPrefix}/home/orbit/.config/orbit";
    File::ensureDirectoryExists($configRoot);
    File::put("{$configRoot}/config.json", '{}');
    putenv("ORBIT_HOST_PATH_PREFIX={$hostPrefix}");

    expect(new GatewayCliConfigAccessProbe()->diff(gatewayCliConfigNode()))->toBe([]);
});

it('reports no drift when the gateway host config root is absent', function (): void {
    $hostPrefix = "{$this->root}/mnt/orbit-host";
    File::ensureDirectoryExists("{$hostPrefix}/home/orbit");
    putenv("ORBIT_HOST_PATH_PREFIX={$hostPrefix}");

    expect(new GatewayCliConfigAccessProbe()->diff(gatewayCliConfigNode()))->toBe([]);
});

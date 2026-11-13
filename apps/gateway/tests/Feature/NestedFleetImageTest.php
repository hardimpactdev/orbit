<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceImage;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('renders a separate pinned image with inner users, subordinate IDs and an app uid firewall', function (): void {
    $incus = app(RecipeImageBuilds::class)->currentRecipe()->incus('amd64');
    $normal = InstanceImage::for('10.253.0.1');
    $nested = InstanceImage::for('10.253.0.1', $incus);
    $files = array_column($nested['files'], 'content', 'path');
    $normalFiles = array_column($normal['files'], 'content', 'path');
    expect($normal['packages'])->toBe(['acl', 'git', 'nftables'])
        ->and($normalFiles['/etc/sysctl.d/90-orbit-no-userns.conf'])->toContain('user.max_user_namespaces = 0')
        ->and($normalFiles)->not->toHaveKeys(['/etc/sudoers.d/orbit-agent', '/etc/subuid'])
        ->and($normal['prepare'])->not->toContain('incus-admin')
        ->and($nested['alias'])->toStartWith('orbit-instance-nested-')->not->toBe($normal['alias'])
        ->and($nested['packages'])->toContain('wireguard-tools', 'sqlite3', 'sudo')
        ->and($nested['incus'])->toBe(array_values(array_filter($incus['packages'], fn (array $package): bool => $package['name'] !== 'incus')))
        ->and(array_column($nested['incus'], 'name'))->toBe(['incus-base', 'incus-client'])
        ->and($nested['prepare'])->toContain('usermod --append --groups incus-admin agent')->not->toContain('incus-admin orbit')
        ->and($files)->not->toHaveKey('/etc/sysctl.d/90-orbit-no-userns.conf')
        ->and($files['/etc/subuid'])->toBe("root:1000000:100000000\n")->and($files['/etc/subgid'])->toBe($files['/etc/subuid'])
        ->and($files['/etc/orbit/apparmor-off'])->toBe("N\n")
        ->and($files['/etc/sudoers.d/orbit-agent'])->toBe("agent ALL=(ALL:ALL) NOPASSWD: ALL\n")
        ->and($files['/etc/nftables.conf'])->toContain('ip daddr { 10.45.0.0/24, 10.232.2.0/24 } meta skuid 1000 drop')
        ->and($normalFiles['/etc/nftables.conf'])->not->toContain('10.45.0.0/24', '10.232.2.0/24');
    $incus['packages'][1]['sha256'] = str_repeat('b', 64);
    expect(InstanceImage::for('10.253.0.1', $incus)['alias'])->not->toBe($nested['alias']);
});

<?php

declare(strict_types=1);

use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\OrbitHostInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('copies bootstrap authorized keys to the runtime user before installing orbit', function (): void {
    Process::fake(fn () => Process::result());

    app(OrbitHostInstaller::class)->install('192.0.2.10', 'root', 'orbit');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "'root'@'192.0.2.10'")
        && str_contains((string) $process->command, 'BOOTSTRAP_KEYS="/root/.ssh/authorized_keys"')
        && str_contains((string) $process->command, 'TARGET_KEYS="/home/$USER/.ssh/authorized_keys"')
        && str_contains((string) $process->command, 'sudo grep -qxF "$key" "$TARGET_KEYS"'));
});

it('runs the pre-wireguard node security baseline over pinned ssh during provisioning', function (): void {
    $node = Node::factory()->create([
        'host' => '203.0.113.20',
        'wireguard_address' => '10.6.0.20',
        'user' => 'orbit',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
        'host_key_fingerprint' => 'SHA256:test',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ]);

    Process::fake(fn () => Process::result());

    $installer = app(OrbitHostInstaller::class);
    $installer->usePinnedNode($node);

    $result = $installer->install('203.0.113.20', 'ubuntu', 'orbit');

    expect($result->successful)->toBeTrue()
        ->and(FirewallRule::query()->where('node_id', $node->id)->where('owner', 'node-security')->count())->toBe(0);

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, '-o StrictHostKeyChecking=yes')
        && str_contains((string) $process->command, "'ubuntu'@'203.0.113.20'")
        && str_contains((string) $process->command, 'sudo useradd -m -s /bin/bash "$USER"'));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, '-o StrictHostKeyChecking=yes')
        && str_contains((string) $process->command, "'orbit'@'203.0.113.20'")
        && str_contains((string) $process->command, '/etc/sysctl.d/60-orbit.conf'));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "'orbit'@'203.0.113.20'")
        && str_contains((string) $process->command, 'ListenAddress 10.6.0.20'));

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "'orbit'@'203.0.113.20'")
        && str_contains((string) $process->command, 'unattended-upgrades'));

    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'ufw deny in on "$PUBLIC_IFACE"'));
});

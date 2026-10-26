<?php

declare(strict_types=1);

use App\Infrastructure\TaskVms\TaskVmVolumes;
use Symfony\Component\Process\Process;

/**
 * The names of the topology seed (ADR 0222) live in four places that never load each other: the Gateway, which attaches
 * the disk; the agent that builds the seed; the harness, which copies Nodes; and host setup in the task VM, which
 * adopts the clone. This test reads each one as its own runtime does and checks that they agree.
 */
it('names the seed disk, pool, and template the same in the Gateway, the agent, the harness, and host setup', function (): void {
    $root = dirname(base_path(), 2);

    // Rust owns these fixed names; compare them with the independent harness and disk attachment.
    $source = (string) file_get_contents("{$root}/apps/agent/src/commands/incus/seed.rs");
    preg_match_all('/const (POOL|TEMPLATE|DEVICE): &str =\s*"([^"]+)";/', $source, $constants, PREG_SET_ORDER);
    $seed = [];
    foreach ($constants as $constant) {
        $seed[strtolower($constant[1])] = $constant[2];
    }

    // Host setup: its assignments, evaluated by Bash.
    $setup = new Process(['bash', '-c', 'eval "$(grep -E "^(pool|template|seed_device|device)=" "$1")"; printf "%s\n" "$pool" "$template" "$seed_device" "$device"', 'host-setup', "{$root}/bin/e2e-topology-host-setup"]);
    $setup->mustRun();
    [$pool, $template, $seedDevice, $device] = explode("\n", trim($setup->getOutput()));

    // The harness: its IncusHost constants.
    $harness = (string) file_get_contents("{$root}/apps/e2e/app/E2E/IncusHost.php");
    preg_match("/public const string POOL = '([^']+)';/", $harness, $harnessPool);
    preg_match("/public const string TEMPLATE = '([^']+)';/", $harness, $harnessTemplate);

    expect(TaskVmVolumes::SeedDevice)->toBe('topology-seed')
        ->and($seedDevice)->toBe(TaskVmVolumes::SeedDevice)
        ->and($seed['device'])->toBe('/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_topology--seed')
        ->and($device)->toBe($seed['device'])
        ->and([$seed['pool'], $pool, $harnessPool[1] ?? null])->toBe(['topology-seed', 'topology-seed', 'topology-seed'])
        ->and([$seed['template'], $template, $harnessTemplate[1] ?? null])->toBe(['node-template', 'node-template', 'node-template']);
});

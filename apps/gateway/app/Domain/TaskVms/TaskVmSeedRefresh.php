<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Infrastructure\TaskVms\TaskVmVolumes;

/**
 * The fixed names of a topology seed refresh on one host. A host refreshes one seed at a time, so the builder VM has
 * one name. The builder is a large task VM that is not a fleet Node: it boots from the task image with a clone of the
 * image's current seed, builds the ready topology in it, and leaves its clone as the new seed. See
 * docs/cli/tasks/vm-seed-refresh.mmd.
 */
final readonly class TaskVmSeedRefresh
{
    /** The builder VM. No digits follow `tvm-`, so the sweep and the slot patterns never match it. */
    public const string BuilderName = 'tvm-seed-refresh';

    /**
     * The proof group of the build. The nested topology belongs to the task `TASK-<group>`, and the checkout branch must
     * name it. The ready copies carry this label until the reset from ready copies relabels them.
     */
    public const string Group = '1';

    public const string Branch = 'seed-refresh/task-'.self::Group;

    /** The ready copies that the build leaves in the seed, one per role, as `ready-<role>`. */
    public const array Roles = ['gateway', 'app-dev', 'app-prod'];

    /** The checkout of the build, in the builder. */
    public const string Checkout = '/home/orbit/apps/orbit/workspace';

    /** The seed under construction: the builder's clone of the current seed. It becomes the new seed. */
    public static function building(string $fingerprint): string
    {
        return TaskVmVolumes::refreshSeed($fingerprint);
    }

    /** The instance config of the builder, as the `incus init` stdin: the task image, a large VM and the seed clone. */
    public static function config(TaskVmHost $host, string $fingerprint): string
    {
        $size = TaskVmSize::Large->resources();

        return json_encode([
            'config' => [
                'limits.cpu' => (string) $size->cpusOn($host), 'limits.memory' => $size->memoryOn($host),
                'raw.qemu.conf' => (string) $size->qemuConfig,
            ],
            'devices' => [
                'root' => ['type' => 'disk', 'path' => '/', 'pool' => $host->pool, 'size' => $size->diskOn($host)],
                'eth0' => ['type' => 'nic', 'network' => $host->network, 'security.port_isolation' => 'true'],
                TaskVmVolumes::SeedDevice => ['type' => 'disk', 'pool' => $host->pool, 'source' => self::building($fingerprint)],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

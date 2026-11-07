<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Models\Node;

/**
 * The custom volumes that Orbit owns in a task VM host's pool ([ADR 0222](/decisions/0222-give-task-vms-a-cloned-topology-seed)):
 *
 * - `orbit-topology-seed-<12 hex>`: the topology seed of a base image with that fingerprint, which earlier builds and refreshes made
 *   (with the endings `-new`, `-refresh` and `-previous`). Pruning deletes it with its image. The seed of the base VM is
 *   `<host>-base-seed`, which the audit owns by name.
 * - `tvm-<id>-topology-seed`: the clone of its base image's topology seed that a VM whose size runs a topology boots
 *   with. It goes with the VM.
 *
 * Incus keeps a ZFS clone's origin until the last clone is gone, so a seed can go while VMs still use their clones.
 */
final readonly class TaskVmVolumes
{
    /** The disk device of a task VM and of the smoke VM that holds the topology seed or its clone. */
    public const string SeedDevice = 'topology-seed';

    /** The size of every topology seed. ZFS volumes are thin, so a clone uses only what its Nodes write. */
    public const string SeedSize = '45GiB';

    /** The start of the name of every topology seed. */
    public const string SeedPrefix = 'orbit-topology-seed-';

    private const string Clone = '/\A(tvm-[0-9]+)-topology-seed\z/D';

    private const string Seed = '/\Aorbit-topology-seed-([a-f0-9]{12})(?:-new|-refresh|-previous)?\z/D';

    public function __construct(private IncusHost $incus) {}

    /**
     * Orbit owns the volume `$name` in the host's pool: a VM's clone, or the topology seed of a base image in
     * `$fingerprints`, the images the host keeps.
     *
     * @param  list<string>  $fingerprints
     */
    public static function owns(string $name, array $fingerprints): bool
    {
        if (preg_match(self::Clone, $name) === 1) {
            return true;
        }

        return preg_match(self::Seed, $name, $match) === 1
            && array_any($fingerprints, static fn (string $fingerprint): bool => str_starts_with($fingerprint, $match[1]));
    }

    /** @phpstan-impure */
    public function exists(TaskVmHost $host, Node $node, string $name): bool
    {
        return in_array($name, $this->names($host, $node), true);
    }

    /**
     * @return list<string> the custom volumes of the host's project in its pool
     *
     * @phpstan-impure
     */
    private function names(TaskVmHost $host, Node $node): array
    {
        return $this->incus->volumeNames($host, $node);
    }
}

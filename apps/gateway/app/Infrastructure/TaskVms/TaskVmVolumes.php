<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Models\Node;
use App\Models\TaskVm;

/**
 * The custom volumes that Orbit owns in a task VM host's pool ([ADR 0222](/decisions/0222-give-task-vms-a-cloned-topology-seed)):
 *
 * - `orbit-topology-seed-<12 hex>`: the topology seed of the base image with that fingerprint. The image build creates
 *   it as `orbit-topology-seed-<12 hex>-new` and renames it when it is finished, and pruning deletes it with its image.
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

    private const string Clone = '/\A(tvm-[0-9]+)-topology-seed\z/D';

    private const string Seed = '/\Aorbit-topology-seed-([a-f0-9]{12})(?:-new)?\z/D';

    public function __construct(private IncusHost $incus) {}

    /** The topology seed of the base image `$fingerprint`. */
    public static function seed(string $fingerprint): string
    {
        return 'orbit-topology-seed-'.substr($fingerprint, 0, 12);
    }

    /** The name a topology seed has while the image build creates it. */
    public static function newSeed(string $fingerprint): string
    {
        return self::seed($fingerprint).'-new';
    }

    /** The clone of the topology seed that `$vm` boots with. */
    public static function clone(TaskVm $vm): string
    {
        return $vm->name.'-topology-seed';
    }

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

    /**
     * Copies the topology seed of the VM's base image to the VM's clone, which ZFS makes a clone. A clone that exists
     * stays, so a retried launch copies nothing twice. A missing seed fails: no VM that can run a topology boots
     * without one.
     */
    public function cloneSeed(TaskVmHost $host, Node $node, TaskVm $vm): string
    {
        try {
            return $this->incus->volume($host, $node, ['kind' => 'clone_seed', 'vm' => $vm->name, 'fingerprint' => $vm->image_fingerprint]);
        } catch (AgentCommandRefused $error) {
            if ($error->errorCode === TaskVmError::TopologySeedMissing->value) {
                throw new TaskVmException(TaskVmError::TopologySeedMissing, "The host of task VM [{$vm->name}] has no topology seed for its base image. Build the image again with task-vms:build-image.");
            }

            throw $error;
        }
    }

    /** Deletes the clone of a VM whose instance is gone. Incus keeps it when it deletes the VM. */
    public function release(TaskVmHost $host, Node $node, TaskVm $vm): void
    {
        $this->delete($host, $node, self::clone($vm));
    }

    /**
     * Deletes the clones whose VM has no live row on the host.
     *
     * @return list<string>
     */
    public function sweepClones(TaskVmHost $host, Node $node): array
    {
        // Names first, then rows: a VM that a claim records in between keeps its clone.
        $names = $this->names($host, $node);
        $live = TaskVm::query()->live()->where('host_node_id', $node->id)->pluck('name')->all();
        $deleted = [];
        foreach ($names as $name) {
            if (preg_match(self::Clone, $name, $match) === 1 && ! in_array($match[1], $live, true)) {
                $this->delete($host, $node, $name);
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    /** @phpstan-impure */
    public function exists(TaskVmHost $host, Node $node, string $name): bool
    {
        return in_array($name, $this->names($host, $node), true);
    }

    /** Renames the finished topology seed of `$fingerprint` from its build name. A seed with its final name stays. */
    public function finishSeed(TaskVmHost $host, Node $node, string $fingerprint): void
    {
        $this->incus->volume($host, $node, ['kind' => 'finish_seed', 'fingerprint' => $fingerprint]);
    }

    /** Deletes a volume; an absent volume counts as deleted. */
    public function delete(TaskVmHost $host, Node $node, string $name): void
    {
        $this->incus->volume($host, $node, ['kind' => 'remove', 'name' => $name]);
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

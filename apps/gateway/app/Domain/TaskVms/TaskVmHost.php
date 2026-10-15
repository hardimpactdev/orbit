<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\WireGuard\Ipv4Subnet;

/**
 * One compute host Node that runs task VMs, as {@see self::fromConfig()} validated it.
 *
 * `cidr` is the task bridge network, for example `10.251.77.0/24`. The bridge owns the first
 * usable address. Guests get the other usable addresses. `pool` is a ZFS storage pool; when it is
 * missing, `task-vms:prepare-host` creates it from `zfsDataset`. `warmVms` is how many enrolled VMs
 * without a group the host keeps ready for claims; they take slots of `maxVms` like any other VM. `operatorOwned` lists the
 * Incus instances and custom volumes on the host that the operator owns, as `<project>/<name>` or `<project>/*`: the
 * stray report skips them.
 */
final readonly class TaskVmHost
{
    /** The stock Ubuntu cloud image that `incus-host.sh` copies. Only the base image build launches it. */
    public const string SourceImage = 'ubuntu-26.04-vm';

    /** The alias of the base image that every task VM launches. Each build also adds a dated alias `orbit-task-base-<time>`. */
    public const string BaseImage = 'orbit-task-base';

    /** @param  list<string>  $operatorOwned  `<project>/<name>` or `<project>/*` */
    public function __construct(
        public int $nodeId,
        public string $project,
        public string $network,
        public string $cidr,
        public int $maxVms,
        public int $cpus,
        public string $memory,
        public string $disk,
        public string $pool = 'orbit-tasks',
        public ?string $zfsDataset = null,
        public int $warmVms = 0,
        public array $operatorOwned = [],
        public string $imageAlias = self::BaseImage,
    ) {}

    private const array Keys = ['node_id', 'project', 'network', 'cidr', 'max_vms', 'cpus', 'memory', 'disk', 'pool', 'zfs_dataset', 'warm_vms', 'operator_owned', 'image_alias'];

    /**
     * Validates one entry of `task_vms.incus.hosts`, at `$index`, over `task_vms.incus.host_defaults`. The bridge must
     * not overlap the VPN subnet.
     *
     * @param  array<string, mixed>  $defaults
     *
     * @throws TaskVmException
     */
    public static function fromConfig(mixed $entry, int $index, Ipv4Subnet $vpnSubnet, array $defaults): self
    {
        $key = "incus.hosts.{$index}";
        TaskVmConfig::validate(['entry' => $entry], ['entry' => ['array:'.implode(',', self::Keys)]], ['entry' => "{$key} has unknown keys."]);
        $entry = ['image_alias' => self::BaseImage, ...$defaults, ...(is_array($entry) ? $entry : [])];
        $maxVms = is_int($entry['max_vms'] ?? null) ? $entry['max_vms'] : 64;
        $int = static fn (int $minimum, int $maximum): array => ['required', 'integer:strict', "between:{$minimum},{$maximum}"];
        $name = static fn (string $pattern): array => ['required', 'string', "regex:{$pattern}"];
        $size = $name('/\A[1-9][0-9]{0,5}(?:MiB|GiB)\z/D');
        $valid = TaskVmConfig::validate($entry, [
            'node_id' => $int(1, 1_000_000_000),
            'project' => $name('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D'),
            'network' => $name('/\Aorbittask[a-z0-9]{1,6}\z/D'),
            'cidr' => ['required', TaskVmConfig::bridgeRule("{$key}.cidr", $vpnSubnet)],
            'max_vms' => $int(1, 64),
            'cpus' => $int(1, 64),
            'memory' => $size,
            'disk' => $size,
            'image_alias' => $name('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,253}\z/D'),
            'pool' => $name('/\A[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?\z/D'),
            // A dataset below a ZFS pool, never a whole pool: Incus takes over the dataset it creates the pool on.
            'zfs_dataset' => ($entry['zfs_dataset'] ?? null) === null ? [] : ['required', 'string', 'regex:/\A(?=.{3,200}\z)[A-Za-z0-9][A-Za-z0-9_.:-]*(?:\/[A-Za-z0-9][A-Za-z0-9_.:-]*)+\z/D'],
            // Warm VMs take slots too.
            'warm_vms' => ['required', 'integer:strict', 'between:0,'.$maxVms],
            'operator_owned' => ['list'],
            'operator_owned.*' => ['required', 'string', 'regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\/(?:\*|[A-Za-z0-9][A-Za-z0-9._-]{0,253})\z/D'],
        ], [
            'node_id' => "{$key}.node_id must be an integer from 1 to 1000000000.",
            'project' => "{$key}.project is invalid.",
            'network' => "{$key}.network is invalid.",
            'cidr.required' => "{$key}.cidr must be an IPv4 network, for example 10.251.77.0/24.",
            'max_vms' => "{$key}.max_vms must be an integer from 1 to 64.",
            'cpus' => "{$key}.cpus must be an integer from 1 to 64.",
            'memory' => "{$key}.memory is invalid.",
            'disk' => "{$key}.disk is invalid.",
            'pool' => "{$key}.pool is invalid.",
            'image_alias' => "{$key}.image_alias is invalid.",
            'zfs_dataset' => "{$key}.zfs_dataset is invalid.",
            'warm_vms' => "{$key}.warm_vms must be an integer from 0 to the host's max_vms.",
            'operator_owned' => "{$key}.operator_owned must be a JSON list of <project>/<name> or <project>/* entries.",
            'operator_owned.*' => "{$key}.operator_owned must be a JSON list of <project>/<name> or <project>/* entries.",
        ]);

        $zfsDataset = $entry['zfs_dataset'] ?? null;

        return new self(
            nodeId: $valid->integer('node_id'),
            project: $valid->string('project')->value(),
            network: $valid->string('network')->value(),
            cidr: Ipv4Subnet::from($valid->string('cidr')->value())->value(),
            maxVms: $valid->integer('max_vms'),
            cpus: $valid->integer('cpus'),
            memory: $valid->string('memory')->value(),
            disk: $valid->string('disk')->value(),
            pool: $valid->string('pool')->value(),
            zfsDataset: is_string($zfsDataset) ? $zfsDataset : null,
            warmVms: $valid->integer('warm_vms'),
            operatorOwned: array_values(array_unique(TaskVmConfig::strings($entry['operator_owned'] ?? []))),
            imageAlias: $valid->string('image_alias')->value(),
        );
    }

    /** The operator owns the Incus instance or custom volume `$name` in `$project`. */
    public function operatorOwns(string $project, string $name): bool
    {
        return in_array("{$project}/{$name}", $this->operatorOwned, true) || in_array("{$project}/*", $this->operatorOwned, true);
    }

    /** The bridge address: the first usable address of `cidr`. */
    public function bridgeAddress(): string
    {
        return Ipv4Subnet::from($this->cidr)->usableRange()[0];
    }

    /** True for a usable address of `cidr` other than the bridge address. */
    public function isGuestAddress(string $address): bool
    {
        return Ipv4Subnet::from($this->cidr)->containsUsableAddress($address) && $address !== $this->bridgeAddress();
    }
}

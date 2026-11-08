<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\WireGuard\Ipv4Subnet;

/**
 * One compute host Node that runs task VMs, as {@see self::fromConfig()} validated it.
 *
 * `cidr` is the task bridge network, for example `10.251.77.0/24`. The bridge owns the first
 * usable address. Guests get the other usable addresses. `pool` is a ZFS storage pool; when it is
 * missing, the refresh creates it from `zfsDataset` or a loop file of `poolSize`. `operatorOwned` lists the
 * Incus instances and custom volumes on the host that the operator owns, as `<project>/<name>` or `<project>/*`: the
 * stray report skips them.
 */
final readonly class TaskVmHost
{
    /** The stock Ubuntu cloud image that host preparation copies. The base VM of a host is made from it once. */
    public const string SourceImage = 'ubuntu-26.04-vm';

    /** The alias of the base image of the Incus project. */
    public const string BaseImage = 'orbit-task-base';

    /** @param  list<string>  $operatorOwned  `<project>/<name>` or `<project>/*` */
    public function __construct(
        public int $nodeId,
        public string $project,
        public string $network,
        public string $cidr,
        public int $taskPlaces,
        public int $cpus,
        public string $memory,
        public string $disk,
        public string $pool = 'orbit-tasks',
        public ?string $zfsDataset = null,
        public array $operatorOwned = [],
        public string $imageAlias = self::BaseImage,
        public string $poolSize = '640GiB',
        public int $ciPlaces = 0,
    ) {}

    private const array Keys = ['node_id', 'project', 'network', 'cidr', 'task_places', 'cpus', 'memory', 'disk', 'pool', 'pool_size', 'zfs_dataset', 'operator_owned', 'image_alias', 'ci_places'];

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
        $entry = ['image_alias' => self::BaseImage, 'pool_size' => '640GiB', ...$defaults, ...(is_array($entry) ? $entry : [])];
        $int = static fn (int $minimum, int $maximum): array => ['required', 'integer:strict', "between:{$minimum},{$maximum}"];
        $name = static fn (string $pattern): array => ['required', 'string', "regex:{$pattern}"];
        $size = $name('/\A[1-9][0-9]{0,5}(?:MiB|GiB)\z/D');
        $valid = TaskVmConfig::validate($entry, [
            'node_id' => $int(1, 1_000_000_000),
            'project' => $name('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D'),
            'network' => $name('/\Aorbittask[a-z0-9]{1,6}\z/D'),
            'cidr' => ['required', TaskVmConfig::bridgeRule("{$key}.cidr", $vpnSubnet)],
            'task_places' => $int(0, 64),
            'ci_places' => $int(0, 64),
            'cpus' => $int(1, 64),
            'memory' => $size,
            'disk' => $size,
            'pool_size' => $size,
            'image_alias' => $name('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,253}\z/D'),
            'pool' => $name('/\A[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?\z/D'),
            // A dataset below a ZFS pool, never a whole pool: Incus takes over the dataset it creates the pool on.
            'zfs_dataset' => ($entry['zfs_dataset'] ?? null) === null ? [] : ['required', 'string', 'regex:/\A(?=.{3,200}\z)[A-Za-z0-9][A-Za-z0-9_.:-]*(?:\/[A-Za-z0-9][A-Za-z0-9_.:-]*)+\z/D'],
            'operator_owned' => ['list'],
            'operator_owned.*' => ['required', 'string', 'regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\/(?:\*|[A-Za-z0-9][A-Za-z0-9._-]{0,253})\z/D'],
        ], [
            'node_id' => "{$key}.node_id must be an integer from 1 to 1000000000.",
            'project' => "{$key}.project is invalid.",
            'network' => "{$key}.network is invalid.",
            'cidr.required' => "{$key}.cidr must be an IPv4 network, for example 10.251.77.0/24.",
            'task_places' => "{$key}.task_places must be an integer from 0 to 64.",
            'ci_places' => "{$key}.ci_places must be an integer from 0 to 64.",
            'cpus' => "{$key}.cpus must be an integer from 1 to 64.",
            'memory' => "{$key}.memory is invalid.",
            'disk' => "{$key}.disk is invalid.",
            'pool_size' => "{$key}.pool_size is invalid.",
            'pool' => "{$key}.pool is invalid.",
            'image_alias' => "{$key}.image_alias is invalid.",
            'zfs_dataset' => "{$key}.zfs_dataset is invalid.",
            'operator_owned' => "{$key}.operator_owned must be a JSON list of <project>/<name> or <project>/* entries.",
            'operator_owned.*' => "{$key}.operator_owned must be a JSON list of <project>/<name> or <project>/* entries.",
        ]);

        $zfsDataset = $entry['zfs_dataset'] ?? null;

        return new self(
            nodeId: $valid->integer('node_id'),
            project: $valid->string('project')->value(),
            network: $valid->string('network')->value(),
            cidr: Ipv4Subnet::from($valid->string('cidr')->value())->value(),
            taskPlaces: $valid->integer('task_places'),
            cpus: $valid->integer('cpus'),
            memory: $valid->string('memory')->value(),
            disk: $valid->string('disk')->value(),
            pool: $valid->string('pool')->value(),
            zfsDataset: is_string($zfsDataset) ? $zfsDataset : null,
            operatorOwned: array_values(array_unique(TaskVmConfig::strings($entry['operator_owned'] ?? []))),
            imageAlias: $valid->string('image_alias')->value(),
            poolSize: $valid->string('pool_size')->value(),
            ciPlaces: $valid->integer('ci_places'),
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

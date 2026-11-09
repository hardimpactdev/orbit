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
 * stray report skips them. `containers` is the host's task container side, or null on a host that runs only task VMs.
 */
final readonly class TaskVmHost
{
    /** The stock Ubuntu cloud image that host preparation copies. The base VM of a host is made from it once. */
    public const string SourceImage = 'ubuntu-26.04-vm';

    /** The alias of the base image of the Incus project. */
    public const string BaseImage = 'orbit-task-base';

    /** An Incus project name. */
    public const string ProjectPattern = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D';

    /** A task bridge name: `orbittask` and 1 to 6 lowercase letters or digits. */
    public const string NetworkPattern = '/\Aorbittask[a-z0-9]{1,6}\z/D';

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
        public ?TaskVmContainers $containers = null,
    ) {}

    private const array Keys = ['node_id', 'project', 'network', 'cidr', 'task_places', 'cpus', 'memory', 'disk', 'pool', 'pool_size', 'zfs_dataset', 'operator_owned', 'image_alias', 'ci_places', 'containers'];

    /**
     * Validates one entry of `task_vms.incus.hosts`, at `$index`, over `task_vms.incus.host_defaults`. The bridge must
     * not overlap the VPN subnet. A `containers` member takes its defaults from `host_defaults.containers`, and its
     * project, bridge name and bridge network must differ from the host's VM ones.
     *
     * @param  array<string, mixed>  $defaults
     *
     * @throws TaskVmException
     */
    public static function fromConfig(mixed $entry, int $index, Ipv4Subnet $vpnSubnet, array $defaults): self
    {
        $key = "incus.hosts.{$index}";
        TaskVmConfig::validate(['entry' => $entry], ['entry' => ['array:'.implode(',', self::Keys)]], ['entry' => "{$key} has unknown keys."]);
        $containerDefaults = is_array($defaults['containers'] ?? null) ? $defaults['containers'] : [];
        unset($defaults['containers']);
        $entry = ['image_alias' => self::BaseImage, 'pool_size' => '640GiB', ...$defaults, ...(is_array($entry) ? $entry : [])];
        $int = static fn (int $minimum, int $maximum): array => ['required', 'integer:strict', "between:{$minimum},{$maximum}"];
        $name = static fn (string $pattern): array => ['required', 'string', "regex:{$pattern}"];
        $size = $name('/\A[1-9][0-9]{0,5}(?:MiB|GiB)\z/D');
        $valid = TaskVmConfig::validate($entry, [
            'node_id' => $int(1, 1_000_000_000),
            'project' => $name(self::ProjectPattern),
            'network' => $name(self::NetworkPattern),
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
        $cidr = Ipv4Subnet::from($valid->string('cidr')->value())->value();
        $containers = array_key_exists('containers', $entry) ? TaskVmContainers::fromConfig($entry['containers'], "{$key}.containers", $vpnSubnet, $containerDefaults) : null;
        match (true) {
            $containers === null => null,
            $containers->project === $valid->string('project')->value() => throw TaskVmConfig::invalid("{$key}.containers.project must differ from {$key}.project."),
            $containers->network === $valid->string('network')->value() => throw TaskVmConfig::invalid("{$key}.containers.network must differ from {$key}.network."),
            self::overlap($containers->cidr, $cidr) => throw TaskVmConfig::invalid("{$key}.containers.cidr must not overlap {$key}.cidr."),
            default => null,
        };

        return new self(
            nodeId: $valid->integer('node_id'),
            project: $valid->string('project')->value(),
            network: $valid->string('network')->value(),
            cidr: $cidr,
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
            containers: $containers,
        );
    }

    /** True when two bridge networks share an address. */
    public static function overlap(string $cidr, string $other): bool
    {
        [$a, $b] = [Ipv4Subnet::from($cidr), Ipv4Subnet::from($other)];

        return $a->contains($b->networkAddress()) || $b->contains($a->networkAddress());
    }

    /**
     * The bridge networks of the host: the VM bridge, and the container bridge when it has one.
     *
     * @return list<string>
     */
    public function bridges(): array
    {
        return $this->containers === null ? [$this->cidr] : [$this->cidr, $this->containers->cidr];
    }

    /** The places of `$runtime` in the CI or the task pool. A host without `containers` has no container place. */
    public function places(TaskVmRuntime $runtime, bool $ci): int
    {
        return match ($runtime) {
            TaskVmRuntime::Vm => $ci ? $this->ciPlaces : $this->taskPlaces,
            TaskVmRuntime::Container => ($ci ? $this->containers?->ciPlaces : $this->containers?->taskPlaces) ?? 0,
        };
    }

    /** The Incus project of the instances and the base of `$runtime`, or null on a host without that side. */
    public function projectOf(TaskVmRuntime $runtime): ?string
    {
        return $runtime === TaskVmRuntime::Vm ? $this->project : $this->containers?->project;
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

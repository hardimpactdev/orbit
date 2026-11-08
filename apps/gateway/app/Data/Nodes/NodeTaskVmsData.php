<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Domain\TaskVms\TaskVmHost;
use App\Domain\WireGuard\Ipv4Subnet;
use Spatie\LaravelData\Data;

final class NodeTaskVmsData extends Data
{
    /**
     * @param  list<string>  $operator_owned
     */
    public function __construct(
        public string $cidr,
        public int $task_places,
        public string $project = 'orbit-tasks',
        public string $network = 'orbittask0',
        public string $pool = 'orbit-tasks',
        public string $image_alias = 'orbit-task-base',
        public ?string $zfs_dataset = null,
        public int $cpus = 2,
        public string $memory = '4GiB',
        public string $disk = '20GiB',
        public array $operator_owned = [],
        public string $pool_size = '640GiB',
        public int $ci_places = 0,
    ) {}

    /** @param array<string, mixed> $value */
    public static function validated(array $value, Ipv4Subnet $vpnSubnet): self
    {
        $defaults = config('task_vms.incus.host_defaults');
        $host = TaskVmHost::fromConfig([...$value, 'node_id' => 1], 0, $vpnSubnet, is_array($defaults) ? $defaults : []);

        return new self(
            cidr: $host->cidr, task_places: $host->taskPlaces, project: $host->project,
            network: $host->network, pool: $host->pool, image_alias: $host->imageAlias,
            zfs_dataset: $host->zfsDataset, cpus: $host->cpus, memory: $host->memory,
            disk: $host->disk, operator_owned: $host->operatorOwned, pool_size: $host->poolSize, ci_places: $host->ciPlaces,
        );
    }
}

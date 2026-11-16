<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Domain\TaskVms\TaskVmContainers;
use Spatie\LaravelData\Data;

/** The task container side of a host's `settings.task_vms` (docs/reference/node-settings.md#task-container-fields). */
final class NodeTaskVmContainersData extends Data
{
    public function __construct(
        public string $cidr,
        public int $task_places,
        public string $project = 'orbit-task-containers',
        public string $network = 'orbittaskc0',
    ) {}

    public static function fromContainers(TaskVmContainers $containers): self
    {
        return new self(
            cidr: $containers->cidr, task_places: $containers->taskPlaces,
            project: $containers->project, network: $containers->network,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeStorageAppsData;
use App\Data\Nodes\NodeTaskVmsData;

final readonly class NodeSettingsPatch
{
    public function __construct(
        public bool $hasApps,
        public ?NodeStorageAppsData $apps,
        public bool $hasTaskVms = false,
        public ?NodeTaskVmsData $taskVms = null,
        public bool $hasNestedFleet = false,
        public bool $nestedFleet = false,
    ) {}

    public function merge(?NodeSettingsData $stored): NodeSettingsData
    {
        $current = $stored ?? new NodeSettingsData;

        return new NodeSettingsData(
            apps: $this->hasApps ? $this->apps : $current->apps,
            task_vms: $this->hasTaskVms ? $this->taskVms : $current->task_vms,
            nested_fleet: $this->hasNestedFleet ? $this->nestedFleet : $current->nested_fleet,
        );
    }

    public function isEmpty(): bool
    {
        return ! $this->hasApps && ! $this->hasTaskVms && ! $this->hasNestedFleet;
    }
}

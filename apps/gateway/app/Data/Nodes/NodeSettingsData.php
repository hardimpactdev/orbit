<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class NodeSettingsData extends Data
{
    public function __construct(
        public ?NodeStorageAppsData $apps = null,
        public ?NodeTaskVmsData $task_vms = null,
        public bool $nested_fleet = false,
        // Absent on a Node without CI places, so its responses leave the member out.
        public NodeCiData|Optional $ci = new Optional,
    ) {}

    public function ci(): ?NodeCiData
    {
        return $this->ci instanceof NodeCiData ? $this->ci : null;
    }

    public function appsPath(): ?string
    {
        $path = $this->apps?->path;

        return is_string($path) ? $path : null;
    }

    public function isEmpty(): bool
    {
        return $this->appsPath() === null && $this->task_vms === null && ! $this->nested_fleet && $this->ci() === null;
    }
}

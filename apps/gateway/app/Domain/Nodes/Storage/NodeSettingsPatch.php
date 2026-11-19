<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeStorageAppsData;
use Spatie\LaravelData\Optional;

final readonly class NodeSettingsPatch
{
    public function __construct(
        public bool $hasApps,
        public ?NodeStorageAppsData $apps,
        public bool $hasNestedFleet = false,
        public bool $nestedFleet = false,
        public bool $hasCi = false,
        public ?NodeCiPatch $ci = null,
        public bool $hasInstanceHost = false,
        public bool $instanceHost = false,
    ) {}

    public function merge(?NodeSettingsData $stored): NodeSettingsData
    {
        $current = $stored ?? new NodeSettingsData;

        return new NodeSettingsData(
            apps: $this->hasApps ? $this->apps : $current->apps,
            nested_fleet: $this->hasNestedFleet ? $this->nestedFleet : $current->nested_fleet,
            instance_host: $this->hasInstanceHost ? $this->instanceHost : $current->instance_host,
            ci: $this->hasCi ? ($this->ci?->over($current->ci()) ?? new Optional) : $current->ci,
        );
    }

    public function isEmpty(): bool
    {
        return ! $this->hasApps && ! $this->hasNestedFleet && ! $this->hasInstanceHost && ! $this->hasCi;
    }
}

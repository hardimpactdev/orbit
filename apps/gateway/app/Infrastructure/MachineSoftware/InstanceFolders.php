<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/**
 * The recipe's pins of the two shared folders of an Instance container host (docs/reference/development-containers.md#shared-folders):
 * the runtime folder, which every container user runs, and the agent folder, which only `agent` runs. Keyed by part name. The
 * runtime folder also holds the static PHP builds, keyed by PHP version.
 */
final readonly class InstanceFolders
{
    /**
     * @param  array<string, FolderPin>  $runtime
     * @param  array<string, FolderPin>  $agent
     * @param  array<string, StaticPhpBuild>  $php
     */
    public function __construct(
        public array $runtime,
        public array $agent,
        public array $php = [],
    ) {}

    public function php(string $version): StaticPhpBuild
    {
        return $this->php[$version] ?? throw new \InvalidArgumentException("The recipe has no static PHP build for version [{$version}].");
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/**
 * The recipe's pins of the two shared folders of an Instance container host (docs/reference/development-containers.md#shared-folders):
 * the runtime folder, which every container user runs, and the agent folder, which only `agent` runs. Keyed by part name.
 */
final readonly class InstanceFolders
{
    /**
     * @param  array<string, FolderPin>  $runtime
     * @param  array<string, FolderPin>  $agent
     */
    public function __construct(
        public array $runtime,
        public array $agent,
    ) {}
}

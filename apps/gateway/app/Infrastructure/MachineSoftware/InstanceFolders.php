<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/**
 * The recipe's pins of the two shared folders of an Instance container host (docs/reference/development-containers.md#shared-folders):
 * the runtime folder, which every container user runs, and the agent folder, which only `agent` runs. Keyed by part name. The
 * runtime folder also holds the static PHP builds, keyed by PHP version, and the home of its Vite+ part, if it has one.
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
        public ?VitePlusHome $vitePlus = null,
    ) {}

    /** The runtime folder's one part with Chromium's trust module; the loader requires exactly one (docs/reference/development-containers.md#trust). */
    public function trustModule(): FolderPin
    {
        foreach ($this->runtime as $part) {
            if ($part->trustModule !== null) {
                return $part;
            }
        }

        throw new \LogicException('The runtime folder holds no trust module.');
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/**
 * One part of a shared Instance folder (docs/reference/development-containers.md#shared-folders): a pinned artifact and
 * the programs it gives the folder's `bin`, each a path inside the unpacked artifact.
 */
final readonly class FolderPin
{
    /** @param  array<string, string>  $bin  Program name => path inside the unpacked artifact. */
    public function __construct(
        public string $version,
        public ArtifactPin $artifact,
        public array $bin,
    ) {}
}

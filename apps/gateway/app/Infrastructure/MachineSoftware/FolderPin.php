<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/**
 * One part of a shared Instance folder (docs/reference/development-containers.md#shared-folders): a pinned artifact and
 * the programs it gives the folder's `bin`, each a path inside the unpacked artifact. The one part with a `trustModule` gives the
 * folder the PKCS#11 trust module that Chromium loads (docs/reference/development-containers.md#trust).
 */
final readonly class FolderPin
{
    /**
     * @param  array<string, string>  $bin  Program name => path inside the unpacked artifact.
     * @param  array{path: string, library: string}|null  $trustModule  The module's path inside the unpacked package, and the
     *     container's package that the module needs at exactly this part's version.
     */
    public function __construct(
        public string $version,
        public ArtifactPin $artifact,
        public array $bin,
        public ?array $trustModule = null,
    ) {}
}

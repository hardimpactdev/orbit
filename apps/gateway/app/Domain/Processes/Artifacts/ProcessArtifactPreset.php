<?php

declare(strict_types=1);

namespace App\Domain\Processes\Artifacts;

/**
 * A release artifact that a role declares for its Process, applied by `process:artifact:set --preset`
 * ([Extensions](/reference/extensions#plug-in-points)). Read while its extension is enabled.
 */
interface ProcessArtifactPreset
{
    public function name(): string;

    /** @return array{repository:string,asset:string,binary:string,checksums:?string,version_args:?list<string>} */
    public function fields(): array;
}

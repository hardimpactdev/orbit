<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Project;

/**
 * Renders the `@orbit/ci-caches` setup step: the main caches of the newest successful CI run on the default branch,
 * and `ci-caches.sh`, which imports each archive with `bin/tia-cache import-ci` in the checkout. The archives travel
 * in heredocs after the script, so a step log that shows the command's start shows the script.
 */
final readonly class CiCacheSetup
{
    public function __construct(private CiCacheSource $source, private CommandDeadline $deadline) {}

    public function command(Project $project, float $timeout): string
    {
        return $this->deadline->withinForwardWork($timeout, function () use ($project): string {
            $script = file_get_contents(resource_path('instances/ci-caches.sh'));
            if (! is_string($script) || $script === '') {
                throw new ResourceOperationException('instance.ci_caches_unavailable', 'The CI cache installer is unavailable.', 503);
            }
            $caches = $this->source->fetch($project);
            $command = $script."commit={$caches->commit}\nwork=\$(mktemp -d)\ntrap 'rm -rf -- \"\$work\"' EXIT\n";
            foreach ($caches->archives as $index => $archive) {
                $command .= "base64 -d > \"\$work/{$index}.zip\" <<'ORBIT_CI_CACHE'\n".chunk_split(base64_encode($archive), 76, "\n")."ORBIT_CI_CACHE\n";
            }

            return $command."import_ci_caches\n";
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Project;

/** Renders the importer for SHA-bound Git cache objects already sent from the Gateway mirror. */
final readonly class CiCacheSetup
{
    public function __construct(private CommandDeadline $deadline) {}

    public function command(Project $project, float $timeout): string
    {
        return $this->deadline->withinForwardWork($timeout, function (): string {
            $script = file_get_contents(resource_path('instances/ci-caches.sh'));
            if (! is_string($script) || $script === '') {
                throw new ResourceOperationException('instance.ci_caches_unavailable', 'The CI cache installer is unavailable.', 503);
            }

            return $script;
        });
    }
}

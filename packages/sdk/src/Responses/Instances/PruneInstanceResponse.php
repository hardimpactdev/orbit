<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Instances;

final readonly class PruneInstanceResponse
{
    /**
     * @param  list<array<string, mixed>>  $staleWorkspaces
     * @param  list<array<string, string>>  $warnings
     */
    public function __construct(
        public string $instance,
        public array $staleWorkspaces,
        public array $warnings,
        public bool $dryRun,
    ) {}
}

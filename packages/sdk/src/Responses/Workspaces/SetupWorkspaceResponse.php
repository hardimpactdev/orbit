<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Workspaces;

final readonly class SetupWorkspaceResponse
{
    /**
     * The first ten constructor parameters preserve the original public SDK
     * response contract. Canonical workspace fields are additive.
     *
     * @param  list<array<string, mixed>>  $warnings
     * @param  array<string, mixed>  $setupSteps
     * @param  array<string, mixed>  $processes
     * @param  array<string, mixed>  $httpProbe
     */
    public function __construct(
        public string $app,
        public string $instance,
        public string $workspace,
        public string $node,
        public string $url,
        public string $action,
        public array $warnings,
        public array $setupSteps,
        public array $processes,
        public array $httpProbe,
        public string $name = '',
        public ?string $path = null,
        public ?string $phpVersion = null,
        public bool $phpInherited = false,
        public bool $adopted = false,
        public string $lifecycleStatus = 'setup-pending',
    ) {}
}

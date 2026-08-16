<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Workspaces;

final readonly class SetupWorkspaceResponse
{
    public function __construct(
        public string $name,
        public string $app,
        public string $instance,
        public ?string $node,
        public ?string $path,
        public ?string $url,
        public ?string $phpVersion,
        public bool $phpInherited,
        public bool $adopted,
        public string $lifecycleStatus,
        public string $action,
        /** @var array<string, mixed> */
        public array $httpProbe,
        /** @var list<array<string, mixed>> */
        public array $warnings,
    ) {}
}

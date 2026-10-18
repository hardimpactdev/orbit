<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

final readonly class ToolPin
{
    /** @param array<string, ArtifactPin> $artifacts */
    public function __construct(
        public string $source,
        public string $version,
        public ?string $package = null,
        public array $artifacts = [],
        public ?string $lockSha256 = null,
    ) {}
}

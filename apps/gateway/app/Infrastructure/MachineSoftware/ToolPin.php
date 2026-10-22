<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

final readonly class ToolPin
{
    public function forArchitecture(string $architecture): self
    {
        return new self($this->source, $this->artifacts[$architecture]->version ?? $this->version, $this->package, $this->artifacts, $this->lockSha256);
    }

    /** @param array<string, ArtifactPin> $artifacts */
    public function __construct(
        public string $source,
        public string $version,
        public ?string $package = null,
        public array $artifacts = [],
        public ?string $lockSha256 = null,
    ) {}
}

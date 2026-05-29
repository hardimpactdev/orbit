<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EPaths
{
    private function __construct(
        private string $repoRoot,
    ) {}

    public static function fromRepositoryRoot(string $repoRoot): self
    {
        return new self(rtrim($repoRoot, '/'));
    }

    public function repoRoot(): string
    {
        return $this->repoRoot;
    }

    public function cliLauncher(): string
    {
        return "{$this->repoRoot}/apps/cli/orbit";
    }

    public function gatewayArtisan(): string
    {
        return "{$this->repoRoot}/apps/gateway/artisan";
    }

    public function e2eAppRoot(): string
    {
        return "{$this->repoRoot}/apps/e2e";
    }
}

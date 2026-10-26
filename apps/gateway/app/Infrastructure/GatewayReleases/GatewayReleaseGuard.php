<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseLayout;

/** A deploy moves forward in Git unless the operator explicitly overrides it. */
final readonly class GatewayReleaseGuard
{
    public function __construct(private GatewayReleaseLayout $layout, private GatewayReleaseCommands $commands) {}

    /** @return array{source: string|null, source_sha: string|null} */
    public function source(): array
    {
        return $this->commands->forward($this->layout, null, false);
    }

    public function assertForward(string $sha, bool $force): void
    {
        $this->commands->forward($this->layout, $sha, $force);
    }
}

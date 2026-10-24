<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseLayout;

/** Requests an atomic switch to one prepared release. */
final readonly class GatewayReleaseSwitcher
{
    public function __construct(private GatewayReleaseLayout $layout, private GatewayReleaseCommands $commands) {}

    /** @return string|null the release id that was current before this call */
    public function switchTo(string $id): ?string
    {
        return $this->commands->switch($this->layout, $id);
    }
}

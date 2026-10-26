<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;

/** Runs each handoff phase with the prepared release's control-plane code through the native boundary. */
final readonly class ArtisanGatewayReleaseRuntime implements GatewayReleaseRuntime
{
    public function __construct(
        private GatewayReleaseLayout $layout,
        private GatewayReleaseCommands $commands,
        private string $stepLock,
    ) {}

    public function handoff(string $id): array
    {
        return $this->commands->handoffPhase($this->layout, $id, 'serve', $this->stepLock);
    }

    public function schedule(string $id): array
    {
        $result = $this->commands->handoffPhase($this->layout, $id, 'schedule', $this->stepLock);
        $result['cleanup_paused'] = ($result['cleanup_paused'] ?? true) === true;

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Http\Controllers\Concerns;

use HardImpact\Orbit\Core\Models\Environment;

trait ProvidesRemoteApiUrl
{
    /**
     * Get the remote API URL for an environment.
     * For remote environments with a known TLD, returns https://orbit.{tld}/api
     * This allows the frontend to bypass the single-threaded NativePHP server.
     * For local environments, returns null so frontend uses local API routes.
     */
    protected function getRemoteApiUrl(Environment $environment): ?string
    {
        // For remote environments with a known TLD
        if (! $environment->is_local && $environment->tld) {
            return "https://orbit.{$environment->tld}/api";
        }

        // For local environments, use local API routes (CLI is called via ORBIT_CLI_PATH)
        return null;
    }
}

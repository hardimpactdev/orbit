<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use RuntimeException;

/**
 * Resolves ORBIT_HOST_PATH_PREFIX, the host filesystem view mounted into the
 * gateway container. Bare-metal and development installs leave it unset.
 */
final class GatewayHostPathPrefix
{
    /**
     * The validated prefix, or null when this gateway has no host view.
     */
    public static function resolve(): ?string
    {
        $hostPathPrefix = getenv('ORBIT_HOST_PATH_PREFIX');

        if (! is_string($hostPathPrefix) || trim($hostPathPrefix) === '') {
            return null;
        }

        $hostPathPrefix = rtrim(trim($hostPathPrefix), characters: '/');

        if (! self::isSafe($hostPathPrefix)) {
            throw new RuntimeException('Gateway host path prefix is invalid.');
        }

        return $hostPathPrefix;
    }

    private static function isSafe(string $hostPathPrefix): bool
    {
        if ($hostPathPrefix === '' || ! str_starts_with($hostPathPrefix, '/')) {
            return false;
        }

        if (str_contains($hostPathPrefix, "\0")) {
            return false;
        }

        return ! array_any(
            explode('/', $hostPathPrefix),
            static fn (string $segment): bool => $segment === '.' || $segment === '..',
        );
    }
}

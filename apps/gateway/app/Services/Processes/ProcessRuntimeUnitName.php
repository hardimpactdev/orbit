<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\Instance;
use App\Models\Process;
use App\Models\Workspace;
use InvalidArgumentException;

/**
 * Canonical runtime-unit identity shared by systemd, launchd, and Docker
 * render/probe/restore paths. Names must stay backend-safe for the strictest
 * consumer (launchd unit identity: max 64 chars, [a-z0-9_.-]).
 */
final class ProcessRuntimeUnitName
{
    /**
     * Strictest backend limit (launchd unit identity asserted by
     * LaunchdPlistRenderer). Keep one shared bound so render/probe/restore
     * never diverge.
     */
    public const int MaxLength = 64;

    public static function for(App $app, Process $process, ?Workspace $workspace = null): string
    {
        $process->loadMissing('instance');
        $instance = $process->instance;

        if (! $instance instanceof Instance) {
            throw new InvalidArgumentException(
                "Process '{$process->name}' has no concrete instance for runtime-unit identity.",
            );
        }

        if ($workspace instanceof Workspace && $workspace->instance_id !== $instance->id) {
            throw new InvalidArgumentException(
                "Process '{$process->name}' cannot render for a workspace on another instance.",
            );
        }

        $scope = $workspace->name ?? 'main';
        $full = "orbit_{$app->name}_{$instance->name}_{$scope}_{$process->name}";

        return self::bounded($full);
    }

    /**
     * Deterministically bound a full runtime-unit identity to MaxLength while
     * remaining a valid launchd/systemd/docker identity slug.
     */
    public static function bounded(string $full): string
    {
        if (self::isValid($full)) {
            return $full;
        }

        $hash = substr(hash('sha256', $full), offset: 0, length: 12);
        $suffix = "_{$hash}";
        $budget = self::MaxLength - strlen($suffix);

        if ($budget < 1) {
            throw new InvalidArgumentException('Runtime-unit identity hash bound is misconfigured.');
        }

        $prefix = substr($full, offset: 0, length: $budget);
        $prefix = rtrim($prefix, characters: '_.-');

        if ($prefix === '' || ! preg_match('/^[a-z0-9]/', $prefix)) {
            $prefix = 'orbit';
        }

        $candidate = $prefix.$suffix;

        if (! self::isValid($candidate)) {
            // Always-valid fallback: orbit_ + 12 hex (fits MaxLength).
            $candidate = 'orbit_'.$hash;
        }

        if (! self::isValid($candidate)) {
            throw new InvalidArgumentException("Unable to bound runtime-unit identity for '{$full}'.");
        }

        return $candidate;
    }

    public static function isValid(string $name): bool
    {
        if (strlen($name) > self::MaxLength) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9_.-]{0,62}[a-z0-9])?$/', $name);
    }
}

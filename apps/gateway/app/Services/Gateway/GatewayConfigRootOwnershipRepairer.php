<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Keeps a bind-mounted gateway config root owned by the host Orbit
 * installation user's numeric uid:gid.
 *
 * Mode hardening alone is not enough. The config root is shared: the gateway
 * container writes it, and the host Orbit CLI reads `config.json` from it over
 * the force_remote_host lane. Hardening the tree to 0700 while it is owned by
 * any other account locks the host CLI out entirely, which makes every
 * force_remote_host operation fail token verification. Ownership must be
 * repaired wherever modes are repaired, not only at container startup.
 *
 * Ownership comes from the host home view under ORBIT_HOST_PATH_PREFIX. The
 * image-internal `orbit` account is never used for a bind-mounted tree: its
 * uid does not match the host install user.
 */
class GatewayConfigRootOwnershipRepairer
{
    /**
     * Repair ownership of the config root tree.
     *
     * `$configRoot` is the path this process writes through. `$hostConfigRoot`
     * is the same root as the host addresses it, which is what host ownership
     * is resolved from; they differ only when the container path is remapped.
     */
    public function repair(string $configRoot, ?string $hostConfigRoot = null): void
    {
        $owner = $this->resolveOwner($hostConfigRoot ?? $configRoot);

        if ($owner === null || ! File::isDirectory($configRoot)) {
            return;
        }

        $result = Process::run(['chown', '-R', $owner, $configRoot]);

        if (! $result->successful()) {
            $reason = trim($result->errorOutput().' '.$result->output());

            throw new RuntimeException(
                "Failed to repair gateway config root ownership at {$configRoot}: "
                .($reason !== '' ? $reason : 'unknown error'),
            );
        }
    }

    /**
     * Resolve the canonical `uid:gid` for the config root, or null when this
     * root is image-local and may keep the image account.
     */
    public function resolveOwner(string $configRoot): ?string
    {
        $hostPathPrefix = GatewayHostPathPrefix::resolve();

        if ($hostPathPrefix === null) {
            return null;
        }

        $hostIdentityPath = $hostPathPrefix.$this->hostHomeDirectory($configRoot);

        if (! File::isDirectory($hostIdentityPath)) {
            throw new RuntimeException(
                "Unable to resolve host ownership for gateway config root at {$hostIdentityPath}.",
            );
        }

        $uid = fileowner($hostIdentityPath);
        $gid = filegroup($hostIdentityPath);

        if (! is_int($uid) || ! is_int($gid)) {
            throw new RuntimeException(
                "Unable to resolve host ownership for gateway config root at {$hostIdentityPath}.",
            );
        }

        return "{$uid}:{$gid}";
    }

    /**
     * The host home directory that owns the config root. Canonical roots live
     * at `<home>/.config/orbit`; anything else falls back to the parent.
     */
    private function hostHomeDirectory(string $configRoot): string
    {
        $configRoot = rtrim($configRoot, '/');

        if (! str_starts_with($configRoot, '/')) {
            throw new RuntimeException('Gateway config root must be an absolute path.');
        }

        if (str_ends_with($configRoot, '/.config/orbit')) {
            return substr($configRoot, 0, -strlen('/.config/orbit'));
        }

        return dirname($configRoot);
    }
}

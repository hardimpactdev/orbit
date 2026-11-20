<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\InstanceRuntime;

/**
 * A task copy's own key for Orbit (docs/reference/development-containers.md#the-copy-key). The model key is one placeholder for every
 * container, so it cannot name a copy; this key does. The Gateway keeps only its SHA-256 hash on the copy's runtime, and a new key
 * replaces the hash. The copy's destroy deletes the runtime, and the key with it.
 */
final readonly class TaskKey
{
    /** The path in the container under the forwarder's origin; the forwarder sends each of its routes to `/api/v1/copy/...`. */
    public const string Path = '/orbit/v1/copy';

    /** A new key for the runtime's copy. The value goes only into the copy's model environment, through the vault. */
    public static function mint(InstanceRuntime $runtime): string
    {
        $key = bin2hex(random_bytes(32));
        $runtime->forceFill(['agent_key_hash' => hash('sha256', $key)])->save();

        return $key;
    }

    /** The runtime whose copy holds this key, or null. */
    public static function runtime(?string $key): ?InstanceRuntime
    {
        if ($key === null || preg_match('/\A[0-9a-f]{64}\z/', $key) !== 1) {
            return null;
        }

        return InstanceRuntime::query()->where('agent_key_hash', hash('sha256', $key))->first();
    }
}

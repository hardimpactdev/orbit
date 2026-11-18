<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Managed;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;

/**
 * Authenticates a managed target request ([Target routes](/reference/conn#target-routes)): the WireGuard peer is the
 * serving Node, the bearer is that environment's service credential, and the generation is the current one.
 */
final class ConnManagedTargets
{
    public static function enabled(): bool
    {
        return Config::boolean('orbit.conn.managed_targets');
    }

    public static function requireEnabled(): void
    {
        if (! self::enabled()) {
            throw new ResourceOperationException('conn.managed_disabled', 'Managed Conn targets are off.', 404);
        }
    }

    public function authenticate(Request $request): ConnManagedTarget
    {
        self::requireEnabled();
        $peer = $this->peer($request);
        $environmentId = $request->input('environment_id');
        $generation = $request->input('generation');
        $target = is_string($environmentId) ? ConnManagedTarget::query()->where('environment_id', $environmentId)->first() : null;
        if (! $target instanceof ConnManagedTarget) {
            throw new ResourceOperationException('conn.managed_target_unknown', 'The environment has no managed target.', 404);
        }

        $bearer = (string) $request->bearerToken();
        if ($peer->id !== $target->node_id
            || ! str_starts_with($bearer, ConnManagedSecrets::ServicePrefix)
            || ! hash_equals($target->credential_digest, ConnManagedSecrets::digest($bearer))) {
            throw new ResourceOperationException('conn.managed_target_identity', 'The managed target identity does not match.', 403);
        }

        if (! is_int($generation) || $generation !== $target->generation) {
            throw new ResourceOperationException('conn.managed_generation_mismatch', 'The generation is not the current one.', 409);
        }

        return $target;
    }

    private function peer(Request $request): Node
    {
        $peer = $request->user();
        if (! $peer instanceof Node) {
            throw new ResourceOperationException('peer.identity_unknown', 'Active WireGuard peer identity required.', 403);
        }

        return $peer;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\WebSocket\WebSocketServiceUnitRenderer;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * The systemd units that a Node's role assignments own: the Swamp worker template for `swamp`, `orbit-websocket.service` for
 * `websocket` (docs/reference/node-provisioning.md#role-units-at-a-converge). Every converge intent carries them as `role_units`, base
 * included, so the base digest that a nested-fleet refresh compares moves when their text moves. The `agent` phase writes them and
 * reloads systemd; it never starts or restarts one. A running service takes the new text at its next start: a Swamp worker at its
 * renewal restart, Reverb at a reboot, a new Reverb release or a changed `.env`. The Swamp serve unit is not among them: its repository is the one the Node's serve really
 * reads, which the role does not render for a Gateway that is its own Swamp Node.
 */
final readonly class NodeRoleUnits
{
    /** @return list<array{name:string,content:string}> */
    public static function for(Node $node): array
    {
        $roles = $node->roles()->get()->map(static fn (NodeRole $assignment): RoleName => $assignment->role)->all();
        $units = [];
        if (in_array(RoleName::Swamp, $roles, true)) {
            $units[] = ['name' => 'orbit-swamp-worker@.service', 'content' => SwampRoleUnits::for($node->user, $node->id)['worker']];
        }
        if (in_array(RoleName::WebSocket, $roles, true)) {
            $install = config('orbit.websocket.install_path');
            $port = config('orbit.websocket.port');
            if (! is_string($install) || ! is_int($port)) {
                throw new ResourceOperationException('websocket.runtime_invalid', 'The Reverb install path or port is invalid.', 422);
            }
            $units[] = ['name' => 'orbit-websocket.service', 'content' => app(WebSocketServiceUnitRenderer::class)->render($install, $node->user, $node->user, $port)];
        }

        return $units;
    }
}

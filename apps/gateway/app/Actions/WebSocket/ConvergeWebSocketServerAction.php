<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Actions\Nodes\AddNodeRoleAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\NodeRole;

/**
 * Converges the active `websocket` role again, so its Reverb server serves the apps as they are now.
 * Without an active role there is no server: the role's next converge renders every app.
 */
final readonly class ConvergeWebSocketServerAction
{
    public function __construct(private AddNodeRoleAction $roles) {}

    public function execute(): void
    {
        $assignment = NodeRole::query()
            ->where('role', RoleName::WebSocket->value)
            ->where('status', LifecycleStatus::Active->value)
            ->with('node')
            ->first();

        if ($assignment instanceof NodeRole) {
            $this->roles->execute($assignment->node, RoleName::WebSocket, convergeExisting: true);
        }
    }
}

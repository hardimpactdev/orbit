<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Models\Instance;

interface WebSocketRouteInspector
{
    /** Inspect the current release's Laravel routes. Refuse if inspection cannot complete. */
    public function hasCollisions(Instance $instance): bool;
}

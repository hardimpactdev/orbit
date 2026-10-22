<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Models\Route;

interface WebSocketRoutePublisher
{
    /** Rebuild the existing app sites after the attachment transaction commits. */
    public function publish(Route $route): void;
}

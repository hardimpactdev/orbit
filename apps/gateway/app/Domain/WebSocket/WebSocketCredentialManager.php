<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Models\Node;

interface WebSocketCredentialManager
{
    /**
     * Returns what the node's Reverb server boots with. Generates the server's Laravel `APP_KEY` and the
     * Gateway's own app on first use, and keeps both stable across every later converge.
     */
    public function ensure(Node $node): WebSocketServerCredentials;

    /**
     * Reads the Gateway's own app for the currently active websocket role
     * assignment without touching the node. Null when no role is active.
     */
    public function current(): ?WebSocketCredentials;

    /** Deletes the server's Laravel `APP_KEY`. The apps outlive the role. */
    public function purge(Node $node, bool $runtimeStopped = false): void;
}

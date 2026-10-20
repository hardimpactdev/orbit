<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Infrastructure\WebSocket\NativeWebSocketRuntimeLifecycle;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeWebSocketRuntimeLifecycle::class)]
interface WebSocketRuntimeLifecycle
{
    public function converge(Node $node, NodeRole $assignment, WebSocketCredentials $credentials): void;

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void;

    public function health(Node $node): bool;
}

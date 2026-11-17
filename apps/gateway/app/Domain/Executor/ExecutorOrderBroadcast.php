<?php

declare(strict_types=1);

namespace App\Domain\Executor;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * The server event `executor-order` on a Node's `presence-node.{id}` (ADR 0266 "The path" step 1). Only the Reverb app secret
 * can publish a server event. `operation` is the Operation id as a numeric string: the observer copies it, unread, into each
 * `client-executor-result` line, so a reply that carries no Operation (`accepted`, or a refusal before the payload is read) still
 * finds its Operation.
 */
final readonly class ExecutorOrderBroadcast implements ShouldBroadcastNow
{
    public const string Name = 'executor-order';

    public function __construct(
        public int $nodeId,
        public SignedExecutorOrder $order,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel("presence-node.{$this->nodeId}")];
    }

    public function broadcastAs(): string
    {
        return self::Name;
    }

    /** @return array{operation: string, order: string} */
    public function broadcastWith(): array
    {
        return ['operation' => $this->order->operation, 'order' => $this->order->wire];
    }
}

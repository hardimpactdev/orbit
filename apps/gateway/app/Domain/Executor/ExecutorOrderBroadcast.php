<?php

declare(strict_types=1);

namespace App\Domain\Executor;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * The server event `executor-order` on a Node's `presence-node.{id}` (ADR 0266 "The path" step 1). Only the Reverb app secret
 * can publish a server event. `ref` is the order's nonce: the observer echoes it, unread, on each `client-executor-result`
 * line, so a reply that carries no Operation (`accepted`, or a refusal before the signature) still finds its order.
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

    /** @return array{ref: string, order: string} */
    public function broadcastWith(): array
    {
        return ['ref' => $this->order->nonce, 'order' => $this->order->wire];
    }
}

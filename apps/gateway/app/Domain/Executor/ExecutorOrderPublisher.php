<?php

declare(strict_types=1);

namespace App\Domain\Executor;

use App\Domain\Broadcasting\RealtimeConnection;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Publishes a signed order as the server event `executor-order` on the Node's `presence-node.{id}` through the Reverb HTTP
 * API (ADR 0266 D5). It uses the Gateway's one Reverb publishing path: no second channel, no SSH, no HTTP to the Node.
 *
 * During a `websocket` move the event also goes to the old Node's Reverb, where an agent that connected before DNS moved still
 * listens. The agent holds one connection, so at most one copy reaches the executor; a second would be refused as a replayed
 * nonce. A failure on the serving server throws, so the caller can take the fallback at once.
 */
final readonly class ExecutorOrderPublisher
{
    public function __construct(private RealtimeConnection $realtime) {}

    /** @throws RuntimeException|Throwable when no websocket role is active or the serving Reverb refused the event. */
    public function publish(int $nodeId, SignedExecutorOrder $order): void
    {
        $connections = $this->realtime->all();
        if ($connections === []) {
            throw new RuntimeException('No websocket role is active.');
        }
        $event = new ExecutorOrderBroadcast($nodeId, $order);
        $servingFailure = null;

        try {
            $this->realtime->configureBroadcasting($connections[0]);
            event($event);
        } catch (Throwable $exception) {
            $servingFailure = $exception;
        }

        foreach (array_slice($connections, 1) as $connection) {
            if ($this->realtime->oldServerSkipped($connection)) {
                continue;
            }

            try {
                $this->realtime->configureBroadcasting($connection, oldServer: true);
                Broadcast::purge('reverb');
                $broadcaster = Broadcast::connection('reverb');
                if (! $broadcaster instanceof Broadcaster) {
                    throw new RuntimeException('The reverb broadcaster is unavailable.');
                }
                $broadcaster->broadcast($event->broadcastOn(), $event->broadcastAs(), $event->broadcastWith());
                $this->realtime->recordOldServer($connection, true);
            } catch (Throwable $exception) {
                // The order's wire form is a signed, short-lived credential: log only the Node and the nonce.
                Log::warning('Failed to publish an executor order to the old websocket server.', ['node' => $nodeId, 'nonce' => $order->nonce, 'exception' => $exception->getMessage()]);
                $this->realtime->recordOldServer($connection, false);
            }
        }

        if (count($connections) > 1) {
            $this->realtime->configureBroadcasting($connections[0]);
            Broadcast::purge('reverb');
        }

        if ($servingFailure !== null) {
            throw $servingFailure;
        }
    }
}

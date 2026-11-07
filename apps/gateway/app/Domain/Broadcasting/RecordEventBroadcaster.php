<?php

declare(strict_types=1);

namespace App\Domain\Broadcasting;

use Closure;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Broadcasts a record change to the `orbit` channel. A Reverb outage must
 * never fail the action that changed the record, so every broadcast failure
 * is logged and swallowed here. Broadcasting only runs when an active
 * `websocket` role assignment resolves a connection; otherwise this is a
 * no-op with no database access beyond the one lazy, memoized lookup.
 */
final class RecordEventBroadcaster
{
    /** Depth of {@see immediately()} calls in progress. */
    private static int $immediate = 0;

    public function __construct(
        private readonly RealtimeConnection $realtime,
    ) {}

    /**
     * Runs work whose events must go out at once, even inside a request: code that runs in a PHP shutdown
     * function after a fatal error, where the application never terminates.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function immediately(Closure $work): mixed
    {
        self::$immediate++;

        try {
            return $work();
        } finally {
            self::$immediate--;
        }
    }

    /**
     * Sends the event to the serving Reverb and, during a `websocket` move, also to the old Node's Reverb,
     * where the clients that connected before DNS moved still listen. A failure on one server never stops
     * the other.
     *
     * The HTTP call to Reverb runs after the surrounding transaction commits, never under SQLite's write lock, and a
     * rolled-back change broadcasts nothing. In an HTTP request it then waits for the response to go out, so the caller
     * never waits for Reverb; the events leave in the order the changes committed. Outside a request, such as in a
     * queue job or a console command, it runs at once.
     *
     * @param  array<string, mixed>  $data
     * @param  string  $channel  the private channel without its `private-` prefix
     */
    public function broadcast(RecordEventType $type, int|string $id, array $data, string $channel = 'orbit'): void
    {
        DB::afterCommit(function () use ($type, $id, $data, $channel): void {
            $event = new RecordBroadcast($type, $id, $data, $channel);

            if (self::$immediate > 0 || request()->route() === null) {
                $this->deliver($event, $type, $id);

                return;
            }

            // A terminating callback added while the application terminates still runs, in order, so a broadcast
            // that a terminating callback causes is not lost.
            app()->terminating(fn () => $this->deliver($event, $type, $id));
        });
    }

    private function deliver(RecordBroadcast $event, RecordEventType $type, int|string $id): void
    {
        try {
            $connections = $this->realtime->all();
        } catch (Throwable $exception) {
            $this->failed($type, $id, $exception);

            return;
        }

        if ($connections === []) {
            // Best-effort: when no websocket role is active this leaves the default `null` connection in
            // place, and the event still fires but broadcasts nowhere.
            $this->send(static fn () => event($event), $type, $id);

            return;
        }

        foreach ($connections as $index => $connection) {
            if ($index === 0) {
                $this->send(function () use ($connection, $event): void {
                    $this->realtime->configureBroadcasting($connection);
                    event($event);
                }, $type, $id);

                continue;
            }

            // The old server of a move only gets a short try, and none for a while after it failed, so an
            // unreachable old Node never slows the broadcast to the serving server.
            if ($this->realtime->oldServerSkipped($connection)) {
                continue;
            }

            $reached = $this->send(function () use ($connection, $event): void {
                $this->realtime->configureBroadcasting($connection, oldServer: true);
                // The old Node's server gets the same payload directly, so listeners run once.
                Broadcast::purge('reverb');
                $broadcaster = Broadcast::connection('reverb');

                if (! $broadcaster instanceof Broadcaster) {
                    throw new RuntimeException('The reverb broadcaster is unavailable.');
                }

                $broadcaster->broadcast($event->broadcastOn(), $event->broadcastAs(), $event->broadcastWith());
            }, $type, $id);
            $this->realtime->recordOldServer($connection, $reached);
        }

        if (count($connections) > 1) {
            // Later broadcasts in this request start again from the serving server.
            Broadcast::purge('reverb');
        }
    }

    /** @param Closure(): mixed $operation */
    private function send(Closure $operation, RecordEventType $type, int|string $id): bool
    {
        try {
            $operation();

            return true;
        } catch (Throwable $exception) {
            $this->failed($type, $id, $exception);

            return false;
        }
    }

    private function failed(RecordEventType $type, int|string $id, Throwable $exception): void
    {
        Log::warning('Failed to broadcast a record event.', [
            'type' => $type->value,
            'id' => $id,
            'exception' => $exception->getMessage(),
        ]);
    }
}

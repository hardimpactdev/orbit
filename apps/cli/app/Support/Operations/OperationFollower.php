<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Support\Console\InterruptIntent;
use App\Support\Realtime\RealtimeEvent;
use App\Support\Realtime\RealtimeState;
use App\Support\Realtime\RealtimeSubscriber;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Sleep;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Throwable;

/**
 * Follows one operation until it changes (docs/reference/operations.md#follow-an-operation-over-reverb). While the
 * operation's channel is subscribed, an `operation.updated` event with a newer sequence starts a read, and the
 * follower also reads every 15 seconds in case an event was lost. Without Reverb, or while the socket is down, it
 * reads every `poll_after_seconds`. Each read asks only for the steps after the last sequence it saw. Each
 * `client-output` line that the Node agent publishes while a deploy step runs goes to `$onOutput`.
 */
final class OperationFollower
{
    /** Seconds between two reads while the channel is subscribed and no event arrives. */
    public const int LiveReadSeconds = 15;

    /** Seconds between two reads when the Gateway names no poll interval. */
    public const int PollSeconds = 2;

    /** Consecutive failed reads, such as while the Gateway reloads, before the follower gives up. */
    public const int TransientReads = 10;

    private const float TickSeconds = 0.2;

    private int $sequence = 0;

    private int $eventSequence = 0;

    private ?CarbonImmutable $lastReadAt = null;

    /**
     * @param  Closure(int): OperationResponse  $read  reads the operation with the steps after this sequence
     * @param  (Closure(OperationOutputLine): void)|null  $onOutput
     */
    public function __construct(
        private readonly Closure $read,
        private readonly ?RealtimeSubscriber $subscriber,
        private readonly ?Closure $onOutput = null,
    ) {
        $this->subscriber?->connect();
    }

    /**
     * Waits until the operation changes after `$current`, and returns the read that saw the change. The read carries
     * only the steps that changed.
     *
     * @throws GatewayApiException after too many failed reads
     */
    public function next(OperationResponse $current): OperationResponse
    {
        $failures = 0;

        while (true) {
            InterruptIntent::throwIfPending();
            $live = $this->drainEvents();

            if ($this->due($current, $live)) {
                try {
                    $read = ($this->read)($this->sequence);
                    $failures = 0;
                } catch (GatewayApiException $exception) {
                    if (++$failures >= self::TransientReads) {
                        throw $exception;
                    }

                    $this->lastReadAt = CarbonImmutable::now();
                    Sleep::for(self::PollSeconds)->seconds();

                    continue;
                }

                $this->lastReadAt = CarbonImmutable::now();
                $changed = $read->sequence > $this->sequence;
                $this->sequence = max($this->sequence, $read->sequence);

                if ($changed || $read->terminal()) {
                    return $read;
                }

                $current = $read;
            }

            Sleep::for((int) (self::TickSeconds * 1_000_000))->microseconds();
        }
    }

    public function close(): void
    {
        $this->subscriber?->close();
    }

    private function due(OperationResponse $current, bool $live): bool
    {
        if ($this->lastReadAt === null || $this->eventSequence > $this->sequence) {
            return true;
        }

        $interval = $live ? self::LiveReadSeconds : ($current->pollAfterSeconds ?? self::PollSeconds);

        return $this->lastReadAt->diffInSeconds(CarbonImmutable::now(), absolute: true) >= $interval;
    }

    /**
     * Takes the newest sequence the channel announced, hands on the live output lines, and tells whether the channel
     * is subscribed.
     */
    private function drainEvents(): bool
    {
        if ($this->subscriber === null) {
            return false;
        }

        try {
            foreach ($this->subscriber->pollDecoded(static function (string $name, mixed $payload): RealtimeEvent|OperationOutputLine|null {
                if ($name === 'client-output') {
                    return OperationOutputLine::fromPayload($payload);
                }

                $event = RealtimeEvent::fromChannelPayload($name, $payload);

                return $event->type === 'operation.updated' ? $event : null;
            }) as $event) {
                if ($event instanceof OperationOutputLine) {
                    if ($this->onOutput !== null) {
                        ($this->onOutput)($event);
                    }

                    continue;
                }

                $sequence = $event->data['sequence'] ?? null;

                if (is_int($sequence)) {
                    $this->eventSequence = max($this->eventSequence, $sequence);
                }
            }
        } catch (Throwable) {
            // A broken socket only costs the follower its events; it reads on its own schedule.
            return false;
        }

        return $this->subscriber->state() === RealtimeState::Connected;
    }
}

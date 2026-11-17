<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use Closure;
use Illuminate\Support\Facades\Config;
use JsonException;

/**
 * The waiting request's side of the executor hand-off (ADR 0266 "The path", step 5): `BLPOP` on the Operation's
 * result key within a bound. It also takes the `accepted` line, so the caller knows when the executor accepted
 * the order (slice 7 falls back to the SSH relay without it).
 */
final readonly class ExecutorResultWait
{
    /** @param Closure(): float $clock */
    public function __construct(private ExecutorResultQueue $queue, private Closure $clock) {}

    /** The bound from the Gateway setting `orbit.executor.wait_ms`. */
    public static function boundSeconds(): float
    {
        return max(1, Config::integer('orbit.executor.wait_ms', 500)) / 1000;
    }

    public function wait(int $operation, ?float $boundSeconds = null): ExecutorWaitResult
    {
        $started = ($this->clock)();
        $deadline = $started + ($boundSeconds ?? self::boundSeconds());
        $resultKey = ExecutorResults::resultKey($operation);
        $acceptedKey = ExecutorResults::acceptedKey($operation);
        $acceptedMs = null;

        while (true) {
            $left = $deadline - ($this->clock)();

            // BLPOP reads a timeout of 0 as "forever", and Valkey counts in milliseconds.
            if ($left < 0.001) {
                return new ExecutorWaitResult(ExecutorWaitStatus::Timeout, $acceptedMs, $this->elapsedMs($started));
            }

            // The result key comes first, so a result that is already there wins over its `accepted` line.
            $popped = $this->queue->pop([$resultKey, $acceptedKey], round($left, 3));

            if ($popped === null) {
                continue;
            }

            if ($popped[0] === $acceptedKey) {
                $acceptedMs ??= $this->elapsedMs($started);

                continue;
            }

            try {
                $line = json_decode($popped[1], associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $status = is_array($line) ? ExecutorWaitStatus::tryFrom(is_string($line['status'] ?? null) ? $line['status'] : '') : null;

            if ($status === null || $status === ExecutorWaitStatus::Timeout) {
                continue;
            }

            $elapsedMs = $this->elapsedMs($started);

            // A result means the order was accepted, even when its `accepted` line is still queued behind it.
            /** @var array<string, mixed> $line */
            return new ExecutorWaitResult($status, $acceptedMs ?? $elapsedMs, $elapsedMs, $line);
        }
    }

    private function elapsedMs(float $started): float
    {
        return round((($this->clock)() - $started) * 1000, 3);
    }
}

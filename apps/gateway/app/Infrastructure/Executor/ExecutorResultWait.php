<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use Closure;
use Illuminate\Support\Facades\Config;
use JsonException;

/**
 * The waiting request's side of the executor hand-off (ADR 0266 "The path", step 5): `BLPOP` on the Operation's
 * reply list within a bound. It also takes the `accepted` line, so the caller knows when the executor accepted
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
        $boundSeconds ??= self::boundSeconds();
        $deadline = $started + $boundSeconds;
        $resultKey = ExecutorResults::resultKey($operation);
        $acceptedMs = null;

        while (true) {
            $left = $deadline - ($this->clock)();

            // BLPOP reads a timeout of 0 as "forever", and Valkey counts in milliseconds.
            if ($left < 0.001) {
                return new ExecutorWaitResult(ExecutorWaitStatus::Timeout, $acceptedMs, $this->elapsedMs($started));
            }

            $popped = $this->queue->pop([$resultKey], round($left, 3));

            if ($popped === null) {
                continue;
            }

            $received = ($this->clock)();
            $elapsedMs = round(($received - $started) * 1000, 3);
            if ($received > $deadline) {
                return new ExecutorWaitResult(ExecutorWaitStatus::Timeout, $acceptedMs, $elapsedMs);
            }

            try {
                $line = json_decode($popped[1], associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (! is_array($line)) {
                continue;
            }

            $status = $line['status'] ?? null;

            if ($status === ExecutorReply::Accepted) {
                $acceptedMs ??= $elapsedMs;

                continue;
            }

            $status = is_string($status) ? ExecutorWaitStatus::tryFrom($status) : null;

            if ($status === null || $status === ExecutorWaitStatus::Timeout) {
                continue;
            }

            /** @var array<string, mixed> $line */
            return new ExecutorWaitResult($status, $acceptedMs, $elapsedMs, $line);
        }
    }

    private function elapsedMs(float $started): float
    {
        return round((($this->clock)() - $started) * 1000, 3);
    }
}

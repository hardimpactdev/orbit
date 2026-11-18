<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use Closure;
use Illuminate\Support\Facades\Config;
use JsonException;
use Throwable;

/**
 * The waiting request's side of the executor hand-off (ADR 0266 "The path", step 5): `BLPOP` on the Operation's
 * reply list within a bound. It also takes the `accepted` line, so the caller knows when the executor accepted
 * the order (slice 7 falls back to the SSH relay without it).
 */
final readonly class ExecutorResultWait
{
    private const float WakeSocketReserveSeconds = 0.25;

    /** @param Closure(): float $clock */
    public function __construct(private ExecutorResultQueue $queue, private Closure $clock, private ?ExecutorResultQueue $wakeQueue = null) {}

    /** The bound from the Gateway setting `orbit.executor.wait_ms`. */
    public static function boundSeconds(): float
    {
        return max(1, Config::integer('orbit.executor.wait_ms', 500)) / 1000;
    }

    /** @param (Closure(): ?array<string, mixed>)|null $durable */
    public function wait(int $operation, ?float $boundSeconds = null, ?Closure $durable = null): ExecutorWaitResult
    {
        $started = ($this->clock)();
        $boundSeconds ??= self::boundSeconds();
        $deadline = $started + $boundSeconds;
        $resultKey = ExecutorResults::resultKey($operation);
        $acceptedMs = null;
        $queue = $durable === null ? $this->queue : ($this->wakeQueue ?? $this->queue);
        $wakeSocket = $durable !== null && $this->wakeQueue !== null;
        $wakeSocketFailed = false;

        while (true) {
            $left = $deadline - ($this->clock)();

            // BLPOP reads a timeout of 0 as "forever", and Valkey counts in milliseconds.
            if ($left < 0.001) {
                return new ExecutorWaitResult(ExecutorWaitStatus::Timeout, $acceptedMs, $this->elapsedMs($started));
            }

            if ($durable !== null && ($line = $durable()) !== null) {
                $status = is_string($line['status'] ?? null) ? ExecutorWaitStatus::tryFrom($line['status']) : null;
                if ($status !== null && ($this->clock)() <= $deadline) {
                    return new ExecutorWaitResult($status, $acceptedMs, $this->elapsedMs($started), $line);
                }

                continue;
            }
            if ($durable !== null) {
                $left = $deadline - ($this->clock)();
                if ($left < 0.001) {
                    continue;
                }
                if ($wakeSocketFailed || ($wakeSocket && $left < self::WakeSocketReserveSeconds)) {
                    usleep((int) floor(min(0.01, $left) * 1e6));

                    continue;
                }
            }
            try {
                $popped = $queue->pop([$resultKey], $durable === null ? round($left, 3) : floor(min(0.025, $left) * 1000) / 1000);
            } catch (Throwable $exception) {
                if (! $wakeSocket) {
                    throw $exception;
                }
                $wakeSocketFailed = true;

                continue;
            }

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

            if ($durable !== null) {
                continue;
            }

            if ($status === 'refused' && ($line['reason'] ?? null) === 'replayed_nonce') {
                $line = ExecutorReply::fromEvent(['operation' => (string) $operation, 'line' => $popped[1]])?->first()?->line;
                $status = $line['status'] ?? null;
            }

            $status = is_string($status) ? ExecutorWaitStatus::tryFrom($status) : null;

            if ($status === null || $status === ExecutorWaitStatus::Timeout || $line === null) {
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

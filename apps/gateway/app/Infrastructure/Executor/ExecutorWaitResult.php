<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

final readonly class ExecutorWaitResult
{
    /**
     * @param  float|null  $acceptedMs  Milliseconds from the start of the wait to an observed `accepted` line before the result, or null.
     * @param  array<string, mixed>|null  $line  The executor's result line; null on a timeout.
     */
    public function __construct(
        public ExecutorWaitStatus $status,
        public ?float $acceptedMs,
        public float $elapsedMs,
        public ?array $line = null,
    ) {}

    /** The refusal reason or the failure code. */
    public function reason(): ?string
    {
        $reason = $this->line[$this->status === ExecutorWaitStatus::Failed ? 'code' : 'reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }
}

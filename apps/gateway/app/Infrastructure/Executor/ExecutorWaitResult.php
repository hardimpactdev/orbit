<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

final readonly class ExecutorWaitResult
{
    /**
     * @param  float|null  $acceptedMs  When the wait saw the `accepted` line or the result, from its start; null when neither arrived.
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

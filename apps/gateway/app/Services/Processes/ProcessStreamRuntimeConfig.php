<?php

declare(strict_types=1);

namespace App\Services\Processes;

/**
 * Internal process SSE loop timing. Not part of the public browser route contract.
 *
 * Public query surface is only `app`. Last-Event-ID is the native EventSource header
 * (accepted, never used to replay history after a fresh snapshot).
 */
final readonly class ProcessStreamRuntimeConfig
{
    /**
     * @param  int  $pollMicroseconds  Cross-worker DB tail interval between idle polls.
     * @param  int  $heartbeatMicroseconds  SSE comment heartbeat interval (independent of poll).
     * @param  int|null  $maxIdlePolls  Bound idle polls for tests; null follows until disconnect.
     */
    public function __construct(
        public int $pollMicroseconds = 500_000,
        public int $heartbeatMicroseconds = 15_000_000,
        public ?int $maxIdlePolls = null,
    ) {}

    public function with(
        ?int $pollMicroseconds = null,
        ?int $heartbeatMicroseconds = null,
        ?int $maxIdlePolls = null,
        bool $clearMaxIdlePolls = false,
    ): self {
        return new self(
            pollMicroseconds: $pollMicroseconds ?? $this->pollMicroseconds,
            heartbeatMicroseconds: $heartbeatMicroseconds ?? $this->heartbeatMicroseconds,
            maxIdlePolls: $clearMaxIdlePolls ? null : ($maxIdlePolls ?? $this->maxIdlePolls),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

final readonly class NodeAgentPushStreamResult
{
    /**
     * @param  array{code: string, message: string, retryable: bool}|null  $agentError
     */
    public function __construct(
        public ?int $exitCode,
        public ?array $agentError,
        public bool $legacyPassthrough,
    ) {}

    public function failed(): bool
    {
        if ($this->agentError !== null) {
            return true;
        }

        return $this->exitCode !== 0;
    }
}

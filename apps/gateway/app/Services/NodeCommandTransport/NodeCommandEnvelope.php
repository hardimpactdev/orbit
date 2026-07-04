<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

final readonly class NodeCommandEnvelope
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public string $commandId,
        public bool $requiresNodeExecution,
        public bool $supportsAgentPushTransport,
        public array $payload = [],
    ) {}

    public static function gatewayOnlyRead(string $commandId): self
    {
        return new self(
            commandId: $commandId,
            requiresNodeExecution: false,
            supportsAgentPushTransport: false,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function nodeExecuting(
        string $commandId,
        bool $supportsAgentPushTransport = false,
        array $payload = [],
    ): self {
        return new self(
            commandId: $commandId,
            requiresNodeExecution: true,
            supportsAgentPushTransport: $supportsAgentPushTransport,
            payload: $payload,
        );
    }

    public static function agentPushNoop(): self
    {
        return new self(
            commandId: 'orbit.agent.noop',
            requiresNodeExecution: true,
            supportsAgentPushTransport: true,
            payload: [],
        );
    }
}

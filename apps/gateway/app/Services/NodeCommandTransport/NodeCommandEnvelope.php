<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

final readonly class NodeCommandEnvelope
{
    public string $commandId;

    public bool $requiresNodeExecution;

    public bool $supportsAgentPushTransport;

    /**
     * @var array<string, mixed>
     */
    public array $payload;

    public ?string $operationId;

    public ?string $binary;

    /**
     * @var list<string>
     */
    public array $argv;

    public ?string $input;

    public int $timeoutSeconds;

    public bool $stream;

    /**
     * @param  array{
     *     command_id: string,
     *     requires_node_execution: bool,
     *     supports_agent_push_transport: bool,
     *     payload?: array<string, mixed>,
     *     operation_id?: string|null,
     *     binary?: string|null,
     *     argv?: list<string>,
     *     input?: string|null,
     *     timeout_seconds?: int,
     *     stream?: bool,
     * }  $attributes
     */
    private function __construct(array $attributes)
    {
        $this->commandId = $attributes['command_id'];
        $this->requiresNodeExecution = $attributes['requires_node_execution'];
        $this->supportsAgentPushTransport = $attributes['supports_agent_push_transport'];
        $this->payload = $attributes['payload'] ?? [];
        $this->operationId = $attributes['operation_id'] ?? null;
        $this->binary = $attributes['binary'] ?? null;
        $this->argv = $attributes['argv'] ?? [];
        $this->input = $attributes['input'] ?? null;
        $this->timeoutSeconds = $attributes['timeout_seconds'] ?? 30;
        $this->stream = $attributes['stream'] ?? true;
    }

    public static function gatewayOnlyRead(string $commandId): self
    {
        return new self([
            'command_id' => $commandId,
            'requires_node_execution' => false,
            'supports_agent_push_transport' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function nodeExecuting(
        string $commandId,
        bool $supportsAgentPushTransport = false,
        array $payload = [],
    ): self {
        return new self([
            'command_id' => $commandId,
            'requires_node_execution' => true,
            'supports_agent_push_transport' => $supportsAgentPushTransport,
            'payload' => $payload,
        ]);
    }

    /**
     * @param  list<string>  $argv
     */
    public static function agentPushBinary(
        string $operationId,
        string $binary,
        array $argv,
        ?string $input = null,
        int $timeoutSeconds = 30,
        bool $stream = true,
    ): self {
        return new self([
            'command_id' => 'orbit.agent.binary',
            'requires_node_execution' => true,
            'supports_agent_push_transport' => true,
            'operation_id' => $operationId,
            'binary' => $binary,
            'argv' => $argv,
            'input' => $input,
            'timeout_seconds' => $timeoutSeconds,
            'stream' => $stream,
        ]);
    }
}

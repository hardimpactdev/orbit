<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

final readonly class NodeAgentPushResult
{
    /**
     * @var list<array<array-key, mixed>>
     */
    public array $frames;

    public string $transport;

    public string $operationId;

    public string $binary;

    public string $status;

    public ?int $exitCode;

    /**
     * @param  array{
     *     transport: string,
     *     operation_id: string,
     *     binary: string,
     *     status: string,
     *     frames: list<array<array-key, mixed>>,
     *     exit_code?: int|null,
     * }  $attributes
     */
    public function __construct(array $attributes)
    {
        $this->frames = $attributes['frames'];
        $this->transport = $attributes['transport'];
        $this->operationId = $attributes['operation_id'];
        $this->binary = $attributes['binary'];
        $this->status = $attributes['status'];
        $this->exitCode = $attributes['exit_code'] ?? null;
    }
}

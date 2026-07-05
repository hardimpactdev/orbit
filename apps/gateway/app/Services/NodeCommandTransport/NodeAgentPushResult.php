<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

use RuntimeException;

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
     * @param  array<string, mixed>  $payload
     */
    public static function fromAgentPayload(array $payload): self
    {
        return new self([
            'transport' => self::stringValue($payload, 'transport'),
            'operation_id' => self::stringValue($payload, 'operation_id'),
            'binary' => self::stringValue($payload, 'binary'),
            'status' => self::stringValue($payload, 'status'),
            'frames' => self::frames($payload),
            'exit_code' => self::intValue($payload, 'exit_code'),
        ]);
    }

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function stringValue(array $payload, string $key): string
    {
        if (! array_key_exists($key, $payload) || ! is_string($payload[$key]) || trim($payload[$key]) === '') {
            throw new RuntimeException("Orbit Agent push response is missing '{$key}'.");
        }

        return $payload[$key];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<array-key, mixed>>
     */
    private static function frames(array $payload): array
    {
        if (! array_key_exists('frames', $payload) || ! is_array($payload['frames'])) {
            return [];
        }

        return array_values(array_filter(
            $payload['frames'],
            is_array(...),
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function intValue(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new RuntimeException("Orbit Agent push response '{$key}' must be an integer.");
        }

        return $value;
    }
}

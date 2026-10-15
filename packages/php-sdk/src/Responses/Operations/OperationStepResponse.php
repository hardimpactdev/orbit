<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Operations;

use InvalidArgumentException;
use SensitiveParameter;

/** One step of an operation. `sequence` is the operation's sequence when the step last changed. */
final readonly class OperationStepResponse
{
    public function __construct(
        public string $name,
        public int $position,
        public string $status,
        public bool $resumable,
        public int $sequence,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $errorCode,
        public ?string $output,
        public bool $outputTruncated,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        $name = $data['name'] ?? null;
        $position = $data['position'] ?? null;
        $status = $data['status'] ?? null;
        $sequence = $data['sequence'] ?? null;

        if (! is_string($name) || $name === '' || ! is_int($position) || ! is_string($status) || ! is_int($sequence)) {
            throw new InvalidArgumentException('Gateway response contains an invalid operation step.');
        }

        return new self(
            name: $name,
            position: $position,
            status: $status,
            resumable: ($data['resumable'] ?? false) === true,
            sequence: $sequence,
            startedAt: self::text($data, 'started_at'),
            finishedAt: self::text($data, 'finished_at'),
            errorCode: self::text($data, 'error_code'),
            output: self::text($data, 'output'),
            outputTruncated: ($data['output_truncated'] ?? false) === true,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'position' => $this->position,
            'status' => $this->status,
            'resumable' => $this->resumable,
            'sequence' => $this->sequence,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'error_code' => $this->errorCode,
            'output' => $this->output,
            'output_truncated' => $this->outputTruncated,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : null;
    }
}

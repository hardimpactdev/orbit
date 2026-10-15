<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/** How an operation kind ended its run: the terminal status, the error code of a failure, and the result. */
final readonly class OperationOutcome
{
    /** @param array<string, mixed>|null $result */
    private function __construct(
        public OperationStatus $status,
        public ?string $errorCode,
        public ?array $result,
    ) {}

    /** @param array<string, mixed> $result */
    public static function succeeded(array $result = []): self
    {
        return new self(OperationStatus::Succeeded, null, $result);
    }

    /** @param array<string, mixed>|null $result */
    public static function failed(string $errorCode, ?array $result = null): self
    {
        return new self(OperationStatus::Failed, $errorCode, $result);
    }

    /** @param array<string, mixed>|null $result */
    public static function cancelled(?array $result = null): self
    {
        return new self(OperationStatus::Cancelled, null, $result);
    }
}

<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Data;

/**
 * Result wrapper for provision action steps.
 *
 * Provides a consistent way to return success/failure status
 * with optional error messages and additional data.
 */
final readonly class StepResult
{
    private function __construct(
        public bool $success,
        public ?string $error = null,
        public array $data = [],
    ) {}

    public static function success(array $data = []): self
    {
        return new self(success: true, data: $data);
    }

    public static function failed(string $error): self
    {
        return new self(success: false, error: $error);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailed(): bool
    {
        return ! $this->success;
    }
}

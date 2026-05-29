<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class ProviderAvailability
{
    private function __construct(
        public bool $available,
        public string $message,
    ) {}

    public static function available(string $message = 'available'): self
    {
        return new self(true, $message);
    }

    public static function unavailable(string $message): self
    {
        return new self(false, $message);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolVersionRequest
{
    public function __construct(
        public ?string $value,
    ) {}

    public static function fromInput(?string $value): self
    {
        if ($value === null) {
            return new self(null);
        }

        $value = trim($value);

        return new self($value === '' ? null : $value);
    }

    public function isEmpty(): bool
    {
        return $this->value === null;
    }
}

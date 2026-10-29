<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use JsonSerializable;
use SensitiveParameter;

/** Secret transport input with explicit redactions; safe collection identifiers stay usable in receipts. */
final readonly class VaultExecutionInput implements JsonSerializable
{
    /** @param array<string, mixed> $value */
    public function __construct(#[SensitiveParameter] private array $value) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->value;
    }

    /** @return array<string, never> */
    public function __debugInfo(): array
    {
        return [];
    }
}

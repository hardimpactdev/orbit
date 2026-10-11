<?php

declare(strict_types=1);

namespace App\Domain\Vault;

/**
 * The plaintext of one Secure Note the Gateway publishes. The client encrypts it with the
 * organization key.
 *
 * @phpstan-type Field array{name: string, value: string, hidden: bool}
 */
final readonly class VaultCipher
{
    /** @param list<Field> $fields */
    public function __construct(
        public string $name,
        public string $notes,
        public array $fields,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['name' => $this->name];
    }
}

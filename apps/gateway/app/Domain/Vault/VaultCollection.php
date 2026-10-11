<?php

declare(strict_types=1);

namespace App\Domain\Vault;

/** A collection the account can reach, with its decrypted name. */
final readonly class VaultCollection
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public bool $readOnly,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Vault;

/** An organization the account is a confirmed member of. */
final readonly class VaultOrganization
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}

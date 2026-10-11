<?php

declare(strict_types=1);

namespace App\Domain\Vault;

/** An item as Vaultwarden lists it, without its content. */
final readonly class VaultRemoteCipher
{
    /** @param list<string> $collectionIds */
    public function __construct(
        public string $id,
        public ?string $organizationId,
        public array $collectionIds,
        public ?string $revisionDate,
    ) {}
}

<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

/**
 * An item as Vaultwarden lists it. `source` is its decrypted `orbit.source` field, so the Gateway
 * recognizes items it published even without a ledger row. `deleted` marks an item in the trash.
 */
final readonly class VaultRemoteCipher
{
    /** @param list<string> $collectionIds */
    public function __construct(
        public string $id,
        public ?string $organizationId,
        public array $collectionIds,
        public ?string $revisionDate,
        public ?string $source = null,
        public bool $deleted = false,
    ) {}
}

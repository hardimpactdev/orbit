<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

/** One Database item the Gateway wants in Vaultwarden, and the record it comes from. */
final readonly class DatabaseCredentialItem
{
    public function __construct(
        public string $source,
        public VaultCipher $cipher,
    ) {}

    /**
     * A keyed hash of everything the item contains and where it goes, so a changed password,
     * host, or collection rewrites the item without storing the password in plain form.
     */
    public function fingerprint(string $organizationId, string $collectionId, string $key): string
    {
        return hash_hmac('sha256', json_encode([
            $organizationId,
            $collectionId,
            $this->cipher->name,
            $this->cipher->notes,
            $this->cipher->fields,
        ], JSON_THROW_ON_ERROR), $key);
    }
}

<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

/** Vaultwarden's API, as the Gateway uses it. Every failure is a VaultException. */
interface VaultClient
{
    /** Logs in with the API key, unlocks the account, and reads what it can reach. */
    public function open(VaultCredentials $credentials): VaultSession;

    public function create(VaultSession $session, string $organizationId, string $collectionId, VaultCipher $cipher): VaultRemoteCipher;

    /** Throws `vault.item_missing` when the item is gone, so the caller can create it again. */
    public function update(VaultSession $session, string $cipherId, string $organizationId, VaultCipher $cipher): VaultRemoteCipher;

    /** Deletes the item permanently. An item that is already gone counts as deleted. */
    public function delete(VaultSession $session, string $cipherId): void;
}

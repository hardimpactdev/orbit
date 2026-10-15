<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Actions;

use Orbit\Extensions\Vault\Data\VaultStatusData;
use Orbit\Extensions\Vault\Domain\VaultClient;
use Orbit\Extensions\Vault\Domain\VaultException;
use Orbit\Extensions\Vault\Domain\VaultLock;
use Orbit\Extensions\Vault\Domain\VaultRemoteCipher;
use Orbit\Extensions\Vault\Domain\VaultSettings;
use Orbit\Extensions\Vault\Models\VaultItem;

/**
 * Permanently deletes every Orbit item in the collection, unless asked to keep them, then the stored
 * setup. That covers items in the ledger and items that only carry an `orbit.source` field, such as
 * one whose create timed out. A failed deletion keeps the setup and the items not yet deleted, so
 * teardown can run again.
 */
final readonly class TeardownVaultAction
{
    public function __construct(
        private VaultSettings $settings,
        private VaultClient $client,
        private VaultLock $lock,
        private ShowVaultStatusAction $status,
    ) {}

    public function execute(bool $keepItems): VaultStatusData
    {
        $this->lock->run(function () use ($keepItems): void {
            $configuration = $this->settings->configuration() ?? throw new VaultException(
                'vault.not_configured',
                'Vaultwarden is not set up.',
                409,
            );

            if (! $keepItems) {
                $session = $this->client->open($configuration->credentials);
                $orbitItems = array_keys(array_filter(
                    $session->ciphers,
                    static fn (VaultRemoteCipher $remote): bool => $remote->source !== null
                        && $remote->organizationId === $configuration->organizationId
                        && in_array($configuration->collectionId, $remote->collectionIds, true),
                ));

                foreach (VaultItem::query()->get() as $published) {
                    $this->client->delete($session, $published->cipher_id);
                    $published->delete();
                }

                foreach ($orbitItems as $cipherId) {
                    $this->client->delete($session, $cipherId);
                }
            }

            VaultItem::query()->delete();
            $this->settings->clear();
        });

        return $this->status->execute();
    }
}

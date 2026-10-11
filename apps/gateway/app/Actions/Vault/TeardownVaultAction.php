<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Data\Vault\VaultStatusData;
use App\Domain\Vault\VaultClient;
use App\Domain\Vault\VaultException;
use App\Domain\Vault\VaultLock;
use App\Domain\Vault\VaultSettings;
use App\Models\VaultItem;

/**
 * Permanently deletes the published items, unless asked to keep them, then the stored setup. A
 * failed deletion keeps the setup and the items not yet deleted, so teardown can run again.
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
        $configuration = $this->settings->configuration() ?? throw new VaultException(
            'vault.not_configured',
            'Vaultwarden is not set up.',
            409,
        );

        $this->lock->run(function () use ($configuration, $keepItems): void {
            if (! $keepItems && VaultItem::query()->exists()) {
                $session = $this->client->open($configuration->credentials);

                foreach (VaultItem::query()->get() as $published) {
                    $this->client->delete($session, $published->cipher_id);
                    $published->delete();
                }
            }

            VaultItem::query()->delete();
            $this->settings->clear();
        });

        return $this->status->execute();
    }
}

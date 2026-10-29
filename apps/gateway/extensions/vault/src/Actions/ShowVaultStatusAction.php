<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Actions;

use Illuminate\Support\Facades\Config;
use Orbit\Extensions\Vault\Data\VaultStatusData;
use Orbit\Extensions\Vault\Domain\DatabaseCredentialCatalog;
use Orbit\Extensions\Vault\Domain\VaultSettings;
use Orbit\Extensions\Vault\Models\VaultItem;

/** Reads the status from the Gateway's own records. It never contacts Vaultwarden. */
final readonly class ShowVaultStatusAction
{
    public function __construct(
        private VaultSettings $settings,
        private DatabaseCredentialCatalog $catalog,
    ) {}

    public function execute(): VaultStatusData
    {
        $configuration = $this->settings->configuration();

        if ($configuration === null) {
            return new VaultStatusData(false, null, null, null, null, 0, 0, [], null, $this->settings->lastError());
        }

        $catalog = $this->catalog->build();
        $published = VaultItem::query()->get();
        $fingerprints = [];
        foreach ($catalog['items'] as $source => $item) {
            $fingerprints[$source] = $item->fingerprint($configuration->organizationId, $configuration->collectionId, Config::string('app.key'));
        }
        $recorded = [];
        foreach ($published as $item) {
            $recorded[$item->source] = $item->fingerprint;
        }
        $pending = count(array_diff_assoc($fingerprints, $recorded)) + count(array_diff_key($recorded, $fingerprints));

        return new VaultStatusData(
            configured: true,
            url: $configuration->credentials->url,
            email: $configuration->email,
            organization: ['id' => $configuration->organizationId, 'name' => $configuration->organizationName],
            collection: ['id' => $configuration->collectionId, 'name' => $configuration->collectionName],
            items: $published->count(),
            pending: $pending,
            skipped: $catalog['skipped'],
            lastSyncedAt: $this->settings->lastSyncedAt(),
            lastError: $this->settings->lastError(),
        );
    }
}

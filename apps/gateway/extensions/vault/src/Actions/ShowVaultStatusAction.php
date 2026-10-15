<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Actions;

use Illuminate\Support\Facades\Config;
use Orbit\Extensions\Vault\Data\VaultStatusData;
use Orbit\Extensions\Vault\Domain\DatabaseCredentialCatalog;
use Orbit\Extensions\Vault\Domain\VaultPlan;
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
            return new VaultStatusData(false, null, null, null, null, 0, 0, [], null, null);
        }

        $catalog = $this->catalog->build();
        $published = VaultItem::query()->get();
        $plan = VaultPlan::for($configuration, $catalog['items'], $published, Config::string('app.key'));

        return new VaultStatusData(
            configured: true,
            url: $configuration->credentials->url,
            email: $configuration->email,
            organization: ['id' => $configuration->organizationId, 'name' => $configuration->organizationName],
            collection: ['id' => $configuration->collectionId, 'name' => $configuration->collectionName],
            items: $published->count(),
            pending: $plan->pending(),
            skipped: $catalog['skipped'],
            lastSyncedAt: $this->settings->lastSyncedAt(),
            lastError: $this->settings->lastError(),
        );
    }
}

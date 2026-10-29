<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Actions;

use App\Models\Operation;
use Illuminate\Support\Str;
use Orbit\Extensions\Vault\Data\SetupVaultData;
use Orbit\Extensions\Vault\Domain\VaultSettings;
use Orbit\Extensions\Vault\Models\VaultItem;

/** Account verification runs in Swamp before completion stores the configured collection. */
final readonly class SetupVaultAction
{
    public function __construct(private VaultSettings $settings, private StartVaultOperationAction $operations) {}

    public function execute(SetupVaultData $data): Operation
    {
        $existing = $this->settings->configuration();

        return $this->operations->execute('setup', [
            'url' => rtrim($data->url, '/'), 'client_id' => $data->clientId,
            'client_secret' => $data->clientSecret, 'master_password' => $data->masterPassword,
            'device_identifier' => $existing?->credentials->deviceIdentifier ?? (string) Str::uuid(),
            'organization' => $data->organization, 'collection' => $data->collection,
            'previous' => $existing === null ? null : ['organization_id' => $existing->organizationId, 'collection_id' => $existing->collectionId],
            'published' => VaultItem::query()->exists(),
        ]);
    }
}

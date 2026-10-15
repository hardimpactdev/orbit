<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Actions;

use Illuminate\Support\Str;
use Orbit\Extensions\Vault\Data\SetupVaultData;
use Orbit\Extensions\Vault\Data\VaultStatusData;
use Orbit\Extensions\Vault\Domain\VaultClient;
use Orbit\Extensions\Vault\Domain\VaultCollection;
use Orbit\Extensions\Vault\Domain\VaultConfiguration;
use Orbit\Extensions\Vault\Domain\VaultCredentials;
use Orbit\Extensions\Vault\Domain\VaultException;
use Orbit\Extensions\Vault\Domain\VaultLock;
use Orbit\Extensions\Vault\Domain\VaultOrganization;
use Orbit\Extensions\Vault\Domain\VaultSettings;
use Orbit\Extensions\Vault\Models\VaultItem;

/**
 * Logs in with the given account, finds the organization and collection, checks that the account
 * can write to it, and only then stores the setup. A changed organization or collection is refused
 * while items are published, because they would stay behind in the old one.
 */
final readonly class SetupVaultAction
{
    public function __construct(
        private VaultSettings $settings,
        private VaultClient $client,
        private VaultLock $lock,
        private ShowVaultStatusAction $status,
    ) {}

    public function execute(SetupVaultData $data): VaultStatusData
    {
        $this->lock->run(function () use ($data): void {
            $existing = $this->settings->configuration();
            $credentials = new VaultCredentials(
                rtrim($data->url, '/'),
                $data->clientId,
                $data->clientSecret,
                $data->masterPassword,
                $existing?->credentials->deviceIdentifier ?? (string) Str::uuid(),
            );
            $session = $this->client->open($credentials);
            $organization = $this->organization($session->organizations, $data->organization);
            $collection = $this->collection($session->collections, $organization, $data->collection);

            if ($existing instanceof VaultConfiguration
                && ($existing->organizationId !== $organization->id || $existing->collectionId !== $collection->id)
                && VaultItem::query()->exists()) {
                throw new VaultException(
                    'vault.items_published',
                    'Items are published in another organization or collection. Run vault:teardown first.',
                    409,
                );
            }

            $this->settings->put(new VaultConfiguration(
                $credentials,
                $session->email,
                $organization->id,
                $organization->name,
                $collection->id,
                $collection->name,
            ));
            $this->settings->forgetVerification();
        });

        return $this->status->execute();
    }

    /** @param list<VaultOrganization> $organizations */
    private function organization(array $organizations, string $wanted): VaultOrganization
    {
        $matches = array_values(array_filter(
            $organizations,
            static fn (VaultOrganization $organization): bool => $organization->id === $wanted || $organization->name === $wanted,
        ));

        if (count($matches) !== 1) {
            throw new VaultException(
                'vault.organization_missing',
                count($matches) === 0
                    ? "The Vaultwarden account is not a confirmed member of organization [{$wanted}]."
                    : "More than one organization is named [{$wanted}]. Use its ID.",
                422,
            );
        }

        return $matches[0];
    }

    /** @param list<VaultCollection> $collections */
    private function collection(array $collections, VaultOrganization $organization, string $wanted): VaultCollection
    {
        $matches = array_values(array_filter(
            $collections,
            static fn (VaultCollection $collection): bool => $collection->organizationId === $organization->id
                && ($collection->id === $wanted || $collection->name === $wanted),
        ));

        if (count($matches) !== 1) {
            throw new VaultException(
                'vault.collection_missing',
                count($matches) === 0
                    ? "Organization [{$organization->name}] has no collection [{$wanted}] that the account can reach."
                    : "More than one collection is named [{$wanted}]. Use its ID.",
                422,
            );
        }

        if ($matches[0]->readOnly) {
            throw new VaultException(
                'vault.collection_read_only',
                "The Vaultwarden account cannot write to collection [{$matches[0]->name}]. Give it Can manage.",
                422,
            );
        }

        return $matches[0];
    }
}

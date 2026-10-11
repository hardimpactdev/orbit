<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Data\Vault\VaultStatusData;
use App\Domain\Vault\DatabaseCredentialCatalog;
use App\Domain\Vault\DatabaseCredentialItem;
use App\Domain\Vault\VaultClient;
use App\Domain\Vault\VaultConfiguration;
use App\Domain\Vault\VaultException;
use App\Domain\Vault\VaultLock;
use App\Domain\Vault\VaultPlan;
use App\Domain\Vault\VaultSession;
use App\Domain\Vault\VaultSettings;
use App\Models\VaultItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Makes the Vaultwarden collection match the Gateway's MySQL and PostgreSQL credentials. A run that
 * finds nothing to change does not contact Vaultwarden, except for the hourly check that recreates
 * items that are gone and rewrites items someone changed.
 */
final readonly class SyncVaultAction
{
    public const int VerifyEveryMinutes = 60;

    public function __construct(
        private VaultSettings $settings,
        private DatabaseCredentialCatalog $catalog,
        private VaultClient $client,
        private VaultLock $lock,
        private ShowVaultStatusAction $status,
    ) {}

    /** Runs a sync with the check now, for `vault:sync`. */
    public function execute(): VaultStatusData
    {
        $configuration = $this->configured();
        $this->lock->run(fn () => $this->reconcile($configuration, true));

        return $this->status->execute();
    }

    /**
     * The scheduled run. It does nothing while Vaultwarden is not set up or another run holds the lock.
     * Returns the error of a failed run or item, or null.
     *
     * @return array{code: string, message: string}|null
     */
    public function scheduled(): ?array
    {
        $configuration = $this->settings->configuration();

        if ($configuration === null) {
            return null;
        }

        try {
            $this->lock->run(fn () => $this->reconcile($configuration, false));
        } catch (VaultException $exception) {
            if ($exception->errorCode === 'vault.sync_busy') {
                return null;
            }
        }

        return $this->settings->lastError();
    }

    private function reconcile(VaultConfiguration $configuration, bool $verify): void
    {
        $now = CarbonImmutable::now();
        $lastVerified = $this->settings->lastVerifiedAt();
        $verify = $verify || ! $lastVerified instanceof CarbonImmutable || $lastVerified->addMinutes(self::VerifyEveryMinutes)->lessThanOrEqualTo($now);
        $key = Config::string('app.key');
        $wanted = $this->catalog->build()['items'];
        $plan = VaultPlan::for($configuration, $wanted, VaultItem::query()->get(), $key);

        if (! $verify && $plan->pending() === 0) {
            $this->settings->recordSuccess($now);

            return;
        }

        try {
            $session = $this->client->open($configuration->credentials);

            if (! isset($session->organizationKeys[$configuration->organizationId])) {
                throw new VaultException(
                    'vault.organization_missing',
                    'The Vaultwarden account is no longer a confirmed member of the organization.',
                    422,
                );
            }
        } catch (VaultException $exception) {
            $this->settings->recordError($exception->errorCode, $exception->getMessage());

            throw $exception;
        }

        if ($verify) {
            $changed = $this->check($configuration, $session);
            $plan = VaultPlan::for($configuration, $wanted, VaultItem::query()->get(), $key, $changed);
        }

        $errors = [];

        foreach ($plan->creates as $item) {
            $this->attempt($errors, fn () => $this->create($configuration, $session, $item, $plan->fingerprints[$item->source]));
        }

        foreach ($plan->updates as $update) {
            $this->attempt($errors, fn () => $this->update($configuration, $session, $update['item'], $update['published'], $plan->fingerprints[$update['item']->source]));
        }

        foreach ($plan->deletes as $published) {
            $this->attempt($errors, function () use ($session, $published): void {
                $this->client->delete($session, $published->cipher_id);
                $published->delete();
            });
        }

        if ($verify) {
            $this->settings->recordVerified($now);
        }

        if ($errors === []) {
            $this->settings->recordSuccess($now);

            return;
        }

        $this->settings->recordError($errors[0]->errorCode, $errors[0]->getMessage());
    }

    /**
     * Forgets published items that are gone from the collection, so the plan creates them again,
     * and returns the sources of items that someone changed in Vaultwarden.
     *
     * @return list<string>
     */
    private function check(VaultConfiguration $configuration, VaultSession $session): array
    {
        $changed = [];

        foreach (VaultItem::query()->get() as $published) {
            $remote = $session->ciphers[$published->cipher_id] ?? null;

            if ($remote === null
                || $remote->organizationId !== $configuration->organizationId
                || ! in_array($configuration->collectionId, $remote->collectionIds, true)) {
                $published->delete();

                continue;
            }

            if ($remote->revisionDate !== $published->revision_date) {
                $changed[] = $published->source;
            }
        }

        return $changed;
    }

    private function create(VaultConfiguration $configuration, VaultSession $session, DatabaseCredentialItem $item, string $fingerprint): void
    {
        $remote = $this->client->create($session, $configuration->organizationId, $configuration->collectionId, $item->cipher);

        VaultItem::query()->updateOrCreate(
            ['source' => $item->source],
            ['cipher_id' => $remote->id, 'fingerprint' => $fingerprint, 'revision_date' => $remote->revisionDate],
        );
    }

    private function update(
        VaultConfiguration $configuration,
        VaultSession $session,
        DatabaseCredentialItem $item,
        VaultItem $published,
        string $fingerprint,
    ): void {
        try {
            $remote = $this->client->update($session, $published->cipher_id, $configuration->organizationId, $item->cipher);
        } catch (VaultException $exception) {
            if ($exception->errorCode !== 'vault.item_missing') {
                throw $exception;
            }

            $this->create($configuration, $session, $item, $fingerprint);

            return;
        }

        $published->update(['fingerprint' => $fingerprint, 'revision_date' => $remote->revisionDate]);
    }

    /**
     * @param  list<VaultException>  $errors
     * @param  callable(): void  $change
     */
    private function attempt(array &$errors, callable $change): void
    {
        try {
            $change();
        } catch (VaultException $exception) {
            $errors[] = $exception;
        }
    }

    private function configured(): VaultConfiguration
    {
        return $this->settings->configuration() ?? throw new VaultException(
            'vault.not_configured',
            'Vaultwarden is not set up. Run vault:setup first.',
            409,
        );
    }
}

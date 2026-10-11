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
use App\Domain\Vault\VaultRemoteCipher;
use App\Domain\Vault\VaultSession;
use App\Domain\Vault\VaultSettings;
use App\Models\VaultItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Makes the Vaultwarden collection match the Gateway's MySQL and PostgreSQL credentials. A run that
 * finds nothing to change does not contact Vaultwarden, except for the hourly check. Every run that
 * does contact it also reconciles the collection: it adopts an item it published without recording
 * it, recreates items that are gone, rewrites items someone changed, and deletes duplicates and
 * items whose source is gone. Items are recognized by their `orbit.source` field.
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

    /** Runs a sync with the check now, for `vault:sync`. A failed change fails the request. */
    public function execute(): VaultStatusData
    {
        $errors = $this->lock->run(fn (): array => $this->reconcile($this->configured(), true));

        if ($errors !== []) {
            throw new VaultException(
                'vault.sync_failed',
                count($errors).' Vaultwarden change(s) failed. First: '.$errors[0]->getMessage(),
                502,
                details: ['failed' => count($errors), 'first_error' => $errors[0]->errorCode],
            );
        }

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
        if ($this->settings->configuration() === null) {
            return null;
        }

        try {
            $this->lock->run(function (): void {
                // Read again inside the lock, so a setup or teardown that just finished wins.
                $configuration = $this->settings->configuration();

                if ($configuration instanceof VaultConfiguration) {
                    $this->reconcile($configuration, false);
                }
            });
        } catch (VaultException $exception) {
            if ($exception->errorCode === 'vault.sync_busy') {
                return null;
            }
        }

        return $this->settings->lastError();
    }

    /** @return list<VaultException> the changes that failed */
    private function reconcile(VaultConfiguration $configuration, bool $verify): array
    {
        $now = CarbonImmutable::now();
        $lastVerified = $this->settings->lastVerifiedAt();
        $verify = $verify || ! $lastVerified instanceof CarbonImmutable || $lastVerified->addMinutes(self::VerifyEveryMinutes)->lessThanOrEqualTo($now);
        $key = Config::string('app.key');
        $wanted = $this->catalog->build()['items'];
        $plan = VaultPlan::for($configuration, $wanted, VaultItem::query()->get(), $key);

        if (! $verify && $plan->pending() === 0) {
            $this->settings->recordSuccess($now);

            return [];
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

        ['changed' => $changed, 'orphans' => $orphans] = $this->check($configuration, $session, $wanted);
        $plan = VaultPlan::for($configuration, $wanted, VaultItem::query()->get(), $key, $changed);
        $errors = [];

        foreach ($orphans as $cipherId) {
            $this->attempt($errors, fn () => $this->client->delete($session, $cipherId));
        }

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

        $this->settings->recordVerified($now);

        if ($errors === []) {
            $this->settings->recordSuccess($now);

            return [];
        }

        $this->settings->recordError($errors[0]->errorCode, $errors[0]->getMessage());

        return $errors;
    }

    /**
     * Compares the ledger with the collection. It forgets a published item that is gone or in the
     * trash, so the plan creates it again, and adopts an item that carries a wanted source but has
     * no ledger row, such as one whose create timed out after Vaultwarden stored it. It returns the
     * sources of items someone changed, and the items to delete: duplicates of a source, trashed
     * copies, and items whose source Orbit no longer has.
     *
     * @param  array<string, DatabaseCredentialItem>  $wanted
     * @return array{changed: list<string>, orphans: list<string>}
     */
    private function check(VaultConfiguration $configuration, VaultSession $session, array $wanted): array
    {
        $inCollection = array_filter(
            $session->ciphers,
            static fn (VaultRemoteCipher $remote): bool => $remote->organizationId === $configuration->organizationId
                && in_array($configuration->collectionId, $remote->collectionIds, true),
        );
        $changed = [];
        $kept = [];

        foreach (VaultItem::query()->get() as $published) {
            $remote = $inCollection[$published->cipher_id] ?? null;

            if ($remote === null || $remote->deleted) {
                $published->delete();

                continue;
            }

            $kept[$published->source] = $published->cipher_id;

            if ($remote->revisionDate !== $published->revision_date) {
                $changed[] = $published->source;
            }
        }

        $orphans = [];

        foreach ($inCollection as $remote) {
            if ($remote->source === null || ($kept[$remote->source] ?? null) === $remote->id) {
                continue;
            }

            if (! $remote->deleted && ! isset($kept[$remote->source]) && isset($wanted[$remote->source])) {
                // An empty fingerprint makes the plan rewrite the adopted item.
                VaultItem::query()->create([
                    'source' => $remote->source,
                    'cipher_id' => $remote->id,
                    'fingerprint' => '',
                    'revision_date' => $remote->revisionDate,
                ]);
                $kept[$remote->source] = $remote->id;

                continue;
            }

            $orphans[] = $remote->id;
        }

        return ['changed' => $changed, 'orphans' => $orphans];
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

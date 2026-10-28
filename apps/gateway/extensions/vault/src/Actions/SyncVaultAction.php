<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Actions;

use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Orbit\Extensions\Vault\Domain\DatabaseCredentialCatalog;
use Orbit\Extensions\Vault\Domain\VaultException;
use Orbit\Extensions\Vault\Domain\VaultPlan;
use Orbit\Extensions\Vault\Domain\VaultSettings;
use Orbit\Extensions\Vault\Models\VaultItem;

/** Records a collection reconciliation; it never contacts Vaultwarden. */
final readonly class SyncVaultAction
{
    public const int VerifyEveryMinutes = 60;

    public function __construct(private VaultSettings $settings, private DatabaseCredentialCatalog $catalog, private StartVaultOperationAction $operations) {}

    public function execute(string $trigger = 'operator'): Operation
    {
        $configuration = $this->settings->configuration() ?? throw new VaultException('vault.not_configured', 'Vaultwarden is not set up. Run vault:setup first.', 409);
        $wanted = [];
        foreach ($this->catalog->build()['items'] as $item) {
            $wanted[] = ['source' => $item->source, 'fingerprint' => $item->fingerprint($configuration->organizationId, $configuration->collectionId, Config::string('app.key')),
                'cipher' => ['name' => $item->cipher->name, 'notes' => $item->cipher->notes, 'fields' => $item->cipher->fields]];
        }

        return $this->operations->execute('sync', [
            ...$configuration->toStoredArray(), 'wanted' => $wanted,
            'ledger' => VaultItem::query()->orderBy('id')->get(['source', 'cipher_id', 'revision_date', 'fingerprint'])->toArray(),
        ], $trigger);
    }

    /** A tick records at most one intent and skips an unchanged collection between hourly checks. */
    public function scheduled(): ?Operation
    {
        $configuration = $this->settings->configuration();
        if ($configuration === null || Operation::query()->where('target_type', 'vault')
            ->whereIn('status', [OperationStatus::Queued->value, OperationStatus::Running->value])->exists()) {
            return null;
        }
        $now = CarbonImmutable::now();
        $verified = $this->settings->lastVerifiedAt();
        $plan = VaultPlan::for($configuration, $this->catalog->build()['items'], VaultItem::query()->get(), Config::string('app.key'));
        if ($plan->pending() === 0 && $verified !== null && $verified->addMinutes(self::VerifyEveryMinutes)->greaterThan($now)) {
            return null;
        }

        return $this->execute('schedule');
    }
}

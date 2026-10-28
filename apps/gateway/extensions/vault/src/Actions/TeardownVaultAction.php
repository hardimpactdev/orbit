<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Actions;

use App\Models\Operation;
use Orbit\Extensions\Vault\Domain\VaultException;
use Orbit\Extensions\Vault\Domain\VaultSettings;
use Orbit\Extensions\Vault\Models\VaultItem;

/** Keeps configuration and published intent until Swamp proves deletion or explicit retention. */
final readonly class TeardownVaultAction
{
    public function __construct(private VaultSettings $settings, private StartVaultOperationAction $operations) {}

    public function execute(bool $keepItems): Operation
    {
        $configuration = $this->settings->configuration() ?? throw new VaultException('vault.not_configured', 'Vaultwarden is not set up.', 409);

        return $this->operations->execute('teardown', [...$configuration->toStoredArray(), 'keep_items' => $keepItems,
            'ledger' => VaultItem::query()->orderBy('id')->get(['source', 'cipher_id', 'revision_date', 'fingerprint'])->toArray()]);
    }
}

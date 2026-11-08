<?php

declare(strict_types=1);

namespace App\Commands\Vault;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Vault\DestroyVaultRequest;

final class DestroyVaultCommand extends VaultCommand
{
    #[\Override]
    protected $signature = 'vault:destroy
        {--keep-items : Leave the published items in Vaultwarden}
        {--yes : Confirm the destruction without a prompt}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy the Orbit Vault account and its published items.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $keepItems = $this->option('keep-items') === true;
        $question = $keepItems
            ? 'Destroy Vaultwarden publishing? This deletes the stored account, organization, and collection. The published items stay in Vaultwarden.'
            : 'Destroy Vaultwarden publishing? This permanently deletes every item Orbit published, then the stored account, organization, and collection.';

        if (! $this->confirmAction(
            $question,
            'Vault destruction cancelled.',
            requiredMessage: 'Non-interactive vault destruction requires --yes.',
        )) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        return $this->integrationOperation($connector, $repository, new DestroyVaultRequest($keepItems, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), 'Destroy vault');
    }
}

<?php

declare(strict_types=1);

namespace App\Commands\Vault;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Vault\TeardownVaultRequest;

final class TeardownVaultCommand extends VaultCommand
{
    #[\Override]
    protected $signature = 'vault:teardown
        {--keep-items : Leave the published items in Vaultwarden}
        {--yes : Confirm the teardown without a prompt}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Delete the published items and the stored account.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $keepItems = $this->option('keep-items') === true;
        $question = $keepItems
            ? 'Tear down Vaultwarden publishing? This deletes the stored account, organization, and collection. The published items stay in Vaultwarden.'
            : 'Tear down Vaultwarden publishing? This permanently deletes every item Orbit published, then the stored account, organization, and collection.';

        if (! $this->confirmAction(
            $question,
            'Vault teardown cancelled.',
            requiredMessage: 'Non-interactive vault teardown requires --yes.',
        )) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        return $this->integrationOperation($connector, $repository, new TeardownVaultRequest($keepItems, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), 'Tear down vault');
    }
}

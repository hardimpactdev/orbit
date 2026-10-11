<?php

declare(strict_types=1);

namespace App\Commands\Vault;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Vault\TeardownVaultRequest;
use Orbit\Sdk\Responses\Vault\VaultStatusResponse;

final class TeardownVaultCommand extends VaultCommand
{
    #[\Override]
    protected $signature = 'vault:teardown
        {--keep-items : Leave the published items in Vaultwarden}
        {--yes : Confirm the teardown without a prompt}
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

        $response = $this->sendWithProgress(
            $connector,
            new TeardownVaultRequest($keepItems),
            VaultStatusResponse::class,
            ['Tear down vault', $keepItems ? 'Deleting the stored account' : 'Deleting the published items', 'Tore down vault'],
        );

        return $response instanceof VaultStatusResponse
            ? $this->renderStatus($response, 'Vaultwarden publishing is torn down.')
            : self::FAILURE;
    }
}

<?php

declare(strict_types=1);

namespace App\Commands\Vault;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Vault\ShowVaultStatusRequest;
use Orbit\Sdk\Responses\Vault\VaultStatusResponse;

final class StatusVaultCommand extends VaultCommand
{
    #[\Override]
    protected $signature = 'vault:status {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show the setup and the state of the published items.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ShowVaultStatusRequest,
            VaultStatusResponse::class,
            ['Show vault status', 'Loading vault status', 'Loaded vault status'],
        );

        return $response instanceof VaultStatusResponse
            ? $this->renderStatus($response, 'Vault status.')
            : self::FAILURE;
    }
}

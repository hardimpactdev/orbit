<?php

declare(strict_types=1);

namespace App\Commands\Vault;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Vault\SyncVaultRequest;
use Orbit\Sdk\Responses\Vault\VaultStatusResponse;

final class SyncVaultCommand extends VaultCommand
{
    #[\Override]
    protected $signature = 'vault:sync {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Publish changes now and repair changed or missing items.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new SyncVaultRequest,
            VaultStatusResponse::class,
            ['Sync vault', 'Publishing database credentials', 'Synced vault'],
        );

        if (! $response instanceof VaultStatusResponse) {
            return self::FAILURE;
        }

        // The Gateway refuses a sync with failed changes; an error still recorded fails the run too.
        $exit = $this->renderStatus($response, 'Vault sync finished.');

        return $response->lastError === null ? $exit : self::FAILURE;
    }
}

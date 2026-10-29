<?php

declare(strict_types=1);

namespace App\Commands\Vault;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Vault\SyncVaultRequest;

final class SyncVaultCommand extends VaultCommand
{
    #[\Override]
    protected $signature = 'vault:sync {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Publish changes now and repair changed or missing items.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        return $this->integrationOperation($connector, $repository, new SyncVaultRequest(is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), 'Sync vault');
    }
}

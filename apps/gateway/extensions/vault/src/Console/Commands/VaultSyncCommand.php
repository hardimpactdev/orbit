<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Console\Commands;

use Illuminate\Console\Command;
use Orbit\Extensions\Vault\Actions\SyncVaultAction;
use Orbit\Extensions\Vault\Domain\VaultSettings;

final class VaultSyncCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:vault-sync';

    #[\Override]
    protected $description = 'Publish changed database credentials to Vaultwarden, when it is set up.';

    public function handle(SyncVaultAction $action, VaultSettings $settings): int
    {
        $operation = $action->scheduled();
        $error = $settings->lastError();
        if ($error !== null) {
            $this->components->error($error['code'].': '.$error['message']);

            return self::FAILURE;
        }
        if ($operation !== null) {
            $this->components->info('Vault Operation '.$operation->id.' queued; collection completion is pending.');
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Console\Commands;

use Illuminate\Console\Command;
use Orbit\Extensions\Vault\Actions\SyncVaultAction;

final class VaultSyncCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:vault-sync';

    #[\Override]
    protected $description = 'Publish changed database credentials to Vaultwarden, when it is set up.';

    public function handle(SyncVaultAction $action): int
    {
        $error = $action->scheduled();

        if ($error === null) {
            return self::SUCCESS;
        }

        $this->components->error("{$error['code']}: {$error['message']}");

        return self::FAILURE;
    }
}

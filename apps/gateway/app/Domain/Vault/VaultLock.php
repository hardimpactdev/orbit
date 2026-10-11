<?php

declare(strict_types=1);

namespace App\Domain\Vault;

use Closure;
use Illuminate\Support\Facades\Cache;

/** One setup, sync, or teardown at a time. */
final readonly class VaultLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $lock = Cache::lock('orbit:vault-sync', 900);

        if (! $lock->get()) {
            throw new VaultException('vault.sync_busy', 'Another Vaultwarden sync or teardown is running.', 409);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}

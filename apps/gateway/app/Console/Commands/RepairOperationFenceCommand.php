<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Operations\OperationVersionCleanup;
use Illuminate\Console\Command;

final class RepairOperationFenceCommand extends Command
{
    protected $signature = 'operations:repair-fence {--release-lock-held : The release switch runs this and already holds the release lock}';

    protected $description = 'Rewrite the Operation-version fence from the serving release policy.';

    public function handle(OperationVersionCleanup $cleanup): int
    {
        if ($this->option('release-lock-held') || ! config()->boolean('orbit.gateway_container')) {
            $cleanup->check();

            return self::SUCCESS;
        }
        // The host's release lock and the handover marker are in the state directory that every release container
        // mounts. A switch holds the lock and writes the marker before it fences; the marker goes only once the
        // inventory names the new live. A busy lock or a marker skips this tick; the switch repairs after it publishes.
        $state = dirname(GatewayReleaseLayout::fromConfig()->containerInventoryPath());
        $lock = @fopen($state.'/gateway-release.lock', 'r');
        if ($lock === false) {
            $this->warn('The release lock is unreadable; the fence repair waits for the next tick.');

            return self::SUCCESS;
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->line('A release holds the lock; the fence repair waits for the next tick.');

                return self::SUCCESS;
            }
            // A handover that routed traffic but has not written the inventory yet keeps its marker until the
            // switch or --recover publishes the inventory. Until then live in the inventory may be the outgoing release.
            clearstatcache(true, $state.'/gateway-handover');
            if (file_exists($state.'/gateway-handover')) {
                $this->line('A release handover is unfinished; the fence repair waits for it.');

                return self::SUCCESS;
            }
            $cleanup->check();
            flock($lock, LOCK_UN);
        } finally {
            fclose($lock);
        }

        return self::SUCCESS;
    }
}

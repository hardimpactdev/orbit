<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\ReconcilePeerDnsAction;
use Illuminate\Console\Command;

final class ReconcilePeerDnsCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:peer-dns-reconcile';

    #[\Override]
    protected $description = 'Reconcile private DNS routing domains on managed Linux peers.';

    public function handle(ReconcilePeerDnsAction $action): int
    {
        $failed = $action->execute();
        if ($failed !== []) {
            $this->error('Peer DNS reconciliation failed on: '.implode(', ', $failed).'.');

            return self::FAILURE;
        }
        $this->info('Managed peer DNS policies are aligned.');

        return self::SUCCESS;
    }
}

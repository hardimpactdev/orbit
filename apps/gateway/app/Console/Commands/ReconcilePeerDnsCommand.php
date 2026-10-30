<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fleet\AdmitPeerDnsAction;
use App\Actions\Fleet\PeerDnsTargetsAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Console\Command;

/** Admission only. Swamp executes each Node Operation. */
final class ReconcilePeerDnsCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:peer-dns-reconcile';

    #[\Override]
    protected $description = 'Record peer DNS reconciliation Operations for Swamp.';

    public function handle(PeerDnsTargetsAction $targets, AdmitPeerDnsAction $admit): int
    {
        $operations = [];
        foreach ($targets->execute() as $target) {
            try {
                $operations[] = $admit->execute(Node::query()->findOrFail($target['node_id']))->id;
            } catch (ResourceOperationException $error) {
                if ($error->errorCode !== 'operation.target_busy') {
                    throw $error;
                }
            }
        }
        $this->line(json_encode(['operations' => $operations], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

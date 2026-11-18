<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Instances\Deployment\InstanceDeployExecutionCopies;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/** Deletes the vault copies of every ended deploy, such as those that ended before deploys removed their own copies. */
final class PruneInstanceDeployCopiesCommand extends Command
{
    /** Deploys per serve connection: serve admits 50 connections from the Gateway's address a minute. */
    private const int Batch = 200;

    #[\Override]
    protected $signature = 'orbit:instance-deploy-copies-prune {--dry-run : Count the deploys and key names only}';

    #[\Override]
    protected $description = 'Delete the vault copies of ended instance.deploy Operations; an open deploy and an unsettled timed-out one keep theirs.';

    public function handle(InstanceDeployExecutionCopies $copies): int
    {
        $dryRun = (bool) $this->option('dry-run');
        [$deploys, $keys, $kept] = [0, 0, 0];
        Operation::query()->where(fn ($query) => $query->where('kind', 'instance.deploy')->orWhere(fn ($rollback) => $rollback->where('kind', 'instance.rollback')->whereNotNull('input->production_release')))->whereIn('status', OperationStatus::terminalValues())
            ->chunkById(self::Batch, function (Collection $chunk) use ($copies, $dryRun, &$deploys, &$keys, &$kept): void {
                $ended = $chunk->filter(InstanceDeployExecutionCopies::ended(...))->values();
                $kept += $chunk->count() - $ended->count();
                $deploys += $ended->count();
                $keys += $ended->sum(static fn (Operation $deploy): int => count(InstanceDeployExecutionCopies::keys($deploy)));
                if (! $dryRun) {
                    $copies->remove(...$ended->all());
                }
            });
        $this->line(($dryRun ? 'Would delete' : 'Deleted')." {$keys} key names of {$deploys} ended deploys; kept {$kept} timed-out deploys whose run has not settled.");

        return self::SUCCESS;
    }
}

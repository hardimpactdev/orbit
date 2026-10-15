<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\TaskVmSetupScript;
use Illuminate\Console\Command;

/**
 * Runs `incus-host.sh` on one host with the values that `TaskVmSettings` validated, preparing its local project, storage, and network.
 */
final class PrepareTaskVmHostCommand extends Command
{
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:prepare-host {node : Id or name of the Incus host Node}';

    #[\Override]
    protected $description = 'Prepare an Incus host for task VMs: ZFS pool, project, stock image, bridge, egress ACL, profile, the ufw route rule.';

    public function handle(TaskVmSetupScript $script): int
    {
        try {
            $node = $this->activeNode((string) $this->argument('node'));
            $host = resolve(TaskVmSettings::class)->host($node->id);
            $script->run($node, TaskVmSetupScript::HostScript, [
                $host->project, $host->network, $host->cidr, $host->pool, TaskVmHost::SourceImage,
                ...($host->zfsDataset === null ? [] : [$host->zfsDataset]),
            ]);
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Node [{$node->name}] is ready for task VMs on bridge [{$host->network}]. Build its images with task-vms:build-image.");

        return self::SUCCESS;
    }
}

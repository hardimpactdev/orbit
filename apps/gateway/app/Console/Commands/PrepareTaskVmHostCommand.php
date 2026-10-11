<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\TaskVmImagePeers;
use App\Infrastructure\TaskVms\TaskVmSetupScript;
use Illuminate\Console\Command;

/**
 * Runs `incus-host.sh` on one host with the values that `TaskVmSettings` validated, then lets every configured
 * host copy base images from every other one.
 */
final class PrepareTaskVmHostCommand extends Command
{
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:prepare-host {node : Id or name of the Incus host Node}';

    #[\Override]
    protected $description = 'Prepare an Incus host for task VMs: ZFS pool, project, stock image, bridge, egress ACL, profile, the ufw route rule, and image sync between the hosts.';

    public function handle(TaskVmSetupScript $script): int
    {
        try {
            $node = $this->activeNode((string) $this->argument('node'));
            $host = resolve(TaskVmSettings::class)->host($node->id);
            $script->run($node, TaskVmSetupScript::HostScript, [
                $host->project, $host->network, $host->cidr, $host->pool, TaskVmHost::SourceImage,
                ...($host->zfsDataset === null ? [] : [$host->zfsDataset]),
            ]);
            resolve(TaskVmImagePeers::class)->converge(function (string $name): void {
                $this->line("Node [{$name}] can sync base images with the other hosts.");
            });
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Node [{$node->name}] is ready for task VMs on bridge [{$host->network}]. Sync its base image with task-vms:sync-image, or build one with task-vms:build-image.");

        return self::SUCCESS;
    }
}

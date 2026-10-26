<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusHost;
use Illuminate\Console\Command;

/**
 * Sends the validated task VM host intent to the native Incus command.
 */
final class PrepareTaskVmHostCommand extends Command
{
    use ReadsStringInput;
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:prepare-host {node : Id or name of the Incus host Node}';

    #[\Override]
    protected $description = 'Prepare an Incus host for task VMs: ZFS pool, project, stock image, bridge, egress ACL, profile, the ufw route rule.';

    public function handle(IncusHost $incus): int
    {
        try {
            $node = $this->activeNode($this->stringArgument('node'));
            $host = resolve(TaskVmSettings::class)->host($node->id);
            $incus->prepare($host, $node);
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Node [{$node->name}] is ready for task VMs on bridge [{$host->network}]. Build its images with task-vms:build-image.");

        return self::SUCCESS;
    }
}

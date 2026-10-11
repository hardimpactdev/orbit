<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\TaskVms\TaskVmImagePeers;
use Illuminate\Console\Command;

/**
 * Removes the image transfer setup of a host whose entry is gone from `task_vms.incus.hosts`: its transfer key,
 * receiver, and peer keys, and its key on every remaining host.
 */
final class RetireTaskVmHostCommand extends Command
{
    #[\Override]
    protected $signature = 'task-vms:retire-host {node : Id of the former Incus host Node}';

    #[\Override]
    protected $description = 'Remove the base image transfer setup of a former task VM host from it and from every remaining host.';

    public function handle(): int
    {
        $reference = (string) $this->argument('node');
        try {
            if (! ctype_digit($reference) || (int) $reference < 1) {
                throw new TaskVmException('task_vm.unknown_host', "Node [{$reference}] is not a Node id.");
            }
            resolve(TaskVmImagePeers::class)->retire((int) $reference);
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Node [{$reference}] no longer sends or receives base images, and no remaining host accepts its key.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\IncusTaskVmImageSync;
use App\Jobs\TaskVms\RefreshTaskVmWorkspaceTemplates;
use Illuminate\Console\Command;

/**
 * Copies the current base image of the source host to one other host and prints each stage with its time. Unless the
 * host already had the image, it queues the workspace template refresh at the end, also after a failure.
 */
final class SyncTaskVmImageCommand extends Command
{
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:sync-image
        {node : Id or name of the Incus host Node that gets the image}
        {--from= : Id or name of the host to copy from; default the first configured host}';

    #[\Override]
    protected $description = 'Copy the current task VM base image from one Incus host to another and point orbit-task-base at it.';

    public function handle(IncusTaskVmImageSync $sync, IncusTaskVmBaseImage $image): int
    {
        $started = microtime(true);
        try {
            $settings = resolve(TaskVmSettings::class);
            $node = $this->activeNode((string) $this->argument('node'));
            $host = $settings->host($node->id);
            $from = $this->option('from');
            $sourceHost = is_string($from) && $from !== ''
                ? $settings->host($this->activeNode($from)->id)
                : $settings->sourceHost() ?? throw new TaskVmException('task_vm.unknown_host', 'No task VM host is configured.');
            $source = $this->activeNode((string) $sourceHost->nodeId);
            if ($source->id === $node->id) {
                throw new TaskVmException('task_vm.image_sync_failed', "Node [{$node->name}] is the source of the base image sync.");
            }
            $fingerprint = $sync->current($sourceHost, $source);
            $owner = 'command-'.bin2hex(random_bytes(8));
            // A stopped command deletes its smoke VM and frees the host for the next build or sync.
            $this->trap([SIGINT, SIGTERM], function (int $signal) use ($image, $host, $node, $owner): never {
                $image->abandon($host, $node, $owner);
                RefreshTaskVmWorkspaceTemplates::dispatch();
                $this->error("Stopped by signal [{$signal}]. The smoke VM is deleted and the current base image is kept.");

                exit(self::FAILURE);
            });
            $copied = null;
            try {
                $copied = $sync->sync($sourceHost, $source, $host, $node, $fingerprint, function (string $stage, float $seconds): void {
                    $this->line(sprintf('%-22s %7.1fs', $stage, $seconds));
                }, $owner);
            } finally {
                if ($copied !== false) {
                    RefreshTaskVmWorkspaceTemplates::dispatch();
                }
            }
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            $copied ? 'Node [%s] has the base image [%s] (%s) of Node [%s] after %.1fs.' : 'Node [%s] already has the base image [%s] (%s) of Node [%s].',
            $node->name, TaskVmHost::BaseImage, substr($fingerprint, 0, 12), $source->name, microtime(true) - $started,
        ));

        return self::SUCCESS;
    }
}

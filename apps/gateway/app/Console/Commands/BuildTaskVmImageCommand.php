<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageSyncResult;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\IncusTaskVmImageBuilder;
use App\Infrastructure\TaskVms\IncusTaskVmImageSync;
use App\Jobs\TaskVms\RefreshTaskVmWorkspaceTemplates;
use App\Models\Node;
use Illuminate\Console\Command;

/**
 * Builds the base image on one task VM host, then syncs it to every other configured host. Prints each stage
 * with its time, and one line for each host. At the end it queues one workspace template refresh, also after a
 * failure, so the templates follow every alias that moved.
 */
final class BuildTaskVmImageCommand extends Command
{
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:build-image {node : Id or name of the Incus host Node that builds the image}';

    #[\Override]
    protected $description = 'Build the task VM base image on one Incus host, point orbit-task-base at it, and sync it to every other host.';

    public function handle(IncusTaskVmImageBuilder $builder, IncusTaskVmImageSync $sync, IncusTaskVmBaseImage $image): int
    {
        try {
            return $this->buildAndSync($builder, $sync, $image);
        } finally {
            RefreshTaskVmWorkspaceTemplates::dispatch();
        }
    }

    private function buildAndSync(IncusTaskVmImageBuilder $builder, IncusTaskVmImageSync $sync, IncusTaskVmBaseImage $image): int
    {
        $started = microtime(true);
        try {
            $node = $this->activeNode((string) $this->argument('node'));
            $settings = resolve(TaskVmSettings::class);
            $host = $settings->host($node->id);
            $owner = 'command-'.bin2hex(random_bytes(8));
            // A stopped command deletes its builder and smoke VMs and frees each host for the next build or sync.
            $this->trap([SIGINT, SIGTERM], function (int $signal) use ($image, $settings, $owner): never {
                foreach ($settings->hosts as $each) {
                    $eachNode = Node::query()->find($each->nodeId);
                    if ($eachNode instanceof Node) {
                        $image->abandon($each, $eachNode, $owner);
                    }
                }
                RefreshTaskVmWorkspaceTemplates::dispatch();
                $this->error("Stopped by signal [{$signal}]. The builder and smoke VMs are deleted and each host keeps its current base image.");

                exit(self::FAILURE);
            });
            $fingerprint = $builder->build($host, $node, function (string $stage, float $seconds): void {
                $this->line(sprintf('%-22s %7.1fs', $stage, $seconds));
            }, $owner);
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('Node [%s] has the base image [%s] (%s) after %.1fs.', $node->name, TaskVmHost::BaseImage, substr($fingerprint, 0, 12), microtime(true) - $started));

        $results = $sync->distribute($host, $node, $fingerprint, function (string $name, string $stage, float $seconds): void {
            $this->line(sprintf('%-22s %-10s %7.1fs', $name, $stage, $seconds));
        }, $owner);
        foreach ($results as $result) {
            $result->succeeded() ? $this->info($result->summary()) : $this->error($result->summary());
        }

        return array_all($results, static fn (TaskVmImageSyncResult $result): bool => $result->succeeded()) ? self::SUCCESS : self::FAILURE;
    }
}

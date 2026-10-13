<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageSyncResult;
use App\Domain\TaskVms\TaskVmQueue;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\IncusTaskVmImageBuilder;
use App\Infrastructure\TaskVms\IncusTaskVmImageSync;
use App\Models\Node;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The nightly refresh: builds the base image once, on the first configured host, and syncs it to every other
 * configured host. The first build is always `task-vms:build-image`, so while the first host has no base image
 * the job changes nothing. A failed build changes no host. A host whose sync fails keeps its image; the job
 * fails after the other hosts. It runs on the image queue, whose own worker runs one job at a time, so the build and
 * sync hold up no task VM job.
 */
final class RefreshTaskVmBaseImages implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** One build of about two minutes and a sync of about one minute for each other host, within the worker's job limit. */
    public int $timeout = 1500;

    public bool $failOnTimeout = true;

    /** The owner of the image locks this job takes, so `failed()` cleans up only its own work. */
    private const string LockOwner = 'nightly-refresh';

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onConnection('task-vms')->onQueue(TaskVmQueue::Images->value);
    }

    public function handle(TaskVmSettings $settings, IncusTaskVmImageBuilder $builder, IncusTaskVmImageSync $sync): void
    {
        $host = $settings->sourceHost();
        if ($host === null) {
            return;
        }
        $node = Node::query()->whereKey($host->nodeId)->where('status', LifecycleStatus::Active)->first()
            ?? throw new TaskVmException('task_vm.unknown_host', "The first task VM host, Node [{$host->nodeId}], is not an active Node.");
        if (! $builder->exists($host, $node)) {
            return;
        }

        // The templates refresh once, after the build and the sync to every host, also when the build fails after it
        // moved an alias. So no refresh sees one host moved and another not.
        try {
            $fingerprint = $builder->build($host, $node, owner: self::LockOwner);
            $results = $sync->distribute($host, $node, $fingerprint, owner: self::LockOwner);
        } finally {
            RefreshTaskVmWorkspaceTemplates::dispatch();
        }
        foreach ($results as $result) {
            Log::info('Task VM base image sync.', ['result' => $result->summary()]);
        }

        $failures = array_filter($results, static fn (TaskVmImageSyncResult $result): bool => ! $result->succeeded());
        if ($failures !== []) {
            throw new TaskVmException('task_vm.image_sync_failed', 'The base image sync failed on '.implode(', ', array_map(
                static fn (TaskVmImageSyncResult $result): string => "Node [{$result->nodeName}]: {$result->errorCode}",
                $failures,
            )).'.', 502);
        }
    }

    /**
     * After a timeout the worker stops this job in the middle of a build or sync, so its own cleanup never runs.
     * The worker calls this first: it deletes the builder and smoke VMs, frees each host's image lock, and queues
     * the template refresh that the job never reached. After an ordinary failure the build or sync has cleaned up
     * already, and the refresh is queued already: the queue keeps one refresh until it starts.
     */
    public function failed(?Throwable $exception): void
    {
        $image = app(IncusTaskVmBaseImage::class);
        foreach (app(TaskVmSettings::class)->hosts as $host) {
            $node = Node::query()->find($host->nodeId);
            if ($node instanceof Node) {
                $image->abandon($host, $node, self::LockOwner);
            }
        }
        RefreshTaskVmWorkspaceTemplates::dispatch();
    }
}

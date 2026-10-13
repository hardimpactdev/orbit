<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmQueue;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\UpCloudServers;
use App\Infrastructure\TaskVms\UpCloudTaskVmImageBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * The nightly UpCloud refresh: builds a new UpCloud base image, a template in UpCloud, with the shared recipe, as
 * `task-vms:build-upcloud-image` does, and keeps the two newest verified templates. The first build is always that
 * command, so while UpCloud has no verified template the job changes nothing. A failed build keeps the current
 * template. It runs on the image queue, whose own worker runs one job at a time, so the build holds up no task VM job.
 */
final class RefreshUpCloudTaskVmBaseImage implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    /** The image worker's limit, below the queue's `retry_after` of 1800 seconds. A build takes about 20 minutes. */
    public int $timeout = 1700;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onTaskVmQueue(TaskVmQueue::Images);
    }

    public function handle(TaskVmSettings $settings, UpCloudServers $servers, UpCloudTaskVmImageBuilder $builder): void
    {
        if ($settings->upcloud === null || $servers->currentTemplate() === null) {
            return;
        }

        $template = $builder->build(static function (string $stage, float $seconds): void {
            Log::info('UpCloud task VM template build stage done.', ['stage' => $stage, 'seconds' => $seconds]);
        });
        Log::info('UpCloud task VM template refreshed.', ['template' => $template]);
    }
}

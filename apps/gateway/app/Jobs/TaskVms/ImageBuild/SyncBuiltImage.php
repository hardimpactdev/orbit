<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms\ImageBuild;

use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusTaskVmImageSync;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Uses the existing synchronous sync; splitting that operation is a separate feature. */
final class SyncBuiltImage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1440;

    public int $tries = 1;

    public function __construct(public int $buildId, public int $hostNodeId) {}

    public function handle(TaskVmSettings $settings, IncusTaskVmImageSync $sync): void
    {
        $build = TaskVmImageBuild::query()->findOrFail($this->buildId);
        $source = $build->hostNode;
        $node = Node::query()->findOrFail($this->hostNodeId);
        try {
            $sync->sync($settings->host($source->id), $source, $settings->host($node->id), $node, (string) $build->image_fingerprint);
        } catch (Throwable $error) {
            // Preserve the old distribute semantics: other hosts and the template refresh still run.
            Log::error('Task VM base image sync failed.', ['node' => $node->name, 'error' => $error->getMessage()]);
        }
    }
}

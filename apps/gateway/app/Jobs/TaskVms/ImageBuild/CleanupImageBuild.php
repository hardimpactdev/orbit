<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms\ImageBuild;

use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Models\TaskVmImageBuild;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CleanupImageBuild implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public int $buildId) {}

    public function handle(TaskVmSettings $settings, IncusTaskVmBaseImage $image, IncusHost $incus): void
    {
        // Claim before any remote call. The durable fence survives a killed worker; a retry is safe,
        // but resume must refuse once cleanup has been claimed, even if the VM still exists.
        $claimed = TaskVmImageBuild::query()->whereKey($this->buildId)->where('state', TaskVmImageBuildState::Failed)
            ->update(['cleanup_started_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $build = TaskVmImageBuild::query()->findOrFail($this->buildId);
        $node = $build->hostNode;
        $name = (string) $build->builder_vm_name;
        $host = $settings->host($node->id);
        $alias = $host->imageAlias.'-'.$build->created_at?->format('YmdHis').'-'.$build->id;
        $fingerprint = $build->image_fingerprint;
        try {
            $fingerprint ??= $incus->aliasTarget($host, $node, $alias);
        } finally {
            $image->discard($host, $node, [$name, $name.'-smoke'], $fingerprint);
        }
    }
}

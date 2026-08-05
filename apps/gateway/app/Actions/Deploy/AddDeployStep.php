<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\DeployStep;
use Illuminate\Support\Facades\DB;

final readonly class AddDeployStep
{
    public function handle(
        int $instanceId,
        string $title,
        string $command,
        int $timeoutSeconds,
        ?int $order = null,
        ?int $retention = null,
    ): DeployStep {
        return DB::transaction(function () use (
            $instanceId,
            $title,
            $command,
            $timeoutSeconds,
            $order,
            $retention,
        ): DeployStep {
            $nextOrder =
                (int) DeployStep::query()
                    ->where('instance_id', $instanceId)
                    ->max('sort_order') + 1;
            $targetOrder = max(1, min($order ?? $nextOrder, $nextOrder));

            DeployStep::query()
                ->where('instance_id', $instanceId)
                ->where('sort_order', '>=', $targetOrder)
                ->orderByDesc('sort_order')
                ->each(function (DeployStep $step): void {
                    $step->forceFill(['sort_order' => $step->sort_order + 1])->save();
                });

            return DeployStep::query()->create([
                'instance_id' => $instanceId,
                'title' => $title,
                'command' => $command,
                'sort_order' => $targetOrder,
                'timeout_seconds' => $timeoutSeconds,
                'retention' => $retention,
            ]);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\AppSetupStep;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class AddAppSetupStep
{
    public function handle(
        int $instanceId,
        string $command,
        int $timeoutSeconds = AppSetupStep::DEFAULT_TIMEOUT_SECONDS,
        ?int $beforeStepId = null,
        ?int $afterStepId = null,
    ): AppSetupStep {
        return DB::transaction(function () use (
            $instanceId,
            $command,
            $timeoutSeconds,
            $beforeStepId,
            $afterStepId,
        ): AppSetupStep {
            $steps = AppSetupStep::query()->where('instance_id', $instanceId);

            if ($beforeStepId !== null) {
                $anchor = (clone $steps)->find($beforeStepId);

                if (! $anchor instanceof AppSetupStep) {
                    throw new InvalidArgumentException("Setup step #{$beforeStepId} was not found.");
                }

                $sortOrder = $anchor->sort_order;
                $steps->where('sort_order', '>=', $sortOrder)->increment('sort_order');
            } elseif ($afterStepId !== null) {
                $anchor = (clone $steps)->find($afterStepId);

                if (! $anchor instanceof AppSetupStep) {
                    throw new InvalidArgumentException("Setup step #{$afterStepId} was not found.");
                }

                $sortOrder = $anchor->sort_order + 1;
                $steps->where('sort_order', '>=', $sortOrder)->increment('sort_order');
            } else {
                $sortOrder = ((clone $steps)->max('sort_order') ?? 0) + 1;
            }

            return AppSetupStep::query()->create([
                'instance_id' => $instanceId,
                'sort_order' => $sortOrder,
                'command' => $command,
                'timeout_seconds' => $timeoutSeconds,
            ]);
        });
    }
}

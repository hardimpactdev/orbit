<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\AppDevelopmentSetupStep;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class AddAppDevelopmentSetupStep
{
    public function handle(
        int $appId,
        string $command,
        int $timeoutSeconds = 600,
        ?int $beforeStepId = null,
        ?int $afterStepId = null,
    ): AppDevelopmentSetupStep {
        if ($beforeStepId !== null && $afterStepId !== null) {
            throw new InvalidArgumentException('Both before and after cannot be supplied.');
        }

        return DB::transaction(static function () use (
            $appId,
            $command,
            $timeoutSeconds,
            $beforeStepId,
            $afterStepId,
        ): AppDevelopmentSetupStep {
            $steps = AppDevelopmentSetupStep::query()->where('app_id', $appId);
            if ($beforeStepId !== null || $afterStepId !== null) {
                $anchor = (clone $steps)->find($beforeStepId ?? $afterStepId);
                if (! $anchor instanceof AppDevelopmentSetupStep) {
                    throw new InvalidArgumentException('Setup step was not found.');
                }
                $order = $anchor->sort_order + ($afterStepId !== null ? 1 : 0);
                $all = (clone $steps)->orderBy('sort_order')->orderBy('id')->get();
                $all->each(static fn (AppDevelopmentSetupStep $step): int => $step->increment('sort_order', 1_000_000));
                foreach ($all as $index => $step) {
                    $step->update(['sort_order' => $index + 1 + ($index >= ($order - 1) ? 1 : 0)]);
                }
            }
            $order ??= ((clone $steps)->max('sort_order') ?? 0) + 1;

            return AppDevelopmentSetupStep::query()->create([
                'app_id' => $appId,
                'sort_order' => $order,
                'command' => $command,
                'timeout_seconds' => $timeoutSeconds,
            ]);
        });
    }
}

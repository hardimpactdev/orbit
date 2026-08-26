<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\AppDevelopmentSetupStep;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class UpdateAppDevelopmentSetupStep
{
    public function handle(
        AppDevelopmentSetupStep $step,
        ?string $command,
        ?int $timeoutSeconds,
        ?int $beforeStepId,
        ?int $afterStepId,
    ): AppDevelopmentSetupStep {
        if ($beforeStepId !== null && $afterStepId !== null) {
            throw new InvalidArgumentException('Both before and after cannot be supplied.');
        }
        return DB::transaction(function () use (
            $step,
            $command,
            $timeoutSeconds,
            $beforeStepId,
            $afterStepId,
        ): AppDevelopmentSetupStep {
            if ($command !== null) {
                $step->command = $command;
            }
            if ($timeoutSeconds !== null) {
                $step->timeout_seconds = $timeoutSeconds;
            }
            $step->save();
            if ($beforeStepId !== null || $afterStepId !== null) {
                $anchor = AppDevelopmentSetupStep::query()
                    ->where('app_id', $step->app_id)
                    ->find($beforeStepId ?? $afterStepId);
                if (! $anchor instanceof AppDevelopmentSetupStep || $anchor->id === $step->id) {
                    throw new InvalidArgumentException('Setup step anchor was not found.');
                }
                $ordered = AppDevelopmentSetupStep::query()
                    ->where('app_id', $step->app_id)
                    ->orderBy('sort_order')->orderBy('id')->get()
                    ->all();
                $ordered = array_values(array_filter($ordered, fn (AppDevelopmentSetupStep $candidate): bool => $candidate->id !== $step->id));
                $anchorIndex = array_search($anchor->id, array_map(fn (AppDevelopmentSetupStep $candidate): int => $candidate->id, $ordered), true);
                if ($anchorIndex === false) {
                    throw new \InvalidArgumentException('Setup step anchor was not found.');
                }
                $insertAt = $anchorIndex + ($afterStepId !== null ? 1 : 0);
                array_splice($ordered, $insertAt, 0, [$step]);
                $orderedIds = array_map(fn (AppDevelopmentSetupStep $candidate): int => $candidate->id, $ordered);
                AppDevelopmentSetupStep::query()->where('app_id', $step->app_id)->increment('sort_order', 1000000);
                foreach ($orderedIds as $index => $candidateId) {
                    AppDevelopmentSetupStep::query()->whereKey($candidateId)->update(['sort_order' => $index + 1]);
                }
                return $step->refresh();
            }
            $step->save();

            return $step->refresh();
        });
    }
}

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

        /** @var AppDevelopmentSetupStep $updated */
        $updated = DB::transaction(static function () use (
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
                /** @var list<int> $orderedIds */
                $orderedIds = AppDevelopmentSetupStep::query()
                    ->where('app_id', $step->app_id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();
                $orderedIds = array_values(array_filter(
                    $orderedIds,
                    static fn (int $candidateId): bool => $candidateId !== $step->id,
                ));
                $anchorIndex = array_search(
                    $anchor->id,
                    $orderedIds,
                    strict: true,
                );
                if ($anchorIndex === false) {
                    throw new \InvalidArgumentException('Setup step anchor was not found.');
                }
                $insertAt = $anchorIndex + ($afterStepId !== null ? 1 : 0);
                array_splice($orderedIds, offset: $insertAt, length: 0, replacement: [$step->id]);
                AppDevelopmentSetupStep::query()->where('app_id', $step->app_id)->increment('sort_order', 1_000_000);
                foreach ($orderedIds as $index => $candidateId) {
                    AppDevelopmentSetupStep::query()
                        ->whereKey($candidateId)
                        ->update(['sort_order' => $index + 1]);
                }

                return $step->refresh();
            }

            return $step->refresh();
        });

        return $updated;
    }
}

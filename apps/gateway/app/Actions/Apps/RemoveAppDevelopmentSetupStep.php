<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\AppDevelopmentSetupStep;
use Illuminate\Support\Facades\DB;

final readonly class RemoveAppDevelopmentSetupStep
{
    public function handle(AppDevelopmentSetupStep $step): void
    {
        DB::transaction(static function () use ($step): void {
            /** @var list<int> $remainingIds */
            $remainingIds = AppDevelopmentSetupStep::query()
                ->where('app_id', $step->app_id)
                ->where('id', '!=', $step->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            AppDevelopmentSetupStep::query()->whereIn('id', $remainingIds)->increment('sort_order', 1_000_000);
            $step->delete();
            foreach ($remainingIds as $index => $candidateId) {
                AppDevelopmentSetupStep::query()
                    ->whereKey($candidateId)
                    ->update(['sort_order' => $index + 1]);
            }
        });
    }
}

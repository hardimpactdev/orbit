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
            $remaining = AppDevelopmentSetupStep::query()
                ->where('app_id', $step->app_id)
                ->where('id', '!=', $step->id)
                ->orderBy('sort_order')->orderBy('id')->get();
            $remaining->each(static fn (AppDevelopmentSetupStep $candidate): int => $candidate->increment('sort_order', 1_000_000));
            $step->delete();
            foreach ($remaining as $index => $candidate) {
                $candidate->update(['sort_order' => $index + 1]);
            }
        });
    }
}

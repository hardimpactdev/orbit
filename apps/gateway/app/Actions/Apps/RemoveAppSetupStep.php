<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\AppSetupStep;
use Illuminate\Support\Facades\DB;

final readonly class RemoveAppSetupStep
{
    public function handle(AppSetupStep $step): void
    {
        DB::transaction(function () use ($step): void {
            $sortOrder = $step->sort_order;
            $appId = $step->app_id;

            $step->delete();

            AppSetupStep::query()
                ->where('app_id', $appId)
                ->where('sort_order', '>', $sortOrder)
                ->decrement('sort_order');
        });
    }
}

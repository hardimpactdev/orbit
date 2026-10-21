<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Orbit runs no test impact analysis, so the Gateway-owned setup operation `@orbit/tia-baseline` is gone. A stored
 * step that names it would now run as a shell command and fail setup. The steps go, and the rest of each list keeps
 * its order.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $steps = DB::table('project_lifecycle_steps')->where('command', '@orbit/tia-baseline');
            $lists = (clone $steps)->select(['project_id', 'phase'])->distinct()->get();
            $steps->delete();

            foreach ($lists as $list) {
                $remaining = DB::table('project_lifecycle_steps')->where('project_id', $list->project_id)
                    ->where('phase', $list->phase)->orderBy('position')->pluck('id');
                // Ascending order frees each lower position before a later step takes it.
                foreach ($remaining as $position => $id) {
                    DB::table('project_lifecycle_steps')->where('id', $id)->update(['position' => $position]);
                }
            }
        });
    }
};

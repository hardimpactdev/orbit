<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Task insight records stored before the handoff numbers lack their facts, and the totals lack the numbers. Both are derived from
     * the source tables, so they go: until the next refresh stores totals, a report comes from the source tables, and that refresh
     * records every ended group of the trend again (docs/reference/tasks.md#freshness).
     */
    public function up(): void
    {
        DB::table('task_insight_totals')->delete();
        DB::table('task_insight_records')->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the refresh rebuilds both tables.
    }
};

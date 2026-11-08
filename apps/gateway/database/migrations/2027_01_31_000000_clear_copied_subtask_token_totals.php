<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Old reads copied cumulative thread usage into linked subtasks. Those totals are unknown. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')->whereNotNull('parent_id')
            ->whereNotNull('implementer_agent_thread_id')
            ->update(['tokens' => null]);
    }
};

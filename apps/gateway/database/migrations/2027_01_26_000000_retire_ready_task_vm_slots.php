<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The ready slots of the slot pool before slot numbers (a stopped VM kept for a Project, with no group) have no path any more, and they hold
 * capacity. They go to the task VM destroy, which the host sweep starts for each row in `destroying` without a group.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_vms')->whereNull('slot_number')->whereNotNull('slot_project_id')->whereNull('group_id')->where('state', 'parked')
            ->update(['state' => 'destroying', 'updated_at' => now()]);
    }
};

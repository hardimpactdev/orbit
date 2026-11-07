<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A release that deleted a task VM's workspace Instance left its group pointing at it, so the group's cancel refused it as no longer owned
 * (group 3943). The release now unbinds the group; this unbinds the groups it left behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')->where('taskable_type', 'instance')->whereNotIn('taskable_id', DB::table('instances')->select('id'))
            ->update(['taskable_type' => null, 'taskable_id' => null]);
    }
};

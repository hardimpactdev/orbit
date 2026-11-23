<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * task:complete recorded how a group's watched pull request ended without its state, so the group kept the state "open". Copy the
 * recorded ending into the state of each such group.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tasks', 'watched_pr_state') || ! Schema::hasColumn('tasks', 'watched_pr_completion')) {
            return;
        }
        DB::table('tasks')->whereNull('parent_id')->whereIn('watched_pr_completion', ['merged', 'closed'])
            ->where(fn ($query) => $query->whereNull('watched_pr_state')->orWhereColumn('watched_pr_state', '<>', 'watched_pr_completion'))
            ->update(['watched_pr_state' => DB::raw('watched_pr_completion')]);
    }
};

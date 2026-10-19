<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::table('task_checks')->where('kind', 'base_merge')->where('status', 'running')->exists()) {
                throw new RuntimeException('A base_merge check is running. Let it finish before deploying.');
            }

            if (! Schema::hasColumn('task_reviewed_commits', 'inherited_approval_sha')) {
                Schema::table('task_reviewed_commits', function (Blueprint $table): void {
                    $table->string('inherited_approval_sha', 40)->nullable();
                });
            }

            DB::table('task_workspace_leases')->whereIn('status', ['queued', 'held'])
                ->where(static fn (Builder $lease): Builder => $lease->where('kind', 'main_merge')
                    ->orWhereIn('check_id', DB::table('task_checks')->where('kind', 'base_merge')->select('id')))
                ->update(['status' => 'cancelled', 'active_instance_id' => null, 'released_at' => now(), 'updated_at' => now()]);
            DB::table('task_checks')->where('kind', 'base_merge')->delete();

            foreach (DB::table('task_reviewed_commits')->where('source', 'base_merge')->orderBy('id')->get() as $reviewed) {
                $approvals = DB::table('task_comments')->where('task_group_id', $reviewed->task_id)
                    ->where('task_id', $reviewed->review_task_id)->where('type', 'approved')->whereNotNull('commit_sha')
                    ->whereIn('commit_sha', DB::table('task_reviewed_commits')->where('task_id', $reviewed->task_id)->select('sha'))
                    ->distinct()->pluck('commit_sha');
                DB::table('task_reviewed_commits')->where('id', $reviewed->id)
                    ->update(['source' => 'orbit_push', 'inherited_approval_sha' => $approvals->count() === 1 ? $approvals->sole() : null]);
            }

            DB::table('tasks')->where('fixup_problem', 'base-merge-review')->update(['fixup_problem' => null]);
        });
    }
};

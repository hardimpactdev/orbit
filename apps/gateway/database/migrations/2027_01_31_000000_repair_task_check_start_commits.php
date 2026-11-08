<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The old check result stored its candidate HEAD as an unrecorded subtask start.
        // Its first check still holds the admitted base. Repair only that exact signature.
        DB::table('tasks')->whereNotNull('subtask_start_commit')->orderBy('id')->each(function (object $task): void {
            $check = DB::table('task_checks')->where('task_id', $task->id)->orderBy('id')->first();
            if ($check === null || $check->head_before !== $task->subtask_start_commit) {
                return;
            }
            $evidence = json_decode($check->deliverable_evidence ?? 'null', true);
            $base = is_array($evidence) ? ($evidence['start'] ?? null) : null;
            if (! is_string($base) || preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $base) !== 1 || $base === $task->subtask_start_commit) {
                return;
            }
            DB::table('tasks')->where('id', $task->id)->where('subtask_start_commit', $check->head_before)->update(['subtask_start_commit' => $base]);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a task group's pull request was opened on GitHub (docs/reference/tasks.md#task-insights), and the indexes the pull request
 * lookup of the task list reads (docs/reference/tasks.md#list-tasks). Expand only: an existing group keeps a null time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestamp('pr_opened_at')->nullable();
            $table->rawIndex('lower(pr_url)', 'tasks_pr_url_lower_index');
            $table->rawIndex('lower(watched_pr_url)', 'tasks_watched_pr_url_lower_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_watched_pr_url_lower_index');
            $table->dropIndex('tasks_pr_url_lower_index');
            $table->dropColumn('pr_opened_at');
        });
    }
};

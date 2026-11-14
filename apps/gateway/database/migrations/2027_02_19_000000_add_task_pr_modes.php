<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task group modes (docs/reference/tasks.md#task-group-modes). Every column is nullable with no default: a null mode inherits,
 * and a default would put a value on subtask rows, which the model refuses. The previous release ignores these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->string('pr_mode', 16)->nullable();
            $table->json('publish_refusal')->nullable();
        });
        Schema::table('projects', static function (Blueprint $table): void {
            $table->string('task_pr_mode', 16)->nullable();
        });
        Schema::table('task_checks', static function (Blueprint $table): void {
            $table->string('pr_mode', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_checks', static function (Blueprint $table): void {
            $table->dropColumn('pr_mode');
        });
        Schema::table('projects', static function (Blueprint $table): void {
            $table->dropColumn('task_pr_mode');
        });
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn(['pr_mode', 'publish_refusal']);
        });
    }
};

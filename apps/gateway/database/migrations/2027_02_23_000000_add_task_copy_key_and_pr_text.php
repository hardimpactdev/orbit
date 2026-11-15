<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task copy's own key for Orbit (docs/reference/development-containers.md#the-copy-key), the reviewer's pull request text on a
 * check (docs/reference/tasks.md#the-reviewers-pull-request-text), and the issue a group closes. Every column is nullable with no
 * default, so the previous release ignores them and a code rollback leaves them unread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_runtimes', static function (Blueprint $table): void {
            $table->string('agent_key_hash', 64)->nullable()->unique();
        });
        Schema::table('task_checks', static function (Blueprint $table): void {
            $table->string('pr_title', 256)->nullable();
            $table->text('pr_body')->nullable();
            $table->unsignedBigInteger('pr_text_operation_id')->nullable();
        });
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->unsignedInteger('issue_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn('issue_number');
        });
        Schema::table('task_checks', static function (Blueprint $table): void {
            $table->dropColumn(['pr_title', 'pr_body', 'pr_text_operation_id']);
        });
        Schema::table('instance_runtimes', static function (Blueprint $table): void {
            $table->dropUnique(['agent_key_hash']);
            $table->dropColumn('agent_key_hash');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider, model and effort of each role of a task copy turn: on the group, as the Project's default, and the provider instance
 * a copy thread started on (docs/reference/tasks.md#copy-turn-agents). Every column is new and nullable, so a release that does
 * not know them keeps working on the migrated database. The group's task VM columns `implementer_model` and `reviewer_model` stay as
 * they are; a copy never reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('implementer_agent_provider', 32)->nullable();
            $table->string('implementer_agent_model', 128)->nullable();
            $table->string('implementer_agent_effort', 16)->nullable();
            $table->string('reviewer_agent_provider', 32)->nullable();
            $table->string('reviewer_agent_model', 128)->nullable();
            $table->string('reviewer_agent_effort', 16)->nullable();
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('task_implementer_provider', 32)->nullable();
            $table->string('task_implementer_model', 128)->nullable();
            $table->string('task_implementer_effort', 16)->nullable();
            $table->string('task_reviewer_provider', 32)->nullable();
            $table->string('task_reviewer_model', 128)->nullable();
            $table->string('task_reviewer_effort', 16)->nullable();
        });
        Schema::table('agent_threads', function (Blueprint $table): void {
            $table->string('provider_instance', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_threads', function (Blueprint $table): void {
            $table->dropColumn('provider_instance');
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['task_implementer_provider', 'task_implementer_model', 'task_implementer_effort',
                'task_reviewer_provider', 'task_reviewer_model', 'task_reviewer_effort']);
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['implementer_agent_provider', 'implementer_agent_model', 'implementer_agent_effort',
                'reviewer_agent_provider', 'reviewer_agent_model', 'reviewer_agent_effort']);
        });
    }
};

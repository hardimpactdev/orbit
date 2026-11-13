<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops Instance registration columns that no code reads or writes, and four tables that lost their last reader
 * (task GitHub review consumptions and feedback in #1568; fleet rollout records in #2634). The release before this
 * one already stopped writing the columns, so a code rollback to it does not need them.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array Columns = [
        'registration_primary',
        'registration_include_worktrees',
        'registration_repository_url',
        'registration_repository_identity',
        'registration_default_branch',
        'registration_inferred_slug',
        'registration_inferred_root',
        'registration_worktree_paths',
        'registration_relocation_state',
        'registration_authoritative_path',
        'registration_route_provenance',
        'registration_source_device',
        'registration_source_inode',
    ];

    public function up(): void
    {
        // Each step checks first, so a run that stopped half way runs again.
        $columns = array_values(array_filter(self::Columns, static fn (string $column): bool => Schema::hasColumn('instances', $column)));
        if ($columns !== []) {
            Schema::table('instances', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }

        Schema::dropIfExists('fleet_rollout_nodes');
        Schema::dropIfExists('fleet_rollouts');
        Schema::dropIfExists('task_github_review_consumptions');
        Schema::dropIfExists('task_github_review_feedback');
    }

    /** Restores each structure empty: the dropped values are not kept. */
    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->boolean('registration_primary')->default(false);
            $table->boolean('registration_include_worktrees')->default(false);
            $table->text('registration_repository_url')->nullable();
            $table->string('registration_repository_identity')->nullable();
            $table->string('registration_default_branch')->nullable();
            $table->string('registration_inferred_slug')->nullable();
            $table->string('registration_inferred_root')->nullable();
            $table->text('registration_worktree_paths')->nullable();
            $table->string('registration_relocation_state')->nullable();
            $table->text('registration_authoritative_path')->nullable();
            $table->string('registration_route_provenance')->nullable();
            $table->integer('registration_source_device')->nullable();
            $table->integer('registration_source_inode')->nullable();
        });

        Schema::create('task_github_review_consumptions', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('group_id');
            $table->string('repository');
            $table->integer('pull_request_number');
            $table->integer('review_id');
            $table->string('identity');
            $table->integer('operator_task_id')->nullable();
            $table->integer('fixup_id')->nullable();
            $table->string('digest');
            $table->text('packet');
            $table->dateTime('created_at');
            $table->foreign(['group_id'])->references(['id'])->on('tasks')->onDelete('cascade');
            $table->foreign(['fixup_id'])->references(['id'])->on('tasks')->onDelete('set null');
            $table->unique(['group_id', 'repository', 'pull_request_number', 'review_id'], 'task_github_review_consumption_source');
        });

        Schema::create('task_github_review_feedback', function (Blueprint $table): void {
            $table->integer('group_id');
            $table->text('causes');
            $table->primary(['group_id']);
            $table->foreign(['group_id'])->references(['id'])->on('tasks')->onDelete('cascade');
        });

        Schema::create('fleet_rollouts', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('gateway_release_id')->nullable()->unique();
            $table->string('commit')->index();
            $table->string('status')->index();
            $table->text('desired_state');
            $table->text('order');
            $table->integer('halted_node_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->text('alert')->nullable();
            $table->text('notices')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->foreign(['gateway_release_id'])->references(['id'])->on('gateway_releases')->onDelete('set null');
            $table->foreign(['halted_node_id'])->references(['id'])->on('nodes')->onDelete('set null');
        });

        Schema::create('fleet_rollout_nodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('fleet_rollout_id');
            $table->integer('node_id')->nullable();
            $table->string('node_name');
            $table->integer('position');
            $table->string('outcome');
            $table->string('step')->nullable();
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->text('evidence')->nullable();
            $table->string('cli_version')->nullable();
            $table->string('agent_version')->nullable();
            $table->string('footprint_digest')->nullable();
            $table->integer('attempts')->default(0);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->foreign(['fleet_rollout_id'])->references(['id'])->on('fleet_rollouts')->onDelete('cascade');
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('set null');
            $table->unique(['fleet_rollout_id', 'node_id']);
        });
    }
};

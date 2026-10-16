<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Retire annotations. Rollback restores empty schema, never deleted notes or tasks. */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $presets = DB::table('processes')->whereIn('runtime_config->preset', ['annotator', 'agentation-mcp', 'antigravity-watch'])->orderBy('id')->pluck('id');
            if ($presets->isNotEmpty()) {
                throw new RuntimeException('Cannot remove annotations: retired preset Processes remain: '.$presets->implode(', ').'. Destroy these Processes through Orbit on the current release, then deploy again. Ordinary hand-made Processes must remain.');
            }

            $groups = DB::table('tasks')->whereNull('parent_id')->where('execution_mode', 'existing_thread')->pluck('id');
            $outside = DB::table('tasks')->where('type', 'annotation')->where(function ($query) use ($groups): void {
                $query->whereNull('parent_id')->orWhereNotIn('parent_id', $groups);
            })->exists();
            $mixed = DB::table('tasks')->whereIn('parent_id', $groups)->where(function ($query): void {
                $query->whereNull('type')->orWhere('type', '!=', 'annotation');
            })->exists();
            $unrelated = DB::table('annotations')->leftJoin('tasks', 'tasks.id', '=', 'annotations.task_id')->where(function ($query) use ($groups): void {
                $query->whereNull('tasks.parent_id')->orWhereNotIn('tasks.parent_id', $groups);
            })->exists();
            if ($outside || $mixed || $unrelated || DB::table('task_vms')->whereIn('group_id', $groups)->exists()) {
                throw new RuntimeException('Cannot remove annotations: annotation tasks have mixed ownership or a Task VM. Resolve the task relationships on the current release before deploying. No data was removed.');
            }

            $exposed = DB::table('instances')
                ->join('tasks as annotation_groups', function ($join) use ($groups): void {
                    $join->on('annotation_groups.taskable_id', '=', 'instances.id')
                        ->where('annotation_groups.taskable_type', 'instance')->whereIn('annotation_groups.id', $groups);
                })
                ->whereExists(function ($survivor) use ($groups): void {
                    $survivor->selectRaw('1')->from('tasks as surviving_tasks')
                        ->whereNull('surviving_tasks.parent_id')->whereNotIn('surviving_tasks.id', $groups)
                        ->whereColumn('surviving_tasks.project_id', 'instances.project_id')
                        ->where(function ($candidate): void {
                            $candidate->whereColumn('surviving_tasks.taskable_id', 'instances.id')
                                ->orWhere(function ($named): void {
                                    $named->whereNull('surviving_tasks.taskable_id')
                                        ->whereRaw("instances.name = 'task-' || surviving_tasks.id")
                                        ->whereColumn('instances.branch_override', 'instances.name');
                                });
                        });
                })->orderBy('instances.id')->distinct()->pluck('instances.id');
            if ($exposed->isNotEmpty()) {
                throw new RuntimeException('Cannot remove annotations: user Instances have ambiguous task workspace ownership: '.$exposed->implode(', ').'. Preserve their associations and resolve ownership on the current release before deploying. No data was removed.');
            }

            Schema::drop('annotations');
            Schema::drop('annotation_events');
            foreach ($groups->chunk(500) as $ids) {
                DB::table('tasks')->whereIn('parent_id', $ids)->delete();
                DB::table('tasks')->whereIn('id', $ids)->delete();
            }
            Schema::drop('annotation_port_assignments');
            Schema::table('instances', function (Blueprint $table): void {
                $table->dropUnique(['node_id', 'agentation_port']);
                $table->dropUnique(['node_id', 'annotator_port']);
            });
            DB::statement('ALTER TABLE instances DROP COLUMN agentation_port');
            DB::statement('ALTER TABLE instances DROP COLUMN annotator_port');
            DB::statement('ALTER TABLE processes DROP COLUMN endpoint_withdrawal_started_at');
            DB::statement('ALTER TABLE processes DROP COLUMN endpoint_withdrawn_at');
            Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex('tasks_target_thread_id_index'));
            DB::statement('ALTER TABLE tasks DROP COLUMN target_thread_id');
            Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex('tasks_execution_mode_index'));
            DB::statement('ALTER TABLE tasks DROP COLUMN execution_mode');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('target_thread_id', 128)->nullable()->index();
            $table->string('execution_mode')->nullable()->index();
        });
        DB::table('tasks')->whereNull('parent_id')->update(['execution_mode' => 'managed']);
        Schema::table('instances', function (Blueprint $table): void {
            $table->unsignedInteger('agentation_port')->nullable();
            $table->unsignedInteger('annotator_port')->nullable();
            $table->unique(['node_id', 'agentation_port']);
            $table->unique(['node_id', 'annotator_port']);
        });
        Schema::table('processes', function (Blueprint $table): void {
            $table->timestamp('endpoint_withdrawal_started_at')->nullable();
            $table->timestamp('endpoint_withdrawn_at')->nullable();
        });
        Schema::create('annotation_port_assignments', function (Blueprint $table): void {
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->unsignedInteger('port');
            $table->unique(['node_id', 'port']);
            $table->primary(['instance_id', 'node_id', 'kind']);
        });
        Schema::create('annotations', function (Blueprint $table): void {
            $table->string('id', 128)->primary();
            $table->foreignId('instance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->unique()->constrained()->restrictOnDelete();
            $table->string('delivery')->default('queued');
            $table->json('context');
            $table->text('error')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('submission_order')->default(0);
            $table->uuid('command_id');
            $table->uuid('message_id');
            $table->json('command')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();
            $table->index(['instance_id', 'created_at']);
        });
        Schema::create('annotation_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }
};

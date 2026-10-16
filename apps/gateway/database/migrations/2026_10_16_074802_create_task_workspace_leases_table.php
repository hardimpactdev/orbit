<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_workspace_leases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('instance_id')->index();
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedBigInteger('active_instance_id')->nullable()->unique();
            $table->string('kind');
            $table->string('owner');
            $table->string('status')->default('queued');
            $table->unsignedBigInteger('thread_id')->nullable();
            $table->unsignedBigInteger('check_id')->nullable();
            $table->integer('pid')->nullable();
            $table->string('process_started')->nullable();
            $table->string('process_head')->nullable();
            $table->string('process_tree')->nullable();
            $table->string('previous_turn_id')->nullable();
            $table->string('dispatch_key')->nullable();
            $table->string('dispatch_hash')->nullable();
            $table->longText('dispatch_message')->nullable();
            $table->boolean('dispatch_generated')->default(false);
            $table->boolean('dispatch_completed')->default(false);
            $table->boolean('review_baseline_required')->default(false);
            $table->boolean('saw_working')->default(false);
            $table->boolean('adopted')->default(false);
            $table->boolean('remote_uncertain')->default(false);
            $table->boolean('orbit_mutation')->default(false);
            $table->timestamp('dispatch_started_at')->nullable();
            $table->integer('gateway_pid')->nullable();
            $table->string('gateway_started')->nullable();
            $table->string('head_before')->nullable();
            $table->string('tree_before')->nullable();
            $table->string('head_after')->nullable();
            $table->string('tree_after')->nullable();
            $table->timestamp('acquired_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_workspace_leases');
    }
};

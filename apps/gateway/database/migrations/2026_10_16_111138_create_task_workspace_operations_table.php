<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_workspace_leases', function (Blueprint $table): void {
            $table->index(['instance_id', 'status']);
            $table->string('gateway_host')->nullable();
            $table->string('gateway_boot')->nullable();
            $table->boolean('journaled')->default(false);
            $table->boolean('completion_pending')->default(false);
            $table->string('check_run_id')->nullable();
            $table->string('actor_kind')->nullable();
            $table->string('actor_owner')->nullable();
        });
        Schema::create('task_workspace_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lease_id')->constrained('task_workspace_leases')->restrictOnDelete();
            $table->string('operation', 32)->unique();
            $table->string('binding', 64);
            $table->json('connection');
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->index(['lease_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_workspace_operations');
        Schema::table('task_workspace_leases', function (Blueprint $table): void {
            $table->dropIndex(['instance_id', 'status']);
            $table->dropColumn(['gateway_host', 'gateway_boot', 'journaled', 'completion_pending', 'check_run_id', 'actor_kind', 'actor_owner']);
        });
    }
};

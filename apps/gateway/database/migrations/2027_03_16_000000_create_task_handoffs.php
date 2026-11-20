<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The handoffs of the task loop and the part table of a task check (docs/reference/tasks.md#handoffs). Expand only: a group that left
 * backlog before keeps `handoffs_gated` false and finishes without the gates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->string('type');
            $table->json('receipt')->nullable();
            $table->boolean('rejected')->default(false);
            $table->text('rejection_reason')->default('');
            $table->foreignId('task_check_id')->nullable()->constrained('task_checks')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['task_id', 'type', 'id']);
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->boolean('handoffs_gated')->default(false);
        });
        Schema::table('task_checks', function (Blueprint $table): void {
            $table->json('parts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_checks', function (Blueprint $table): void {
            $table->dropColumn('parts');
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('handoffs_gated');
        });
        Schema::dropIfExists('task_handoffs');
    }
};

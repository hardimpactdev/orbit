<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stored [task insights](docs/reference/tasks.md#task-insights): one record per ended task group and the report totals the
 * endpoint reads. Expand only: the previous release neither reads nor writes these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_insight_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->timestamp('ended_at')->index();
            $table->json('facts');
            $table->timestamp('recorded_at');
        });
        Schema::create('task_insight_totals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('period', 8);
            $table->timestamp('computed_at');
            $table->json('payload');
            $table->unique(['project_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_insight_totals');
        Schema::dropIfExists('task_insight_records');
    }
};

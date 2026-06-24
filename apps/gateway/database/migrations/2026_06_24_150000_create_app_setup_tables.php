<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_setup_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->text('command');
            $table->unsignedInteger('timeout_seconds')->default(600);
            $table->timestamps();

            $table->unique(['app_id', 'sort_order']);
        });

        Schema::create('app_setup_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('status');
            $table->string('step_set_hash')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'status']);
        });

        Schema::create('app_setup_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_setup_run_id')->constrained('app_setup_runs')->cascadeOnDelete();
            $table->foreignId('app_setup_step_id')->nullable()->constrained('app_setup_steps')->nullOnDelete();
            $table->text('command');
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['app_setup_run_id', 'app_setup_step_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_setup_run_steps');
        Schema::dropIfExists('app_setup_runs');
        Schema::dropIfExists('app_setup_steps');
    }
};

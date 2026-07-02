<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orbit_agent_jobs', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('status')->index();
            $table->foreignId('target_node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignUuid('operation_run_id')->nullable()->constrained('operation_runs')->nullOnDelete();
            $table->json('payload');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['target_node_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orbit_agent_jobs');
    }
};

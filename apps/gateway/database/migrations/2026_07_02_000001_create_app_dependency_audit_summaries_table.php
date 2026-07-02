<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_dependency_audit_summaries', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('manager', 16);
            $table->string('status', 32);
            $table->unsignedInteger('danger_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->json('severity_counts')->nullable();
            $table->json('advisory_summary')->nullable();
            $table->timestamp('audited_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'manager']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_dependency_audit_summaries');
    }
};

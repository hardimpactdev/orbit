<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (! Schema::hasTable('operation_cleanup_reviews')) {
                Schema::create('operation_cleanup_reviews', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('release_id')->constrained('gateway_releases')->cascadeOnDelete();
                    $table->string('repository');
                    $table->string('kind');
                    $table->unsignedInteger('version');
                    // A null brief records that this release removed a previously fenced version mapping.
                    $table->text('brief')->nullable();
                    // The stopped releases a rollback could start when this row was recorded. Filing waits until all are gone.
                    $table->json('parked');
                    $table->timestamp('processed_at')->nullable();
                    $table->unique(['release_id', 'kind', 'version']);
                });
            }
            if (! Schema::hasTable('operation_cleanup_tasks')) {
                Schema::create('operation_cleanup_tasks', function (Blueprint $table): void {
                    $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                    $table->string('kind');
                    $table->unsignedInteger('version');
                    // Keep the filing receipt even if the operator deletes the Task.
                    $table->unsignedBigInteger('task_id');
                    $table->char('filed_sha', 40);
                    $table->char('removed_sha', 40)->nullable();
                    $table->primary(['project_id', 'kind', 'version']);
                });
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::dropIfExists('operation_cleanup_tasks');
            Schema::dropIfExists('operation_cleanup_reviews');
        });
    }
};

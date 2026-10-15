<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Long operations and their steps (docs/reference/operations.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', static function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 64);
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            // The target's serving Node, so a list returns only operations the caller can access.
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            // Held from the start request to the terminal status: one operation per target.
            $table->string('lock_key', 64)->nullable()->unique();
            $table->json('input');
            $table->json('state')->nullable();
            $table->json('result')->nullable();
            $table->string('status', 16);
            $table->unsignedBigInteger('sequence')->default(1);
            $table->string('current_step', 128)->nullable();
            $table->string('failed_step', 128)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('worker', 255)->nullable();
            $table->string('triggered_by', 255)->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['target_type', 'target_id', 'id']);
        });

        Schema::create('operation_steps', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('name', 128);
            $table->string('status', 16);
            $table->boolean('resumable');
            $table->unsignedBigInteger('sequence');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('output')->nullable();
            $table->boolean('output_truncated')->default(false);
            $table->timestamps();

            $table->unique(['operation_id', 'name']);
            $table->unique(['operation_id', 'position']);
            $table->index(['operation_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_steps');
        Schema::dropIfExists('operations');
    }
};

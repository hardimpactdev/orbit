<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The steps of an Operation that runs on the Gateway state machine (ADR 0271; docs/reference/gateway-engine.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_engine_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
            $table->string('name', 100);
            // 0 for a step that runs on the Gateway itself.
            $table->unsignedBigInteger('node_id');
            $table->string('action', 64);
            $table->string('target', 128);
            $table->json('after');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('sends')->default(0);
            $table->unsignedInteger('budget_ms');
            $table->unsignedInteger('resend_ms');
            $table->unsignedBigInteger('first_sent_ms')->nullable();
            $table->unsignedBigInteger('resend_at_ms')->nullable();
            $table->unsignedBigInteger('done_ms')->nullable();
            $table->text('output')->nullable();
            $table->string('error_code', 128)->nullable();
            $table->unsignedInteger('late')->default(0);
            $table->timestamps();
            $table->unique(['operation_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_engine_steps');
    }
};

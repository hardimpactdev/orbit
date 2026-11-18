<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Managed grant revocation (docs/reference/conn.md#managed-grant-revocation): target records, revisions with their owed ACKs, and managed tokens. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conn_managed_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conn_environment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('environment_id')->unique();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->unsignedInteger('revision')->default(0);
            $table->char('credential_digest', 64);
            $table->timestamps();
            $table->index(['node_id', 'id']);
        });

        // One row per removal revision of a target. A row without `acknowledged_at` or `superseded_at` is owed.
        Schema::create('conn_managed_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conn_managed_target_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->unsignedInteger('revision');
            $table->json('edge_ids');
            $table->string('committed_at', 40);
            $table->string('notice_sent_at', 40)->nullable();
            $table->string('notice_suppressed_at', 40)->nullable();
            $table->string('ack_kind', 16)->nullable();
            $table->string('acknowledged_at', 40)->nullable();
            $table->string('notice_received_at', 40)->nullable();
            $table->string('fenced_at', 40)->nullable();
            $table->string('handlers_closed_at', 40)->nullable();
            $table->string('recovered_at', 40)->nullable();
            $table->string('superseded_at', 40)->nullable();
            $table->unique(['conn_managed_target_id', 'revision']);
        });

        Schema::create('conn_managed_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conn_managed_target_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->foreignId('caller_node_id')->constrained('nodes')->cascadeOnDelete();
            $table->unsignedBigInteger('node_access_id')->index();
            $table->char('token_digest', 64)->unique();
            $table->json('scopes');
            $table->timestamp('expires_at');
            $table->unsignedInteger('revoked_revision')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conn_managed_tokens');
        Schema::dropIfExists('conn_managed_revisions');
        Schema::dropIfExists('conn_managed_targets');
    }
};

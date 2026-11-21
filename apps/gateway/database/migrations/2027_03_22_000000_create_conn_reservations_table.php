<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A clone a Conn device reserved when its user opened a new thread, before they named it (docs/reference/conn.md#reserve-a-clone).
 * `state` is `open` until the finalize is admitted (`finalized`), the device releases it (`released`) or it expires (`expired`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conn_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conn_profile_id')->nullable()->constrained('conn_profiles')->nullOnDelete();
            $table->foreignId('conn_pairing_id')->nullable()->constrained('conn_pairings')->nullOnDelete();
            $table->foreignId('create_operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->foreignId('finalize_operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->foreignId('destroy_operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->string('state', 16);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['node_id', 'state']);
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conn_reservations');
    }
};

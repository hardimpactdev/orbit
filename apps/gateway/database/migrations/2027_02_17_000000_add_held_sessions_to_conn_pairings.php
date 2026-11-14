<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pairing the Gateway redeemed itself keeps the session it holds for its Node, encrypted, and the time it withdrew that session
 * and still owes its revoke on the server (docs/reference/conn.md#held-sessions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conn_pairings', function (Blueprint $table): void {
            $table->boolean('held')->default(false);
            $table->text('session')->nullable();
            $table->timestamp('session_expires_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->index(['node_id', 'conn_environment_id', 'held']);
        });
    }

    public function down(): void
    {
        Schema::table('conn_pairings', function (Blueprint $table): void {
            $table->dropIndex(['node_id', 'conn_environment_id', 'held']);
            $table->dropColumn(['held', 'session', 'session_expires_at', 'withdrawn_at']);
        });
    }
};

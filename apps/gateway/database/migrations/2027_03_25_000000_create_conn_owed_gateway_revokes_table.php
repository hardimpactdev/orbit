<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Gateway admin session of an unregistered host server, kept until the server revokes it (docs/reference/conn.md#unregister-a-host-server).
 * The row is hidden from every server list; only the owed-work reconcile reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conn_owed_gateway_revokes', function (Blueprint $table): void {
            $table->id();
            $table->string('environment_id');
            $table->string('url');
            $table->text('admin_session');
            $table->timestamp('admin_session_expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conn_owed_gateway_revokes');
    }
};

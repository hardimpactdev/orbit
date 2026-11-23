<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit and rate-limit records for Conn APNs push deliveries (docs/reference/conn.md#push-notifications).
 * Never stores device tokens, JWTs, or thread titles. Device row IDs are kept as plain integers so
 * invalid-token deletions preserve why a device was removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conn_push_sends', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 26);
            $table->unsignedBigInteger('conn_push_device_id');
            $table->string('environment_id', 255);
            $table->string('thread_id', 255);
            $table->string('apns_environment', 16);
            $table->string('push_type', 16);
            $table->boolean('is_summary')->default(false);
            $table->unsignedSmallInteger('apns_status');
            $table->string('apns_id', 64)->nullable();
            $table->string('apns_reason', 64)->nullable();
            $table->string('device_deleted_reason', 64)->nullable();
            $table->timestamp('created_at');

            $table->index('event_id');
            $table->index('created_at');
            $table->index(['conn_push_device_id', 'push_type', 'created_at'], 'conn_push_sends_device_window_idx');
            $table->index(
                ['conn_push_device_id', 'environment_id', 'thread_id', 'push_type', 'created_at'],
                'conn_push_sends_thread_window_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conn_push_sends');
    }
};

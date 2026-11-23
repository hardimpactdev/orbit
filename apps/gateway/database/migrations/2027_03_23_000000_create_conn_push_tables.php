<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push notification device registrations and attention events for Conn (docs/reference/conn.md#push-notifications).
 * Device tokens are stored encrypted; attention events are deduplicated by event_id and pruned after 7 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conn_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_id');
            $table->text('apns_token');
            $table->string('apns_environment', 16);
            $table->string('bundle_id', 255);
            $table->string('app_version', 64);
            $table->string('ios_version', 64);
            $table->string('locale', 32);
            $table->json('preferences');
            $table->timestamps();
            $table->unique(['node_id', 'device_id']);
        });

        Schema::create('conn_attention_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 26)->unique();
            $table->foreignId('conn_environment_id')->constrained()->cascadeOnDelete();
            $table->string('environment_id', 255);
            $table->string('thread_id', 255);
            $table->string('type', 32);
            $table->string('kind', 32)->nullable();
            $table->string('request_id', 255)->nullable();
            $table->string('thread_title', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index('created_at');
            $table->index(['conn_environment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conn_attention_events');
        Schema::dropIfExists('conn_push_devices');
    }
};

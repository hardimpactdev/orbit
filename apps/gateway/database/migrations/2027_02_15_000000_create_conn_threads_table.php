<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Conn thread index (docs/reference/conn.md#thread-index): one row per thread of a container T3 server, with no message text
 * beyond its title, and each server's last read. The rows go with their server's environment, which goes with its Instance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conn_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conn_environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $table->string('thread_id');
            $table->string('title');
            $table->string('state')->nullable();
            $table->boolean('needs_input')->default(false);
            $table->timestamp('last_user_message_at')->nullable();
            $table->timestamp('last_run_completed_at')->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();
            $table->unique(['conn_environment_id', 'thread_id']);
            $table->index('instance_id');
        });
        Schema::table('conn_environments', function (Blueprint $table): void {
            $table->timestamp('threads_read_at')->nullable();
            $table->string('threads_read_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conn_environments', function (Blueprint $table): void {
            $table->dropColumn(['threads_read_at', 'threads_read_error']);
        });
        Schema::dropIfExists('conn_threads');
    }
};

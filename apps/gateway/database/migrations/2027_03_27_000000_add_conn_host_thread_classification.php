<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conn_host_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conn_environment_id')->constrained()->cascadeOnDelete();
            $table->string('thread_id');
            $table->string('title');
            $table->string('state')->nullable();
            $table->boolean('needs_input')->default(false);
            $table->timestamp('last_user_message_at')->nullable();
            $table->timestamp('last_run_completed_at')->nullable();
            $table->unsignedBigInteger('orbit_project_id')->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();
            $table->unique(['conn_environment_id', 'thread_id']);
        });
        Schema::create('conn_thread_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conn_environment_id')->constrained()->cascadeOnDelete();
            $table->string('thread_id');
            $table->json('result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unique(['conn_environment_id', 'thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conn_thread_classifications');
        Schema::dropIfExists('conn_host_threads');
    }
};

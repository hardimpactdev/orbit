<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The commit that the idle release saved to the group's `task-<id>-idle` branch, which the next claim restores (docs/reference/tasks.md#release-an-idle-task-vm). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->string('idle_saved_commit')->nullable();
            $table->timestamp('idle_saved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn(['idle_saved_commit', 'idle_saved_at']);
        });
    }
};

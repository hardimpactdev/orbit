<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first failed create of a group's task copy: its Operation, failed step and error code, while the Gateway starts the create once
 * more (docs/reference/tasks.md#a-failed-create). Null when no retry is owed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->json('copy_create_failure')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('copy_create_failure');
        });
    }
};

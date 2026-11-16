<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The failed handoff check that a correction of a task copy's started subtask answered, so one check takes one correction
 * (docs/reference/tasks.md#correct-the-deliverables-of-a-started-subtask). The column is nullable with no default, so the previous
 * release ignores it and a code rollback leaves it unread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->unsignedBigInteger('copy_correction_check_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn('copy_correction_check_id');
        });
    }
};

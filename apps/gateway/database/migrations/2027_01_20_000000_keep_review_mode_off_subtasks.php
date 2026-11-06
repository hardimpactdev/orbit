<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The review mode belongs to the Task group alone: the model sets `manual` on a new group, and a subtask keeps none. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->string('review_mode', 16)->nullable()->default(null)->change();
        });
        DB::table('tasks')->whereNotNull('parent_id')->update(['review_mode' => null]);
    }

    public function down(): void
    {
        DB::table('tasks')->whereNull('review_mode')->update(['review_mode' => 'manual']);
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->string('review_mode', 16)->nullable(false)->default('manual')->change();
        });
    }
};

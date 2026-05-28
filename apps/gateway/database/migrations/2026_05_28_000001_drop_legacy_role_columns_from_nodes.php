<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['role', 'environment']);
        });
    }

    /**
     * Rollback recreates both columns as nullable even though `role` was originally
     * NOT NULL in 2026_05_01_210611_create_nodes_table.php. The legacy column is
     * being retired permanently; rolling back to its post-drop state needs to be
     * compatible with rows that have no legacy value to backfill.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('role')->nullable();
            $table->string('environment')->nullable();
        });
    }
};

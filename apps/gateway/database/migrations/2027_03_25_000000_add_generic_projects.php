<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A `generic` Project has no repository and may name the one Node it is about (projects.md#generic-projects):
 * the repository columns become nullable, and `node_id` holds the optional, unique Node association.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild(function (Blueprint $table): void {
            $table->text('repository_url')->nullable()->change();
            $table->text('repository_identity')->nullable()->change();
            $table->foreignId('node_id')->nullable()->unique()->constrained('nodes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('projects')->whereNull('repository_url')->exists()) {
            throw new RuntimeException('Remove every generic Project before rolling back: their rows have no repository.');
        }

        $this->rebuild(function (Blueprint $table): void {
            $table->dropForeign(['node_id']);
            $table->dropUnique(['node_id']);
            $table->dropColumn('node_id');
            $table->text('repository_url')->nullable(false)->change();
            $table->text('repository_identity')->nullable(false)->change();
        });
    }

    /**
     * SQLite changes a column by rebuilding the table, which drops its triggers (the Project code guard among them) and
     * may drop its indexes: every one the table held before is created again afterwards.
     */
    private function rebuild(Closure $change): void
    {
        $kept = DB::select("SELECT name, sql FROM sqlite_master WHERE tbl_name = 'projects' AND type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY type, name");
        DB::statement('PRAGMA legacy_alter_table = ON');
        try {
            Schema::table('projects', $change);
        } finally {
            DB::statement('PRAGMA legacy_alter_table = OFF');
        }
        foreach ($kept as $object) {
            if (is_object($object) && is_string($object->name ?? null) && is_string($object->sql ?? null)
                && DB::selectOne('SELECT 1 FROM sqlite_master WHERE name = ?', [$object->name]) === null) {
                DB::statement($object->sql);
            }
        }
    }
};

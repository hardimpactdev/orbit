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
        $this->rebuild(function (Blueprint $table): void {
            if (DB::table('projects')->whereNull('repository_url')->exists()) {
                throw new RuntimeException('Remove every generic Project before rolling back: their rows have no repository.');
            }

            $table->dropForeign(['node_id']);
            $table->dropUnique(['node_id']);
            $table->dropColumn('node_id');
            $table->text('repository_url')->nullable(false)->change();
            $table->text('repository_identity')->nullable(false)->change();
        });
    }

    /**
     * SQLite changes a column by rebuilding the table, which drops its triggers (the Project code guard among them) and
     * may drop its indexes: every one the table held before is created again afterwards, except one that names a column
     * the change removed. The rebuild also resets the AUTOINCREMENT sequence to the highest surviving ID, so the
     * sequence is put back: a deleted Project's ID is never given out again.
     *
     * Laravel runs no SQLite migration in a transaction, so the rebuild holds one write transaction (the connection's
     * transaction mode is IMMEDIATE): no other writer commits between the copy and the swap. Foreign keys are off
     * around it, because SQLite ignores that pragma inside a transaction and dropping the old table would otherwise
     * delete or orphan the rows that reference it.
     */
    private function rebuild(Closure $change): void
    {
        $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('PRAGMA legacy_alter_table = ON');
        try {
            DB::transaction(static function () use ($change): void {
                $kept = DB::select("SELECT name, sql FROM sqlite_master WHERE tbl_name = 'projects' AND type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY type, name");
                $columns = Schema::getColumnListing('projects');
                $sequence = DB::scalar("SELECT seq FROM sqlite_sequence WHERE name = 'projects'");

                Schema::table('projects', $change);

                $removed = array_diff($columns, Schema::getColumnListing('projects'));
                foreach ($kept as $object) {
                    if (is_object($object) && is_string($object->name ?? null) && is_string($object->sql ?? null)
                        && array_filter($removed, static fn (string $column): bool => preg_match('/\b'.preg_quote($column, '/').'\b/', $object->sql) === 1) === []
                        && DB::selectOne('SELECT 1 FROM sqlite_master WHERE name = ?', [$object->name]) === null) {
                        DB::statement($object->sql);
                    }
                }
                if (is_int($sequence)) {
                    DB::delete("DELETE FROM sqlite_sequence WHERE name = 'projects'");
                    DB::insert("INSERT INTO sqlite_sequence (name, seq) SELECT 'projects', MAX(?, COALESCE((SELECT MAX(id) FROM projects), 0))", [$sequence]);
                }
            });
        } finally {
            DB::statement('PRAGMA legacy_alter_table = OFF');
            if ($foreignKeys) {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }
    }
};

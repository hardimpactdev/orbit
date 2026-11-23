<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allows switching an existing container runtime's server from `t3` to `conn` (never back, and never on a serverless runtime),
 * and sets the `projects.container_server` column default to `conn` while keeping existing Project rows unchanged
 * (docs/reference/development-containers.md#container-server).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->containerServerDefault('conn');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS instance_runtimes_container_server_update;
            CREATE TRIGGER instance_runtimes_container_server_update
            BEFORE UPDATE OF container_server ON instance_runtimes
            WHEN NEW.container_server IS NOT OLD.container_server
                AND NOT (OLD.container_server IS 't3' AND NEW.container_server IS 'conn')
            BEGIN SELECT RAISE(ABORT, 'An Instance runtime can only switch its container server from t3 to conn.'); END;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS instance_runtimes_container_server_update;
            CREATE TRIGGER instance_runtimes_container_server_update
            BEFORE UPDATE OF container_server ON instance_runtimes
            WHEN NEW.container_server IS NOT OLD.container_server
            BEGIN SELECT RAISE(ABORT, 'An Instance runtime keeps its container server.'); END;
            SQL);

        $this->containerServerDefault('t3');
    }

    /**
     * SQLite changes a column default by rebuilding the table, which drops its triggers (the Project code guard among them) and
     * may drop its indexes: every one the table held before is created again afterwards.
     */
    private function containerServerDefault(string $default): void
    {
        $kept = DB::select("SELECT name, sql FROM sqlite_master WHERE tbl_name = 'projects' AND type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY type, name");
        DB::statement('PRAGMA legacy_alter_table = ON');
        try {
            Schema::table('projects', function (Blueprint $table) use ($default) {
                $table->string('container_server', 16)->default($default)->change();
            });
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

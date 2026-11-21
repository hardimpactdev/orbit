<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A container Instance keeps its Node, Project and name, except a reserved clone's draft name (`draft-<hex>`), which its finalize
 * replaces once with the name its user chose (docs/reference/conn.md#reserve-a-clone).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS instances_runtime_placement_update;
            CREATE TRIGGER instances_runtime_placement_update
            BEFORE UPDATE OF node_id, project_id, name ON instances
            WHEN EXISTS (SELECT 1 FROM instance_runtimes WHERE instance_id = OLD.id)
                AND (NEW.node_id IS NOT OLD.node_id OR NEW.project_id IS NOT OLD.project_id
                    OR (NEW.name IS NOT OLD.name AND OLD.name NOT GLOB 'draft-[0-9a-f]*'))
            BEGIN SELECT RAISE(ABORT, 'A container Instance keeps its Node, Project and name.'); END;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS instances_runtime_placement_update;
            CREATE TRIGGER instances_runtime_placement_update
            BEFORE UPDATE OF node_id, project_id, name ON instances
            WHEN EXISTS (SELECT 1 FROM instance_runtimes WHERE instance_id = OLD.id)
                AND (NEW.node_id IS NOT OLD.node_id OR NEW.project_id IS NOT OLD.project_id OR NEW.name IS NOT OLD.name)
            BEGIN SELECT RAISE(ABORT, 'A container Instance keeps its Node, Project and name.'); END;
            SQL);
    }
};

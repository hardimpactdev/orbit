<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Node keeps its Instance bridge while a container uses it. A bridge that no container uses yet may go back to none, which the
 * record of a clashing CI bridge does, so the next admission allocates around that bridge (docs/reference/development-containers.md#nodes).
 * Any other change of a recorded bridge is still refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS nodes_instance_bridge_update');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER nodes_instance_bridge_update
            BEFORE UPDATE OF instance_bridge ON nodes
            WHEN OLD.instance_bridge IS NOT NULL AND NEW.instance_bridge IS NOT OLD.instance_bridge
                AND (NEW.instance_bridge IS NOT NULL OR EXISTS (SELECT 1 FROM instance_runtimes WHERE node_id = NEW.id))
            BEGIN SELECT RAISE(ABORT, 'A Node keeps its Instance bridge.'); END;
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS nodes_instance_bridge_update');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER nodes_instance_bridge_update
            BEFORE UPDATE OF instance_bridge ON nodes
            WHEN OLD.instance_bridge IS NOT NULL AND NEW.instance_bridge IS NOT OLD.instance_bridge
            BEGIN SELECT RAISE(ABORT, 'A Node keeps its Instance bridge.'); END;
            SQL);
    }
};

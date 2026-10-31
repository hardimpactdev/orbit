<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_removal_members', function (Blueprint $table): void {
            $table->json('route_ids')->nullable();
        });
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER instance_removal_members_routes_insert
            BEFORE INSERT ON instance_removal_members
            WHEN NEW.route_ids IS NOT NULL AND (
                NOT json_valid(NEW.route_ids)
                OR json_type(NEW.route_ids) <> 'array'
                OR EXISTS (SELECT 1 FROM json_each(NEW.route_ids) WHERE type <> 'integer' OR value < 1)
                OR json_array_length(NEW.route_ids) <> (SELECT COUNT(DISTINCT value) FROM json_each(NEW.route_ids))
                OR json_array_length(NEW.route_ids) <> (SELECT COUNT(*) FROM route_targets WHERE instance_id = NEW.instance_id)
                OR EXISTS (
                    SELECT 1 FROM route_targets
                    WHERE instance_id = NEW.instance_id
                        AND route_id NOT IN (SELECT value FROM json_each(NEW.route_ids))
                )
                OR (NEW.route_id IS NOT NULL AND NEW.route_id NOT IN (SELECT value FROM json_each(NEW.route_ids)))
            )
            BEGIN
                SELECT RAISE(ABORT, 'Instance removal requires its complete Route inventory.');
            END;
            CREATE TRIGGER instance_removal_members_routes_immutable
            BEFORE UPDATE OF route_ids ON instance_removal_members
            WHEN NEW.route_ids IS NOT OLD.route_ids
            BEGIN
                SELECT RAISE(ABORT, 'Instance removal Route inventory is immutable.');
            END;
            SQL);
        $this->replaceRouteMatch(true);
    }

    public function down(): void
    {
        $this->replaceRouteMatch(false);
        DB::unprepared('DROP TRIGGER instance_removal_members_routes_insert; DROP TRIGGER instance_removal_members_routes_immutable;');
        Schema::table('instance_removal_members', function (Blueprint $table): void {
            $table->dropColumn('route_ids');
        });
    }

    /** Preserve the existing phase guards and extend only the admitted Route membership. */
    private function replaceRouteMatch(bool $complete): void
    {
        $targetMatch = $complete
            ? '(instance_removal_members.route_id = OLD.route_id OR EXISTS (SELECT 1 FROM json_each(instance_removal_members.route_ids) WHERE value = OLD.route_id))'
            : 'instance_removal_members.route_id = OLD.route_id';
        $routeMatch = $complete
            ? '(instance_removal_members.route_id = OLD.id OR EXISTS (SELECT 1 FROM json_each(instance_removal_members.route_ids) WHERE value = OLD.id))'
            : 'instance_removal_members.route_id = OLD.id';
        DB::unprepared(<<<SQL
            DROP TRIGGER route_targets_active_delete;
            CREATE TRIGGER route_targets_active_delete
            BEFORE DELETE ON route_targets
            WHEN (
                (SELECT type FROM projects WHERE id = (
                    SELECT project_id FROM instances WHERE id = OLD.instance_id
                )) = 'laravel-app'
                AND (SELECT status FROM instances WHERE id = OLD.instance_id) = 'active'
                AND (SELECT status FROM routes WHERE id = OLD.route_id) IN ('active', 'activating')
            ) OR (
                (SELECT status FROM instances WHERE id = OLD.instance_id) = 'removing'
                AND NOT EXISTS (
                    SELECT 1 FROM instance_removal_members
                    JOIN instance_removals ON instance_removals.id = instance_removal_members.instance_removal_id
                    WHERE instance_removal_members.instance_id = OLD.instance_id
                        AND {$targetMatch}
                        AND instance_removal_members.source_prepared_at IS NOT NULL
                        AND instance_removal_members.route_cleared_at IS NULL
                        AND instance_removal_members.row_deleted_at IS NULL
                        AND instance_removals.status IN ('removing', 'failed')
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'An active Instance requires exactly one authoritative Route.');
            END;
            DROP TRIGGER routes_active_target_delete;
            CREATE TRIGGER routes_active_target_delete
            BEFORE DELETE ON routes
            WHEN EXISTS (
                SELECT 1 FROM route_targets
                JOIN instances ON instances.id = route_targets.instance_id
                JOIN projects ON projects.id = instances.project_id
                WHERE route_targets.route_id = OLD.id AND (
                    (projects.type = 'laravel-app' AND instances.status = 'active' AND OLD.status IN ('active', 'activating'))
                    OR (
                        instances.status = 'removing'
                        AND NOT EXISTS (
                            SELECT 1 FROM instance_removal_members
                            JOIN instance_removals ON instance_removals.id = instance_removal_members.instance_removal_id
                            WHERE instance_removal_members.instance_id = instances.id
                                AND {$routeMatch}
                                AND instance_removal_members.source_prepared_at IS NOT NULL
                                AND instance_removal_members.route_cleared_at IS NULL
                                AND instance_removal_members.row_deleted_at IS NULL
                                AND instance_removals.status IN ('removing', 'failed')
                        )
                    )
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'An active Instance requires exactly one authoritative Route.');
            END;
            SQL);
    }
};

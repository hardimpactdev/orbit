<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const array RemovalTriggers = [
        'instance_removal_members_delete',
        'instance_removal_members_immutable',
        'instance_removal_members_insert',
        'instance_removal_members_routes_immutable',
        'instance_removal_members_routes_insert',
        'instance_removal_members_source_commit_immutable',
        'instance_removal_members_source_commit_insert',
        'instance_removal_members_source_immutable',
        'instance_removals_delete',
        'instance_removals_immutable',
        'instance_removals_insert',
        'instance_removals_native_intent_immutable',
        'instance_removals_native_sources_immutable',
        'instances_removal_status_update',
        'route_targets_active_delete',
        'routes_active_target_delete',
    ];

    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            DB::transaction(function (): void {
                $this->assertRemovalContracts();
                // SQLite rebuilds the enum table. Drop only these known contracts, then restore
                // their literal definitions with the abandoned-state rules. No data row is changed.
                DB::unprepared(<<<'SQL'
                    DROP TRIGGER "instance_removal_members_delete";
                    DROP TRIGGER "instance_removal_members_immutable";
                    DROP TRIGGER "instance_removal_members_insert";
                    DROP TRIGGER "instance_removal_members_routes_immutable";
                    DROP TRIGGER "instance_removal_members_routes_insert";
                    DROP TRIGGER "instance_removal_members_source_commit_immutable";
                    DROP TRIGGER "instance_removal_members_source_commit_insert";
                    DROP TRIGGER "instance_removal_members_source_immutable";
                    DROP TRIGGER "instance_removals_delete";
                    DROP TRIGGER "instance_removals_immutable";
                    DROP TRIGGER "instance_removals_insert";
                    DROP TRIGGER "instance_removals_native_intent_immutable";
                    DROP TRIGGER "instance_removals_native_sources_immutable";
                    DROP TRIGGER "instances_removal_status_update";
                    DROP TRIGGER "route_targets_active_delete";
                    DROP TRIGGER "routes_active_target_delete";
                    SQL);
                Schema::table('instance_removals', function (Blueprint $table): void {
                    $table->enum('status', ['removing', 'failed', 'completed', 'abandoned'])->change();
                });
                Schema::table('instance_removal_members', function (Blueprint $table): void {
                    $table->timestamp('abandoned_at')->nullable();
                });
                DB::unprepared(<<<'SQL'
                    DROP INDEX instance_removal_members_live_unique;
                    CREATE UNIQUE INDEX instance_removal_members_live_unique ON instance_removal_members(instance_id)
                    WHERE row_deleted_at IS NULL AND abandoned_at IS NULL;
                    CREATE TRIGGER `instance_removal_members_delete`
                    BEFORE DELETE ON instance_removal_members
                    BEGIN
                        SELECT RAISE(ABORT, 'Instance removal member evidence is immutable.');
                    END;
                    CREATE TRIGGER `instance_removal_members_immutable`
                    BEFORE UPDATE ON instance_removal_members
                    WHEN OLD.abandoned_at IS NOT NULL
                        OR (NEW.abandoned_at IS NOT NULL AND (
                            NEW.row_deleted_at IS NOT NULL
                            OR NEW.source_finalized_at IS NOT NULL
                            OR NEW.runtime_cleaned_at IS NOT NULL
                            OR (SELECT status FROM instance_removals WHERE id = NEW.instance_removal_id) IS NOT 'failed'
                            OR (SELECT status FROM instances WHERE id = NEW.instance_id) IS NOT 'removing'
                        ))
                        OR NEW.instance_removal_id <> OLD.instance_removal_id
                        OR NEW.position <> OLD.position
                        OR NEW.instance_id <> OLD.instance_id
                        OR NEW.project_id <> OLD.project_id
                        OR NEW.node_id <> OLD.node_id
                        OR NEW.route_id IS NOT OLD.route_id
                                    OR NEW.runtime_published <> OLD.runtime_published
                        OR NEW.name <> OLD.name
                        OR NEW.environment <> OLD.environment
                        OR NEW.source_layout <> OLD.source_layout
                        OR NEW.repository_identity IS NOT OLD.repository_identity
                        OR NEW.checkout_path IS NOT OLD.checkout_path
                        OR NEW.root IS NOT OLD.root
                        OR NEW.branch IS NOT OLD.branch
                        OR NEW.starting_commit IS NOT OLD.starting_commit
                        OR (NEW.common_repository_path IS NOT OLD.common_repository_path) AND NOT (OLD.origin IS NULL AND OLD.worktree_inventory IS NULL AND (SELECT native_sources IS NULL AND COALESCE(json_extract(native_intent, '$.source_pending'), 0) = 1 FROM instance_removals WHERE id = OLD.instance_removal_id))
                        OR (NEW.source_identity IS NOT OLD.source_identity) AND NOT (OLD.origin IS NULL AND OLD.worktree_inventory IS NULL AND (SELECT native_sources IS NULL AND COALESCE(json_extract(native_intent, '$.source_pending'), 0) = 1 FROM instance_removals WHERE id = OLD.instance_removal_id))
                        OR (NEW.linked_worktree_paths <> OLD.linked_worktree_paths) AND NOT (OLD.origin IS NULL AND OLD.worktree_inventory IS NULL AND (SELECT native_sources IS NULL AND COALESCE(json_extract(native_intent, '$.source_pending'), 0) = 1 FROM instance_removals WHERE id = OLD.instance_removal_id))
                        OR (NEW.source_digest <> OLD.source_digest) AND NOT (OLD.origin IS NULL AND OLD.worktree_inventory IS NULL AND (SELECT native_sources IS NULL AND COALESCE(json_extract(native_intent, '$.source_pending'), 0) = 1 FROM instance_removals WHERE id = OLD.instance_removal_id))
                        OR (OLD.source_prepared_at IS NOT NULL AND NEW.source_prepared_at IS NOT OLD.source_prepared_at)
                        OR (OLD.route_cleared_at IS NOT NULL AND NEW.route_cleared_at IS NOT OLD.route_cleared_at)
                        OR (OLD.route_outcome IS NOT NULL AND NEW.route_outcome IS NOT OLD.route_outcome)
                        OR (OLD.source_finalized_at IS NOT NULL AND NEW.source_finalized_at IS NOT OLD.source_finalized_at)
                        OR (OLD.finalization_receipt IS NOT NULL
                            AND NEW.finalization_receipt IS NOT OLD.finalization_receipt)
                        OR (OLD.runtime_cleaned_at IS NOT NULL AND NEW.runtime_cleaned_at IS NOT OLD.runtime_cleaned_at)
                        OR (OLD.row_deleted_at IS NOT NULL AND NEW.row_deleted_at IS NOT OLD.row_deleted_at)
                        OR (OLD.source_prepared_at IS NULL AND NEW.source_prepared_at IS NOT NULL AND (
                            (SELECT status FROM instances WHERE id = NEW.instance_id) IS NOT 'removing'
                            OR (SELECT status FROM instance_removals
                                WHERE id = NEW.instance_removal_id) IS NOT 'removing'
                        ))
                        OR (NEW.route_cleared_at IS NOT NULL AND NEW.source_prepared_at IS NULL)
                        OR (NEW.route_outcome IS NOT NULL) <> (NEW.route_cleared_at IS NOT NULL)
                        OR (NEW.route_outcome IS NOT NULL AND NEW.route_outcome NOT IN ('retained', 'deleted', 'none'))
                                    OR (NEW.route_outcome IS NOT NULL AND (NEW.route_outcome = 'none') <> (NEW.route_id IS NULL))
                        OR (NEW.route_cleared_at IS NOT NULL AND EXISTS (
                            SELECT 1 FROM route_targets WHERE instance_id = NEW.instance_id
                        ))
                        OR (NEW.route_outcome = 'deleted' AND EXISTS (
                            SELECT 1 FROM routes WHERE id = NEW.route_id
                        ))
                        OR (NEW.route_outcome = 'retained' AND NOT EXISTS (
                            SELECT 1 FROM routes WHERE id = NEW.route_id
                        ))
                        OR (NEW.source_finalized_at IS NOT NULL AND NEW.route_cleared_at IS NULL)
                        OR (NEW.finalization_receipt IS NOT NULL) <> (NEW.source_finalized_at IS NOT NULL)
                        OR (NEW.runtime_cleaned_at IS NOT NULL AND NEW.source_finalized_at IS NULL)
                        OR (NEW.row_deleted_at IS NOT NULL AND NEW.runtime_cleaned_at IS NULL)
                        OR (NEW.row_deleted_at IS NOT NULL AND EXISTS (
                            SELECT 1 FROM instances WHERE id = NEW.instance_id
                        ))
                        OR (SELECT status FROM instance_removals
                            WHERE id = NEW.instance_removal_id) = 'completed'
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid Instance removal member contract.');
                    END;
                    CREATE TRIGGER `instance_removal_members_insert`
                    BEFORE INSERT ON instance_removal_members
                    WHEN NOT EXISTS (
                            SELECT 1
                            FROM instance_removals
                            WHERE id = NEW.instance_removal_id
                                AND status = 'removing'
                                AND current_step = 'source_preparation'
                                AND failed_step IS NULL
                                AND error_code IS NULL
                        )
                        OR NEW.position < 0
                        OR NEW.position >= (
                            SELECT total FROM instance_removals WHERE id = NEW.instance_removal_id
                        )
                        OR (SELECT COUNT(*) FROM instance_removal_members
                            WHERE instance_removal_id = NEW.instance_removal_id) >= (
                                SELECT total FROM instance_removals WHERE id = NEW.instance_removal_id
                            )
                        OR (
                            (SELECT COUNT(*) FROM instance_removal_members
                                WHERE instance_removal_id = NEW.instance_removal_id) + 1
                                = (SELECT total FROM instance_removals
                                    WHERE id = NEW.instance_removal_id)
                            AND NEW.instance_id <> (
                                SELECT requested_instance_id FROM instance_removals
                                WHERE id = NEW.instance_removal_id
                            )
                            AND NOT EXISTS (
                                SELECT 1 FROM instance_removal_members
                                WHERE instance_removal_id = NEW.instance_removal_id
                                    AND instance_id = (
                                        SELECT requested_instance_id FROM instance_removals
                                        WHERE id = NEW.instance_removal_id
                                    )
                            )
                        )
                        OR NEW.environment NOT IN ('development', 'production')
                        OR NEW.source_layout NOT IN ('checkout', 'worktree')
                        OR json_valid(NEW.linked_worktree_paths) <> 1
                        OR json_type(NEW.linked_worktree_paths) <> 'array'
                        OR length(NEW.source_digest) <> 64
                        OR NEW.source_prepared_at IS NOT NULL
                        OR NEW.route_cleared_at IS NOT NULL
                        OR NEW.route_outcome IS NOT NULL
                        OR NEW.source_finalized_at IS NOT NULL
                        OR NEW.finalization_receipt IS NOT NULL
                        OR NEW.runtime_cleaned_at IS NOT NULL
                        OR NEW.row_deleted_at IS NOT NULL OR NEW.abandoned_at IS NOT NULL
                        OR NOT EXISTS (
                            SELECT 1
                            FROM instances
                            JOIN projects ON projects.id = instances.project_id
                            WHERE instances.id = NEW.instance_id
                                AND instances.project_id = NEW.project_id
                                AND instances.node_id = NEW.node_id
                                AND instances.name = NEW.name
                                AND ((NEW.environment = 'production' AND EXISTS (SELECT 1 FROM node_roles AS removal_role WHERE removal_role.node_id = instances.node_id AND removal_role.role = 'app-prod' AND removal_role.status IN ('active', 'removing'))) OR (NEW.environment = 'development' AND EXISTS (SELECT 1 FROM node_roles AS removal_role WHERE removal_role.node_id = instances.node_id AND removal_role.role = 'app-dev' AND removal_role.status IN ('active', 'removing'))))
                                AND instances.source_layout = NEW.source_layout
                                AND instances.checkout_path IS NEW.checkout_path
                                AND COALESCE(instances.root, projects.root) IS NEW.root
                                AND (instances.starting_commit IS NULL OR instances.branch IS NEW.branch)
                                AND instances.starting_commit IS NEW.starting_commit
                                AND projects.repository_identity IS NEW.repository_identity
                                AND (instances.status = 'active' OR (NEW.runtime_published = 0 AND (instances.status IN ('reserved', 'checkout_prepared', 'source_resolved') AND (instances.failed_step IS NOT NULL AND instances.error_code IS NOT NULL OR (((instances.source_layout = 'checkout' AND instances.source_prepare_id IS NOT NULL) OR ((instances.source_layout = 'worktree' AND instances.status = 'reserved' AND instances.starting_commit IS NULL AND instances.task_workspace_routed IS NOT NULL) OR (instances.source_layout = 'worktree' AND instances.status IN ('reserved', 'checkout_prepared') AND instances.source_prepare_id IS NOT NULL AND instances.seed_repository IS NOT NULL AND instances.seed_commit IS NOT NULL AND instances.starting_commit IS NULL))) AND instances.registration_request_id IS NULL AND (instances.task_workspace_routed IS NOT 0 OR instances.starting_commit IS NULL))) AND EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = instances.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing'))))
                                                OR (instances.status = 'source_resolved' AND (NEW.route_id IS NULL OR (NEW.runtime_published = 0 AND EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = instances.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing')) AND (SELECT COUNT(DISTINCT route_id) FROM route_targets WHERE instance_id = instances.id) = 1 AND EXISTS (SELECT 1 FROM routes JOIN route_targets ON route_targets.route_id = routes.id WHERE route_targets.instance_id = instances.id AND routes.project_id = instances.project_id AND routes.status IN ('pending', 'failed') AND (SELECT COUNT(*) FROM route_targets AS targets WHERE targets.route_id = routes.id) = 1)))))
                        )
                        OR (NEW.route_id IS NOT NULL AND NOT EXISTS (
                                        SELECT 1 FROM route_targets
                                        WHERE route_targets.route_id = NEW.route_id
                                            AND route_targets.instance_id = NEW.instance_id
                                    ))
                                    OR (NEW.route_id IS NULL AND EXISTS (
                                        SELECT 1 FROM route_targets
                                        WHERE route_targets.instance_id = NEW.instance_id
                                    ))
                        OR (NEW.environment = 'development' AND (
                            NEW.repository_identity IS NULL
                            OR NEW.checkout_path IS NULL
                            OR NEW.root IS NULL
                                    OR (NEW.starting_commit IS NULL AND NOT (NEW.runtime_published = 0 AND EXISTS (SELECT 1 FROM instances WHERE instances.id = NEW.instance_id AND (instances.status IN ('reserved', 'checkout_prepared', 'source_resolved') AND (instances.failed_step IS NOT NULL AND instances.error_code IS NOT NULL OR (((instances.source_layout = 'checkout' AND instances.source_prepare_id IS NOT NULL) OR ((instances.source_layout = 'worktree' AND instances.status = 'reserved' AND instances.starting_commit IS NULL AND instances.task_workspace_routed IS NOT NULL) OR (instances.source_layout = 'worktree' AND instances.status IN ('reserved', 'checkout_prepared') AND instances.source_prepare_id IS NOT NULL AND instances.seed_repository IS NOT NULL AND instances.seed_commit IS NOT NULL AND instances.starting_commit IS NULL))) AND instances.registration_request_id IS NULL AND (instances.task_workspace_routed IS NOT 0 OR instances.starting_commit IS NULL))) AND EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = instances.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing'))))))
                            OR NEW.common_repository_path IS NULL
                            OR NEW.source_identity IS NULL
                        ))
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid Instance removal member contract.');
                    END;
                    CREATE TRIGGER instance_removal_members_routes_immutable
                    BEFORE UPDATE OF route_ids ON instance_removal_members
                    WHEN NEW.route_ids IS NOT OLD.route_ids
                    BEGIN
                        SELECT RAISE(ABORT, 'Instance removal Route inventory is immutable.');
                    END;
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
                    CREATE TRIGGER instance_removal_members_source_commit_immutable
                    BEFORE UPDATE OF source_commit ON instance_removal_members
                    WHEN NEW.source_commit IS NOT OLD.source_commit AND NOT (OLD.origin IS NULL AND OLD.worktree_inventory IS NULL AND (SELECT native_sources IS NULL AND COALESCE(json_extract(native_intent, '$.source_pending'), 0) = 1 FROM instance_removals WHERE id = OLD.instance_removal_id))
                    BEGIN
                        SELECT RAISE(ABORT, 'Native Instance removal source commit is immutable.');
                    END;
                    CREATE TRIGGER `instance_removal_members_source_commit_insert`
                    BEFORE INSERT ON instance_removal_members
                    WHEN NEW.environment = 'development' AND (
                        NEW.source_commit IS NULL
                        OR (length(NEW.source_commit) NOT IN (40, 64) AND NOT (NEW.source_commit = '' AND NEW.starting_commit IS NULL AND NEW.environment = 'development' AND NEW.source_layout IN ('checkout', 'worktree')))
                    )
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid Instance removal member contract.');
                    END;
                    CREATE TRIGGER instance_removal_members_source_immutable
                    BEFORE UPDATE OF origin, worktree_inventory ON instance_removal_members
                    WHEN (NEW.origin IS NOT OLD.origin OR NEW.worktree_inventory IS NOT OLD.worktree_inventory)
                        AND NOT (OLD.origin IS NULL AND OLD.worktree_inventory IS NULL AND (SELECT native_sources IS NULL AND COALESCE(json_extract(native_intent, '$.source_pending'), 0) = 1 FROM instance_removals WHERE id = OLD.instance_removal_id))
                    BEGIN
                        SELECT RAISE(ABORT, 'Native Instance removal source inventory is immutable.');
                    END;
                    CREATE TRIGGER `instance_removals_delete`
                    BEFORE DELETE ON instance_removals
                    BEGIN
                        SELECT RAISE(ABORT, 'Instance removal evidence is immutable.');
                    END;
                    CREATE TRIGGER `instance_removals_immutable`
                    BEFORE UPDATE ON instance_removals
                    WHEN NEW.id <> OLD.id
                        OR NEW.requested_instance_id <> OLD.requested_instance_id
                        OR NEW.requested_name <> OLD.requested_name
                        OR (NEW.force <> OLD.force AND NOT (OLD.force = 0 AND NEW.force = 1 AND OLD.status = 'failed' AND NEW.status = 'failed'))
                        OR NEW.inventory_digest <> OLD.inventory_digest
                        OR NEW.total <> OLD.total
                        OR NEW.status NOT IN ('removing', 'failed', 'completed', 'abandoned')
                        OR (NEW.current_step IS NOT NULL AND NEW.current_step NOT IN (
                            'source_preparation',
                            'route_target_clear',
                            'source_finalization',
                            'runtime_cleanup',
                            'row_deletion'
                        ))
                        OR (NEW.failed_step IS NOT NULL AND NEW.failed_step NOT IN (
                            'source_preparation',
                            'route_target_clear',
                            'source_finalization',
                            'runtime_cleanup',
                            'row_deletion'
                        ))
                        OR (NEW.status = 'removing' AND (
                            NEW.current_step IS NULL
                            OR NEW.failed_step IS NOT NULL
                            OR NEW.error_code IS NOT NULL
                        ))
                        OR (NEW.status = 'failed' AND (
                            NEW.current_step IS NULL
                            OR NEW.failed_step IS NOT NEW.current_step
                            OR NEW.error_code IS NULL
                            OR NEW.error_code = ''
                        ))
                        OR (NEW.status = 'completed' AND (
                            NEW.current_step IS NOT NULL
                            OR NEW.failed_step IS NOT NULL
                            OR NEW.error_code IS NOT NULL
                            OR (SELECT COUNT(*) FROM instance_removal_members
                                WHERE instance_removal_id = NEW.id) <> NEW.total
                            OR EXISTS (
                                SELECT 1 FROM instance_removal_members
                                WHERE instance_removal_id = NEW.id AND row_deleted_at IS NULL
                            )
                        ))
                        OR (OLD.status = 'completed' AND NEW.status <> 'completed')
                    OR OLD.status = 'abandoned'
                    OR (NEW.status = 'abandoned' AND (
                        OLD.status <> 'failed'
                        OR NEW.current_step IS NOT NULL OR NEW.failed_step IS NOT NULL OR NEW.error_code IS NOT NULL
                        OR (SELECT COUNT(*) FROM instance_removal_members WHERE instance_removal_id = NEW.id) <> NEW.total
                        OR EXISTS (SELECT 1 FROM instance_removal_members WHERE instance_removal_id = NEW.id
                            AND (abandoned_at IS NULL OR row_deleted_at IS NOT NULL))
                    ))
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid Instance removal contract.');
                    END;
                    CREATE TRIGGER `instance_removals_insert`
                    BEFORE INSERT ON instance_removals
                    WHEN NEW.id = ''
                        OR NEW.requested_name = ''
                        OR NEW.force NOT IN (0, 1)
                        OR length(NEW.inventory_digest) <> 64
                        OR NEW.total < 1
                        OR NEW.status <> 'removing'
                        OR NEW.current_step IS NOT 'source_preparation'
                        OR NEW.failed_step IS NOT NULL
                        OR NEW.error_code IS NOT NULL
                        OR NOT EXISTS (
                            SELECT 1
                            FROM instances
                            WHERE id = NEW.requested_instance_id
                                AND name = NEW.requested_name
                                            AND (status = 'active' OR (instances.status IN ('reserved', 'checkout_prepared', 'source_resolved') AND (instances.failed_step IS NOT NULL AND instances.error_code IS NOT NULL OR (((instances.source_layout = 'checkout' AND instances.source_prepare_id IS NOT NULL) OR ((instances.source_layout = 'worktree' AND instances.status = 'reserved' AND instances.starting_commit IS NULL AND instances.task_workspace_routed IS NOT NULL) OR (instances.source_layout = 'worktree' AND instances.status IN ('reserved', 'checkout_prepared') AND instances.source_prepare_id IS NOT NULL AND instances.seed_repository IS NOT NULL AND instances.seed_commit IS NOT NULL AND instances.starting_commit IS NULL))) AND instances.registration_request_id IS NULL AND (instances.task_workspace_routed IS NOT 0 OR instances.starting_commit IS NULL))) AND EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = instances.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing'))) OR (status = 'source_resolved' AND (NOT EXISTS ( SELECT 1 FROM route_targets WHERE route_targets.instance_id = instances.id ) OR (EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = instances.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing')) AND (SELECT COUNT(DISTINCT route_id) FROM route_targets WHERE instance_id = instances.id) = 1 AND EXISTS (SELECT 1 FROM routes JOIN route_targets ON route_targets.route_id = routes.id WHERE route_targets.instance_id = instances.id AND routes.project_id = instances.project_id AND routes.status IN ('pending', 'failed') AND (SELECT COUNT(*) FROM route_targets AS targets WHERE targets.route_id = routes.id) = 1)))))
                        )
                    BEGIN
                        SELECT RAISE(ABORT, 'Invalid Instance removal contract.');
                    END;
                    CREATE TRIGGER instance_removals_native_intent_immutable
                    BEFORE UPDATE OF native_intent ON instance_removals
                    WHEN OLD.native_intent IS NOT NULL AND NEW.native_intent IS NOT OLD.native_intent
                    BEGIN
                        SELECT RAISE(ABORT, 'Native Instance removal intent is immutable.');
                    END;
                    CREATE TRIGGER instance_removals_native_sources_immutable
                    BEFORE UPDATE OF native_sources ON instance_removals
                    WHEN OLD.native_sources IS NOT NULL AND NEW.native_sources IS NOT OLD.native_sources
                    BEGIN
                        SELECT RAISE(ABORT, 'Native Instance removal source inspection is immutable.');
                    END;
                    CREATE TRIGGER `instances_removal_status_update`
                    BEFORE UPDATE OF status ON instances
                    WHEN (OLD.status = 'removing' AND NEW.status <> 'removing' AND (
                        NEW.status <> 'active'
                        OR NOT EXISTS (
                            SELECT 1 FROM instance_removal_members AS member
                            JOIN instance_removals AS removal ON removal.id = member.instance_removal_id
                            WHERE member.instance_id = NEW.id AND member.abandoned_at IS NOT NULL
                                AND member.row_deleted_at IS NULL AND removal.status = 'abandoned'
                        )
                        OR EXISTS (SELECT 1 FROM instance_removal_members WHERE instance_id = NEW.id
                            AND row_deleted_at IS NULL AND abandoned_at IS NULL)
                    ))
                        OR (NEW.status = 'removing' AND OLD.status <> 'removing' AND (
                            (OLD.status NOT IN ('active', 'source_resolved') AND NOT (OLD.status IN ('reserved', 'checkout_prepared', 'source_resolved') AND (OLD.failed_step IS NOT NULL AND OLD.error_code IS NOT NULL OR (((OLD.source_layout = 'checkout' AND OLD.source_prepare_id IS NOT NULL) OR ((OLD.source_layout = 'worktree' AND OLD.status = 'reserved' AND OLD.starting_commit IS NULL AND OLD.task_workspace_routed IS NOT NULL) OR (OLD.source_layout = 'worktree' AND OLD.status IN ('reserved', 'checkout_prepared') AND OLD.source_prepare_id IS NOT NULL AND OLD.seed_repository IS NOT NULL AND OLD.seed_commit IS NOT NULL AND OLD.starting_commit IS NULL))) AND OLD.registration_request_id IS NULL AND (OLD.task_workspace_routed IS NOT 0 OR OLD.starting_commit IS NULL))) AND EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = OLD.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing'))))
                            OR NOT EXISTS (
                                SELECT 1
                                FROM instance_removal_members
                                JOIN instance_removals
                                    ON instance_removals.id = instance_removal_members.instance_removal_id
                                WHERE instance_removal_members.instance_id = NEW.id
                                    AND instance_removal_members.row_deleted_at IS NULL AND instance_removal_members.abandoned_at IS NULL
                                    AND instance_removals.status = 'removing'
                                    AND (SELECT COUNT(*) FROM instance_removal_members AS inventory
                                        WHERE inventory.instance_removal_id = instance_removals.id)
                                        = instance_removals.total
                            )
                        ))
                    BEGIN
                        SELECT RAISE(ABORT, 'A removing Instance requires recorded removal membership.');
                    END;
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
                                AND (instance_removal_members.route_id = OLD.route_id OR EXISTS (SELECT 1 FROM json_each(instance_removal_members.route_ids) WHERE value = OLD.route_id))
                                AND instance_removal_members.source_prepared_at IS NOT NULL
                                AND instance_removal_members.route_cleared_at IS NULL
                                AND instance_removal_members.row_deleted_at IS NULL AND instance_removal_members.abandoned_at IS NULL
                                AND instance_removals.status IN ('removing', 'failed')
                        )
                    )
                    BEGIN
                        SELECT RAISE(ABORT, 'An active Instance requires exactly one authoritative Route.');
                    END;
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
                                        AND (instance_removal_members.route_id = OLD.id OR EXISTS (SELECT 1 FROM json_each(instance_removal_members.route_ids) WHERE value = OLD.id))
                                        AND instance_removal_members.source_prepared_at IS NOT NULL
                                        AND instance_removal_members.route_cleared_at IS NULL
                                        AND instance_removal_members.row_deleted_at IS NULL AND instance_removal_members.abandoned_at IS NULL
                                        AND instance_removals.status IN ('removing', 'failed')
                                )
                            )
                        )
                    )
                    BEGIN
                        SELECT RAISE(ABORT, 'An active Instance requires exactly one authoritative Route.');
                    END;
                    SQL);
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new LogicException('Abandoned removal migration changed foreign-key integrity.');
                }
            });
        });
    }

    public function down(): void
    {
        throw new LogicException('Abandoned removal history is forward-only; it cannot be downgraded without losing history.');
    }

    private function assertRemovalContracts(): void
    {
        $rows = DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND instr(lower(sql), 'instance_removal') > 0 ORDER BY name");
        $names = array_map(static function (stdClass $row): string {
            $name = $row->name ?? null;
            if (! is_string($name)) {
                throw new LogicException('A removal trigger has no catalog name.');
            }

            return $name;
        }, $rows);
        if ($names !== self::RemovalTriggers) {
            throw new LogicException('The removal trigger inventory changed before migration.');
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER `instance_removals_insert`;
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
            DROP TRIGGER `instance_removal_members_insert`;
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
                OR NEW.row_deleted_at IS NOT NULL
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
            DROP TRIGGER `instances_removal_status_update`;
            CREATE TRIGGER `instances_removal_status_update`
            BEFORE UPDATE OF status ON instances
            WHEN (OLD.status = 'removing' AND NEW.status <> 'removing')
                OR (NEW.status = 'removing' AND OLD.status <> 'removing' AND (
                    (OLD.status NOT IN ('active', 'source_resolved') AND NOT (OLD.status IN ('reserved', 'checkout_prepared', 'source_resolved') AND (OLD.failed_step IS NOT NULL AND OLD.error_code IS NOT NULL OR (((OLD.source_layout = 'checkout' AND OLD.source_prepare_id IS NOT NULL) OR ((OLD.source_layout = 'worktree' AND OLD.status = 'reserved' AND OLD.starting_commit IS NULL AND OLD.task_workspace_routed IS NOT NULL) OR (OLD.source_layout = 'worktree' AND OLD.status IN ('reserved', 'checkout_prepared') AND OLD.source_prepare_id IS NOT NULL AND OLD.seed_repository IS NOT NULL AND OLD.seed_commit IS NOT NULL AND OLD.starting_commit IS NULL))) AND OLD.registration_request_id IS NULL AND (OLD.task_workspace_routed IS NOT 0 OR OLD.starting_commit IS NULL))) AND EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = OLD.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing'))))
                    OR NOT EXISTS (
                        SELECT 1
                        FROM instance_removal_members
                        JOIN instance_removals
                            ON instance_removals.id = instance_removal_members.instance_removal_id
                        WHERE instance_removal_members.instance_id = NEW.id
                            AND instance_removal_members.row_deleted_at IS NULL
                            AND instance_removals.status = 'removing'
                            AND (SELECT COUNT(*) FROM instance_removal_members AS inventory
                                WHERE inventory.instance_removal_id = instance_removals.id)
                                = instance_removals.total
                    )
                ))
            BEGIN
                SELECT RAISE(ABORT, 'A removing Instance requires recorded removal membership.');
            END;
            SQL);
    }
};

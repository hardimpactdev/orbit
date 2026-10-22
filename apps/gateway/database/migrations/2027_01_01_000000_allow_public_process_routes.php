<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER routes_contract_insert;
            DROP TRIGGER routes_contract_update;
            CREATE TRIGGER routes_contract_insert
            BEFORE INSERT ON routes
            WHEN (
                NEW.kind NOT IN ('app', 'custom_proxy', 'analytics_tracking')
                OR (NEW.kind = 'app' AND NEW.project_id IS NULL)
                OR (
                    NEW.kind = 'custom_proxy'
                    AND (
                        NEW.project_id IS NOT NULL
                        OR (NEW.node_id IS NOT NULL AND NEW.publication <> 'private')
                        OR (NEW.cluster_id IS NOT NULL AND NEW.publication <> 'public')
                        OR NEW.provenance <> 'explicit'
                        OR NEW.generation_basis_node_id IS NOT NULL
                    )
                )
                OR (
                    NEW.kind = 'analytics_tracking'
                    AND (
                        NEW.project_id IS NOT NULL
                        OR NEW.provenance <> 'explicit'
                        OR NEW.generation_basis_node_id IS NOT NULL
                        OR (NEW.node_id IS NOT NULL AND NEW.publication <> 'private')
                    )
                )
                OR (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
                OR (NEW.provenance = 'generated' AND NEW.generation_basis_node_id IS NULL)
                OR (NEW.provenance = 'explicit' AND NEW.generation_basis_node_id IS NOT NULL)
                OR NEW.status NOT IN ('pending', 'active', 'activating', 'retiring', 'failed')
                OR NEW.status IN ('active', 'activating', 'retiring')
                OR (NEW.status = 'failed') <> (NEW.failed_step IS NOT NULL AND NEW.error_code IS NOT NULL)
                OR (NEW.replaces_route_id IS NOT NULL AND NEW.replaced_by_route_id IS NOT NULL)
                OR (NEW.replaces_route_id IS NOT NULL AND NEW.replacement_step IS NULL)
                OR EXISTS (
                    SELECT 1 FROM routes
                    WHERE routes.domain = NEW.domain
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END;
            CREATE TRIGGER routes_contract_update
            BEFORE UPDATE ON routes
            WHEN (
                NEW.kind <> OLD.kind
                OR NEW.kind NOT IN ('app', 'custom_proxy', 'analytics_tracking')
                OR (NEW.kind = 'app' AND NEW.project_id IS NULL)
                OR (NEW.project_id IS NOT OLD.project_id)
                OR NEW.provenance <> OLD.provenance
                OR NEW.domain <> OLD.domain
                OR (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
                OR (
                    NEW.kind = 'custom_proxy'
                    AND (
                        NEW.project_id IS NOT NULL
                        OR (NEW.node_id IS NOT NULL AND NEW.publication <> 'private')
                        OR (NEW.cluster_id IS NOT NULL AND NEW.publication <> 'public')
                    )
                )
                OR (
                    NEW.kind = 'analytics_tracking'
                    AND (
                        NEW.project_id IS NOT NULL
                        OR (NEW.node_id IS NOT NULL AND NEW.publication <> 'private')
                    )
                )
                OR (NEW.provenance = 'generated' AND NEW.generation_basis_node_id IS NULL)
                OR (NEW.provenance = 'explicit' AND NEW.generation_basis_node_id IS NOT NULL)
                OR NEW.status NOT IN ('pending', 'active', 'activating', 'retiring', 'failed')
                OR (NEW.failed_step IS NULL) <> (NEW.error_code IS NULL)
                OR (NEW.status = 'failed' AND NEW.failed_step IS NULL)
                OR (
                    NEW.status = 'active'
                    AND NEW.failed_step IS NOT NULL
                    AND NEW.replacement_step IS NULL
                    AND NEW.target_set_step IS NULL
                )
                OR (NEW.replaces_route_id IS NOT NULL AND NEW.replaced_by_route_id IS NOT NULL)
                OR (
                    NEW.status IN ('active', 'activating')
                    AND (
                        SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id
                    ) = 0
                    AND NOT (
                        NEW.provenance = 'explicit'
                        AND NEW.cluster_id IS NOT NULL
                        AND NEW.target_set_step IS NOT NULL
                    )
                    AND NEW.kind = 'app'
                )
                OR ((SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id) > 1 AND (
                    NEW.provenance <> 'explicit'
                    OR NEW.cluster_id IS NULL
                    OR EXISTS (
                        SELECT 1
                        FROM route_targets
                        JOIN instances ON instances.id = route_targets.instance_id
                        JOIN active_app_prod_nodes
                            ON active_app_prod_nodes.node_id = instances.node_id
                        WHERE route_targets.route_id = NEW.id
                            AND (
                                instances.project_id <> NEW.project_id
                                OR NOT EXISTS (SELECT 1 FROM node_roles AS production_role WHERE production_role.node_id = instances.node_id AND production_role.role = 'app-prod' AND production_role.status = 'active')
                                OR active_app_prod_nodes.cluster_id IS NOT NEW.cluster_id
                                OR active_app_prod_nodes.active <> 1
                            )
                    )
                    OR (SELECT MIN(position) FROM route_targets WHERE route_id = NEW.id) <> 0
                    OR (SELECT MAX(position) FROM route_targets WHERE route_id = NEW.id)
                        <> (SELECT COUNT(*) - 1 FROM route_targets WHERE route_id = NEW.id)
                ))
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END;
            SQL);
    }

    public function down(): void
    {
        if (DB::table('routes')->where('kind', 'custom_proxy')->where('publication', 'public')->exists()) {
            throw new RuntimeException('Remove public Process Routes before rolling back.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER routes_contract_insert;
            DROP TRIGGER routes_contract_update;
            CREATE TRIGGER routes_contract_insert
            BEFORE INSERT ON routes
            WHEN (
                NEW.kind NOT IN ('app', 'custom_proxy', 'analytics_tracking')
                OR (NEW.kind = 'app' AND NEW.project_id IS NULL)
                OR (
                    NEW.kind = 'custom_proxy'
                    AND (
                        NEW.project_id IS NOT NULL
                        OR NEW.node_id IS NULL
                        OR NEW.cluster_id IS NOT NULL
                        OR NEW.provenance <> 'explicit'
                        OR NEW.publication <> 'private'
                        OR NEW.generation_basis_node_id IS NOT NULL
                    )
                )
                OR (
                    NEW.kind = 'analytics_tracking'
                    AND (
                        NEW.project_id IS NOT NULL
                        OR NEW.provenance <> 'explicit'
                        OR NEW.generation_basis_node_id IS NOT NULL
                        OR (NEW.node_id IS NOT NULL AND NEW.publication <> 'private')
                    )
                )
                OR (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
                OR (NEW.provenance = 'generated' AND NEW.generation_basis_node_id IS NULL)
                OR (NEW.provenance = 'explicit' AND NEW.generation_basis_node_id IS NOT NULL)
                OR NEW.status NOT IN ('pending', 'active', 'activating', 'retiring', 'failed')
                OR NEW.status IN ('active', 'activating', 'retiring')
                OR (NEW.status = 'failed') <> (NEW.failed_step IS NOT NULL AND NEW.error_code IS NOT NULL)
                OR (NEW.replaces_route_id IS NOT NULL AND NEW.replaced_by_route_id IS NOT NULL)
                OR (NEW.replaces_route_id IS NOT NULL AND NEW.replacement_step IS NULL)
                OR EXISTS (
                    SELECT 1 FROM routes
                    WHERE routes.domain = NEW.domain
                )
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END;
            CREATE TRIGGER routes_contract_update
            BEFORE UPDATE ON routes
            WHEN (
                NEW.kind <> OLD.kind
                OR NEW.kind NOT IN ('app', 'custom_proxy', 'analytics_tracking')
                OR (NEW.kind = 'app' AND NEW.project_id IS NULL)
                OR (NEW.project_id IS NOT OLD.project_id)
                OR NEW.provenance <> OLD.provenance
                OR NEW.domain <> OLD.domain
                OR (NEW.node_id IS NULL) = (NEW.cluster_id IS NULL)
                OR (
                    NEW.kind = 'custom_proxy'
                    AND (
                        NEW.project_id IS NOT NULL
                        OR NEW.node_id IS NULL
                        OR NEW.cluster_id IS NOT NULL
                        OR NEW.publication <> 'private'
                    )
                )
                OR (
                    NEW.kind = 'analytics_tracking'
                    AND (
                        NEW.project_id IS NOT NULL
                        OR (NEW.node_id IS NOT NULL AND NEW.publication <> 'private')
                    )
                )
                OR (NEW.provenance = 'generated' AND NEW.generation_basis_node_id IS NULL)
                OR (NEW.provenance = 'explicit' AND NEW.generation_basis_node_id IS NOT NULL)
                OR NEW.status NOT IN ('pending', 'active', 'activating', 'retiring', 'failed')
                OR (NEW.failed_step IS NULL) <> (NEW.error_code IS NULL)
                OR (NEW.status = 'failed' AND NEW.failed_step IS NULL)
                OR (
                    NEW.status = 'active'
                    AND NEW.failed_step IS NOT NULL
                    AND NEW.replacement_step IS NULL
                    AND NEW.target_set_step IS NULL
                )
                OR (NEW.replaces_route_id IS NOT NULL AND NEW.replaced_by_route_id IS NOT NULL)
                OR (
                    NEW.status IN ('active', 'activating')
                    AND (
                        SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id
                    ) = 0
                    AND NOT (
                        NEW.provenance = 'explicit'
                        AND NEW.cluster_id IS NOT NULL
                        AND NEW.target_set_step IS NOT NULL
                    )
                    AND NEW.kind = 'app'
                )
                OR ((SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.id) > 1 AND (
                    NEW.provenance <> 'explicit'
                    OR NEW.cluster_id IS NULL
                    OR EXISTS (
                        SELECT 1
                        FROM route_targets
                        JOIN instances ON instances.id = route_targets.instance_id
                        JOIN active_app_prod_nodes
                            ON active_app_prod_nodes.node_id = instances.node_id
                        WHERE route_targets.route_id = NEW.id
                            AND (
                                instances.project_id <> NEW.project_id
                                OR NOT EXISTS (SELECT 1 FROM node_roles AS production_role WHERE production_role.node_id = instances.node_id AND production_role.role = 'app-prod' AND production_role.status = 'active')
                                OR active_app_prod_nodes.cluster_id IS NOT NEW.cluster_id
                                OR active_app_prod_nodes.active <> 1
                            )
                    )
                    OR (SELECT MIN(position) FROM route_targets WHERE route_id = NEW.id) <> 0
                    OR (SELECT MAX(position) FROM route_targets WHERE route_id = NEW.id)
                        <> (SELECT COUNT(*) - 1 FROM route_targets WHERE route_id = NEW.id)
                ))
            )
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Route persistence contract.');
            END;
            SQL);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Successful container creation and migration publish inline, without a later policy Route receipt. */
return new class extends Migration
{
    public function up(): void
    {
        // Repair only completed defaults. Keep every ambiguous or still-publishing projection pending.
        DB::statement(<<<'SQL'
            UPDATE instances SET development_projection_pending = 0
            WHERE development_projection_pending = 1 AND name = 'default' AND status = 'active'
                AND EXISTS (
                    SELECT 1 FROM instance_runtimes
                    WHERE instance_runtimes.instance_id = instances.id AND instance_runtimes.seed_commit IS NOT NULL
                )
                AND EXISTS (
                    SELECT 1 FROM node_roles JOIN nodes ON nodes.id = node_roles.node_id
                    WHERE nodes.id = instances.node_id AND nodes.status = 'active'
                        AND node_roles.role = 'app-dev' AND node_roles.status = 'active'
                )
                AND NOT EXISTS (
                    SELECT 1 FROM node_roles WHERE node_roles.node_id = instances.node_id
                        AND node_roles.role = 'app-prod' AND node_roles.status IN ('active', 'removing')
                )
                AND (
                    EXISTS (
                        SELECT 1 FROM routes JOIN route_targets ON route_targets.route_id = routes.id
                        WHERE route_targets.instance_id = instances.id
                            AND routes.project_id = instances.project_id AND routes.node_id = instances.node_id
                            AND routes.web_root IS NULL AND routes.status = 'active' AND routes.sites_published = 1
                    )
                    OR (
                        NOT EXISTS (SELECT 1 FROM route_targets WHERE route_targets.instance_id = instances.id)
                        AND EXISTS (SELECT 1 FROM projects WHERE projects.id = instances.project_id
                            AND projects.type IN ('monorepo', 'laravel-package', 'node-package'))
                    )
                )
                AND NOT EXISTS (
                    SELECT 1 FROM routes JOIN route_targets ON route_targets.route_id = routes.id
                    WHERE route_targets.instance_id = instances.id
                        AND (routes.status != 'active' OR routes.sites_published != 1
                            OR routes.failed_step IS NOT NULL OR routes.error_code IS NOT NULL
                            OR routes.replacement_step IS NOT NULL OR routes.replaces_route_id IS NOT NULL
                            OR routes.replaced_by_route_id IS NOT NULL OR routes.transition_node_id IS NOT NULL
                            OR routes.transition_cluster_id IS NOT NULL OR routes.target_set_step IS NOT NULL
                            OR routes.target_set_intent IS NOT NULL)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM operations
                    WHERE operations.status NOT IN ('succeeded', 'failed', 'cancelled', 'interrupted')
                        AND (
                            (operations.target_type = 'instance' AND operations.target_id = instances.id
                                AND operations.kind IN ('instance.create', 'instance.clone', 'instance.migrate',
                                    'instance.destroy', 'instance.transfer', 'instance.register'))
                            OR (operations.target_type = 'route'
                                AND operations.kind IN ('route.create', 'route.update', 'route.destroy', 'route.target_set')
                                AND operations.target_id IN (SELECT route_id FROM route_targets WHERE instance_id = instances.id))
                        )
                )
                AND NOT EXISTS (SELECT 1 FROM route_publication_holds WHERE route_publication_holds.node_id = instances.node_id)
            SQL);
    }

    public function down(): void
    {
        // A completed projection must not become pending again on rollback.
    }
};

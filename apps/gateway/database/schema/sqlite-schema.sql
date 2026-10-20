CREATE TABLE IF NOT EXISTS `migrations`(
  `id` integer not null primary key autoincrement,
  `migration` varchar not null,
  `batch` integer not null
);
CREATE TABLE IF NOT EXISTS `horizon_new_dawn_batch_metadata`(
  `batch_id` varchar not null,
  `queue` varchar,
  `connection` varchar,
  `queue_is_explicit` tinyint(1) not null,
  `connection_is_explicit` tinyint(1) not null,
  primary key(`batch_id`)
);
CREATE INDEX `hnd_batch_metadata_queue_index` on `horizon_new_dawn_batch_metadata`(
  `queue`
);
CREATE INDEX `hnd_batch_metadata_connection_index` on `horizon_new_dawn_batch_metadata`(
  `connection`
);
CREATE TABLE IF NOT EXISTS `firewall_rules`(
  `id` integer not null primary key autoincrement,
  `node_id` integer not null,
  `name` varchar not null,
  `action` varchar not null,
  `source` varchar,
  `protocol` varchar not null,
  `port` varchar not null,
  `status` varchar not null default 'provisioning',
  `failed_step` varchar,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`node_id`) references `nodes`(`id`) on delete restrict
);
CREATE UNIQUE INDEX `firewall_rules_node_id_name_unique` on `firewall_rules`(
  `node_id`,
  `name`
);
CREATE INDEX `firewall_rules_status_index` on `firewall_rules`(`status`);
CREATE TABLE IF NOT EXISTS `settings`(
  `id` integer not null primary key autoincrement,
  `scope_type` varchar not null,
  `scope_id` integer not null default '0',
  `key` varchar not null,
  `value` text,
  `is_secret` tinyint(1) not null default '0',
  `created_at` datetime,
  `updated_at` datetime
);
CREATE UNIQUE INDEX `settings_scope_type_scope_id_key_unique` on `settings`(
  `scope_type`,
  `scope_id`,
  `key`
);
CREATE INDEX `settings_scope_type_index` on `settings`(`scope_type`);
CREATE TABLE IF NOT EXISTS `activity_log`(
  `id` integer not null primary key autoincrement,
  `log_name` varchar,
  `description` text not null,
  `subject_type` varchar,
  `subject_id` integer,
  `causer_type` varchar,
  `causer_id` integer,
  `event` varchar,
  `properties` text,
  `attribute_changes` text,
  `batch_uuid` varchar,
  `request_id` varchar not null,
  `command` varchar not null,
  `caller_node_id` integer,
  `target_node_id` integer,
  `caller_ip` varchar,
  `status` varchar not null,
  `duration_ms` integer,
  `exit_code` integer,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`caller_node_id`) references `nodes`(`id`) on delete set null,
  foreign key(`target_node_id`) references `nodes`(`id`) on delete set null
);
CREATE INDEX `subject` on `activity_log`(`subject_type`, `subject_id`);
CREATE INDEX `causer` on `activity_log`(`causer_type`, `causer_id`);
CREATE INDEX `activity_log_log_name_index` on `activity_log`(`log_name`);
CREATE INDEX `activity_log_request_id_index` on `activity_log`(`request_id`);
CREATE INDEX `activity_log_command_index` on `activity_log`(`command`);
CREATE INDEX `activity_log_status_index` on `activity_log`(`status`);
CREATE INDEX `activity_log_error_code_index` on `activity_log`(`error_code`);
CREATE TABLE IF NOT EXISTS `node_access`(
  `id` integer not null primary key autoincrement,
  `consumer_node_id` integer not null,
  `serving_node_id` integer not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`consumer_node_id`) references `nodes`(`id`) on delete cascade,
  foreign key(`serving_node_id`) references `nodes`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `node_access_consumer_node_id_serving_node_id_unique` on `node_access`(
  `consumer_node_id`,
  `serving_node_id`
);
CREATE TABLE IF NOT EXISTS `tool_managers`(
  `id` integer not null primary key autoincrement,
  `node_id` integer not null,
  `name` varchar not null,
  `status` varchar not null,
  `installed_version` varchar,
  `failed_step` varchar,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`node_id`) references `nodes`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `tool_managers_node_id_name_unique` on `tool_managers`(
  `node_id`,
  `name`
);
CREATE TABLE IF NOT EXISTS `tools`(
  `id` integer not null primary key autoincrement,
  `node_id` integer not null,
  `tool_manager_id` integer not null,
  `package` varchar not null,
  `version_constraint` varchar,
  `status` varchar not null,
  `installed_version` varchar,
  `failed_operation` varchar,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`node_id`) references `nodes`(`id`) on delete cascade,
  foreign key(`tool_manager_id`) references `tool_managers`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `tools_node_id_tool_manager_id_package_unique` on `tools`(
  `node_id`,
  `tool_manager_id`,
  `package`
);
CREATE INDEX `tools_node_id_status_index` on `tools`(`node_id`, `status`);
CREATE TABLE IF NOT EXISTS `clusters`(
  `id` integer not null primary key autoincrement,
  `name` varchar not null,
  `tld` varchar,
  `state` varchar not null check(`state` in('inactive', 'active')) default 'inactive',
  `created_at` datetime,
  `updated_at` datetime
);
CREATE UNIQUE INDEX `clusters_name_unique` on `clusters`(`name`);
CREATE UNIQUE INDEX `clusters_tld_unique` on `clusters`(`tld`);
CREATE INDEX `clusters_state_index` on `clusters`(`state`);
CREATE TABLE IF NOT EXISTS `nodes`(
  `id` integer not null primary key autoincrement,
  `name` varchar not null,
  `status` varchar not null default('provisioning'),
  `platform` varchar not null default('linux'),
  `architecture` varchar,
  `public_ssh_host` varchar not null,
  `public_ssh_port` integer not null default('22'),
  `wireguard_public_key` text,
  `wireguard_endpoint_override` varchar,
  `dns_server_override` varchar,
  `ssh_host_key_type` varchar,
  `ssh_host_key` text,
  `ssh_host_fingerprint` varchar,
  `failed_step` varchar,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  `tld` varchar,
  `user` varchar not null default('orbit'),
  `settings` text,
  `cluster_id` integer,
  `wireguard_ip` varchar,
  `lan_ip` varchar,
  `agent_secret_hash` varchar,
  ssh_jump_node_id integer NULL REFERENCES nodes(id) ON DELETE SET NULL,
  foreign key(`cluster_id`) references `clusters`(`id`) on delete restrict
);
CREATE UNIQUE INDEX `nodes_name_unique` on `nodes`(`name`);
CREATE INDEX `nodes_status_index` on `nodes`(`status`);
CREATE UNIQUE INDEX `nodes_tld_unique` on `nodes`(`tld`);
CREATE UNIQUE INDEX `nodes_wireguard_ip_unique` on `nodes`(`wireguard_ip`);
CREATE TABLE IF NOT EXISTS `node_roles`(
  `id` integer not null primary key autoincrement,
  `node_id` integer not null,
  `role` varchar not null,
  `status` varchar not null default('provisioning'),
  `failed_step` varchar,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  `cluster_id` integer,
  foreign key(`node_id`) references nodes(`id`) on delete restrict on update no action,
  foreign key(`cluster_id`) references `clusters`(`id`) on delete restrict
);
CREATE UNIQUE INDEX `node_roles_node_id_role_unique` on `node_roles`(
  `node_id`,
  `role`
);
CREATE INDEX `node_roles_role_index` on `node_roles`(`role`);
CREATE INDEX `node_roles_status_index` on `node_roles`(`status`);
CREATE UNIQUE INDEX nodes_cluster_lan_ip_unique
ON nodes(
  cluster_id,
  lan_ip
)
WHERE cluster_id IS NOT NULL AND lan_ip IS NOT NULL;
CREATE UNIQUE INDEX node_roles_cluster_router_unique
ON node_roles(
  cluster_id
)
WHERE role = 'router' AND status = 'active';
CREATE UNIQUE INDEX node_roles_cluster_ingress_active_unique
ON node_roles(
  cluster_id
)
WHERE role = 'ingress' AND status = 'active';
CREATE TABLE IF NOT EXISTS `route_targets`(
  `id` integer not null primary key autoincrement,
  `route_id` integer not null,
  `instance_id` integer not null,
  `position` integer not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`route_id`) references `routes`(`id`) on delete cascade,
  foreign key(`instance_id`) references `instances`(`id`) on delete cascade
);
CREATE TABLE IF NOT EXISTS `projects`(
  `id` integer not null primary key autoincrement,
  `name` varchar not null,
  `slug` varchar not null,
  `repository_url` text not null,
  `created_at` datetime,
  `updated_at` datetime,
  `default_branch` varchar,
  `root` varchar,
  `repository_identity` text not null,
  `type` varchar not null default 'laravel-app',
  `code` varchar,
  `task_check` text,
  `task_workspace_routed` tinyint(1) not null default '1',
  `source_access` varchar not null default 'github_app',
  `review_and_merge` tinyint(1) not null default '0',
  `merge_check` varchar
);
CREATE TABLE IF NOT EXISTS `instances`(
  `id` integer not null primary key autoincrement,
  `project_id` integer not null,
  `node_id` integer not null,
  `name` varchar not null,
  `checkout_path` text not null,
  `root` varchar,
  `branch` varchar,
  `starting_commit` varchar,
  `status` varchar not null check(`status` in('reserved', 'checkout_prepared', 'source_resolved', 'active', 'removing')) default 'reserved',
  `created_at` datetime,
  `updated_at` datetime,
  `selected_php_version` varchar,
  `provisioning_step` varchar,
  `failed_step` varchar,
  `error_code` varchar,
  `source_layout` varchar not null default('checkout'),
  `branch_override` varchar,
  `registration_original_path` text,
  `registration_request_id` varchar,
  `registration_primary` tinyint(1) not null default '0',
  `registration_include_worktrees` tinyint(1) not null default '0',
  `registration_repository_url` text,
  `registration_repository_identity` varchar,
  `registration_source_digest` varchar,
  `registration_detached` tinyint(1) not null default '0',
  `registration_default_branch` varchar,
  `registration_inferred_slug` varchar,
  `registration_inferred_root` varchar,
  `registration_common_repository_path` text,
  `registration_worktree_paths` text,
  `registration_relocation_state` varchar,
  `registration_authoritative_path` text,
  `registration_route_domain` varchar,
  `registration_route_provenance` varchar,
  `registration_completed_at` datetime,
  `source_is_laravel` tinyint(1),
  `production_user` varchar,
  `production_home` text,
  `registration_source_device` integer,
  `registration_source_inode` integer,
  `production_php_service` varchar,
  `production_php_pool` varchar,
  `production_php_socket` text,
  `deployment_branch` varchar,
  `runtime_definitions_captured_at` datetime,
  `clone_candidate_id` integer,
  `clone_candidate_commit` varchar,
  `clone_requested_branch` varchar,
  `clone_preview_name` varchar,
  `clone_preview_domain` varchar,
  `clone_sqlite_source_path` text,
  `clone_completed_at` datetime,
  `vite_port` integer,
  `task_workspace_routed` tinyint(1),
  `development_release_layout` tinyint(1) not null default '0',
  `source_prepare_id` varchar,
  `seed_path` text,
  `seed_commit` varchar,
  `seed_repository` text,
  `seed_selected` tinyint(1) not null default '0',
  `development_projection_pending` tinyint(1) not null default '1',
  `dependency_sub_projects` text,
  `shared_paths` text,
  `first_setup_pending` tinyint(1) not null default '0',
  `ssr_port` integer,
  `environment_owned_keys` text,
  `auto_deploy` tinyint(1) not null default '0',
  `auto_deploy_resumed_after` integer not null default '0',
  `auto_deploy_state` text,
  `vp_environment` text,
  `environment_runtime_pending_at` datetime,
  task_workspace_owner_id integer NULL REFERENCES tasks(id) ON DELETE SET NULL,
  foreign key(`node_id`) references nodes(`id`) on delete restrict on update no action,
  foreign key(`project_id`) references `projects`(`id`) on delete restrict on update no action
);
CREATE TABLE IF NOT EXISTS `instance_removals`(
  `id` varchar not null,
  `requested_instance_id` integer not null,
  `requested_name` varchar not null,
  `force` tinyint(1) not null,
  `inventory_digest` varchar not null,
  `total` integer not null,
  `status` varchar not null check(`status` in('removing', 'failed', 'completed')),
  `current_step` varchar,
  `failed_step` varchar,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  primary key(`id`)
);
CREATE TABLE IF NOT EXISTS `instance_removal_members`(
  `id` integer not null primary key autoincrement,
  `instance_removal_id` varchar not null,
  `position` integer not null,
  `instance_id` integer not null,
  `project_id` integer not null,
  `node_id` integer not null,
  `route_id` integer,
  `name` varchar not null,
  `environment` varchar not null,
  `source_layout` varchar not null,
  `repository_identity` varchar,
  `checkout_path` text,
  `root` text,
  `branch` varchar,
  `starting_commit` varchar,
  `common_repository_path` text,
  `source_identity` varchar,
  `linked_worktree_paths` text not null,
  `source_digest` varchar not null,
  `source_prepared_at` datetime,
  `route_cleared_at` datetime,
  `route_outcome` varchar,
  `source_finalized_at` datetime,
  `finalization_receipt` varchar,
  `runtime_cleaned_at` datetime,
  `row_deleted_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  `source_commit` varchar,
  `runtime_published` tinyint(1) not null default '1',
  foreign key(`instance_removal_id`) references `instance_removals`(`id`) on delete cascade
);
CREATE TABLE IF NOT EXISTS `active_app_prod_nodes`(
  `node_id` integer not null,
  `cluster_id` integer,
  `active` tinyint(1) not null default '0',
  primary key(`node_id`)
);
CREATE TABLE IF NOT EXISTS `instance_environment_values`(
  `id` integer not null primary key autoincrement,
  `instance_id` integer not null,
  `env_key` varchar not null,
  `env_value` text not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`instance_id`) references `instances`(`id`) on delete cascade
);
CREATE TABLE IF NOT EXISTS `schedules`(
  `id` varchar not null,
  `target_type` varchar not null,
  `target_id` integer not null,
  `host_node_id` integer not null,
  `name` varchar not null,
  `calendar` varchar not null,
  `command` text not null,
  `timeout_seconds` integer not null default '3600',
  `desired_timer_state` varchar not null,
  `status` varchar not null default 'provisioning',
  `failed_step` varchar,
  `error_code` varchar,
  `last_run_at` datetime,
  `last_run_status` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  `source_definition_id` varchar,
  foreign key(`host_node_id`) references `nodes`(`id`) on delete restrict,
  primary key(`id`)
);
CREATE INDEX `schedules_target_type_target_id_index` on `schedules`(
  `target_type`,
  `target_id`
);
CREATE UNIQUE INDEX `schedules_target_type_target_id_name_unique` on `schedules`(
  `target_type`,
  `target_id`,
  `name`
);
CREATE INDEX `schedules_status_index` on `schedules`(`status`);
CREATE TABLE IF NOT EXISTS `process_definitions`(
  `id` varchar not null,
  `project_id` integer not null,
  `name` varchar not null,
  `environments` text not null,
  `spec` text not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`project_id`) references `projects`(`id`) on delete cascade,
  primary key(`id`)
);
CREATE TABLE IF NOT EXISTS `schedule_definitions`(
  `id` varchar not null,
  `project_id` integer not null,
  `name` varchar not null,
  `environments` text not null,
  `spec` text not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`project_id`) references `projects`(`id`) on delete cascade,
  primary key(`id`)
);
CREATE UNIQUE INDEX `schedules_target_definition_unique` on `schedules`(
  `target_type`,
  `target_id`,
  `source_definition_id`
);
CREATE TABLE IF NOT EXISTS `database_connection_targets`(
  `id` integer not null primary key autoincrement,
  `database_connection_id` integer not null,
  `instance_id` integer not null,
  `prefix` varchar not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`database_connection_id`) references `database_connections`(`id`) on delete restrict,
  foreign key(`instance_id`) references `instances`(`id`) on delete cascade
);
CREATE TABLE IF NOT EXISTS `instance_deploy_steps`(
  `id` integer not null primary key autoincrement,
  `instance_id` integer not null,
  `name` varchar not null,
  `phase` varchar not null,
  `command` text not null,
  `timeout_seconds` integer not null,
  `position` integer not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`instance_id`) references `instances`(`id`) on delete cascade
);
CREATE TRIGGER active_app_prod_nodes_node_delete
AFTER DELETE ON nodes
BEGIN
    DELETE FROM active_app_prod_nodes WHERE node_id = OLD.id;
END;
CREATE TRIGGER active_app_prod_nodes_node_insert
AFTER INSERT ON nodes
BEGIN
    INSERT INTO active_app_prod_nodes (node_id, cluster_id, active)
    VALUES (NEW.id, NEW.cluster_id, 0);
END;
CREATE TRIGGER active_app_prod_nodes_node_update
AFTER UPDATE OF id, cluster_id, status ON nodes
BEGIN
    UPDATE active_app_prod_nodes
    SET node_id = NEW.id,
        cluster_id = NEW.cluster_id,
        active = CASE WHEN NEW.status = 'active' AND EXISTS (
            SELECT 1 FROM node_roles
            WHERE node_roles.node_id = NEW.id
                AND node_roles.role = 'app-prod'
                AND node_roles.status = 'active'
        ) THEN 1 ELSE 0 END
    WHERE node_id = OLD.id;
END;
CREATE TRIGGER active_app_prod_nodes_role_delete
AFTER DELETE ON node_roles
BEGIN
    UPDATE active_app_prod_nodes
    SET active = CASE WHEN EXISTS (
        SELECT 1 FROM nodes
        WHERE nodes.id = OLD.node_id AND nodes.status = 'active'
    ) AND EXISTS (
        SELECT 1 FROM node_roles
        WHERE node_roles.node_id = OLD.node_id
            AND node_roles.role = 'app-prod'
            AND node_roles.status = 'active'
    ) THEN 1 ELSE 0 END
    WHERE node_id = OLD.node_id;
END;
CREATE TRIGGER active_app_prod_nodes_role_insert
AFTER INSERT ON node_roles
BEGIN
    UPDATE active_app_prod_nodes
    SET active = CASE WHEN EXISTS (
        SELECT 1 FROM nodes
        WHERE nodes.id = NEW.node_id AND nodes.status = 'active'
    ) AND EXISTS (
        SELECT 1 FROM node_roles
        WHERE node_roles.node_id = NEW.node_id
            AND node_roles.role = 'app-prod'
            AND node_roles.status = 'active'
    ) THEN 1 ELSE 0 END
    WHERE node_id = NEW.node_id;
END;
CREATE TRIGGER active_app_prod_nodes_role_update
AFTER UPDATE OF node_id, role, status ON node_roles
BEGIN
    UPDATE active_app_prod_nodes
    SET active = CASE WHEN EXISTS (
        SELECT 1 FROM nodes
        WHERE nodes.id = OLD.node_id AND nodes.status = 'active'
    ) AND EXISTS (
        SELECT 1 FROM node_roles
        WHERE node_roles.node_id = OLD.node_id
            AND node_roles.role = 'app-prod'
            AND node_roles.status = 'active'
    ) THEN 1 ELSE 0 END
    WHERE node_id = OLD.node_id;
    UPDATE active_app_prod_nodes
    SET active = CASE WHEN EXISTS (
        SELECT 1 FROM nodes
        WHERE nodes.id = NEW.node_id AND nodes.status = 'active'
    ) AND EXISTS (
        SELECT 1 FROM node_roles
        WHERE node_roles.node_id = NEW.node_id
            AND node_roles.role = 'app-prod'
            AND node_roles.status = 'active'
    ) THEN 1 ELSE 0 END
    WHERE node_id = NEW.node_id;
END;
CREATE TRIGGER node_roles_cluster_ownership_insert
BEFORE INSERT ON node_roles
WHEN (
    (NEW.role IN ('router', 'ingress') AND (
        NEW.cluster_id IS NULL
        OR NEW.cluster_id IS NOT (SELECT cluster_id FROM nodes WHERE id = NEW.node_id)
    ))
    OR (NEW.role NOT IN ('router', 'ingress') AND NEW.cluster_id IS NOT NULL)
)
BEGIN
    SELECT RAISE(ABORT, 'Invalid Cluster role ownership.');
END;
CREATE TRIGGER node_roles_cluster_ownership_update
BEFORE UPDATE OF node_id, cluster_id, role ON node_roles
WHEN (
    (NEW.role IN ('router', 'ingress') AND (
        NEW.cluster_id IS NULL
        OR NEW.cluster_id IS NOT (SELECT cluster_id FROM nodes WHERE id = NEW.node_id)
    ))
    OR (NEW.role NOT IN ('router', 'ingress') AND NEW.cluster_id IS NOT NULL)
)
BEGIN
    SELECT RAISE(ABORT, 'Invalid Cluster role ownership.');
END;
CREATE TRIGGER nodes_cluster_role_ownership_update
BEFORE UPDATE OF cluster_id ON nodes
WHEN NEW.cluster_id IS NOT OLD.cluster_id
    AND EXISTS (
        SELECT 1
        FROM node_roles
        WHERE node_id = OLD.id
            AND role IN ('router', 'ingress')
    )
BEGIN
    SELECT RAISE(ABORT, 'Cluster role assignment prevents membership changes.');
END;
CREATE TABLE IF NOT EXISTS `processes`(
  `id` integer not null primary key autoincrement,
  `owner_type` varchar not null,
  `owner_id` integer not null,
  `name` varchar not null,
  `runtime` varchar not null,
  `working_directory` text,
  `runtime_config` text not null,
  `restart_policy` varchar not null default('unless-stopped'),
  `desired_state` varchar not null default('stopped'),
  `status` varchar not null default('provisioning'),
  `failed_step` varchar,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  `source_definition_id` varchar,
  `keep_alive` tinyint(1) not null default('0'),
  `retired_runtime` varchar,
  `systemd_activation_hash` varchar,
  `systemd_activation_pending` tinyint(1) not null default '0',
  `restart_on_deploy` tinyint(1) not null default '1'
);
CREATE UNIQUE INDEX `processes_owner_definition_unique` on `processes`(
  `owner_type`,
  `owner_id`,
  `source_definition_id`
);
CREATE INDEX `processes_owner_type_owner_id_index` on `processes`(
  `owner_type`,
  `owner_id`
);
CREATE UNIQUE INDEX `processes_owner_type_owner_id_name_unique` on `processes`(
  `owner_type`,
  `owner_id`,
  `name`
);
CREATE INDEX `processes_status_index` on `processes`(`status`);
CREATE TABLE IF NOT EXISTS `project_updates`(
  `id` integer not null primary key autoincrement,
  `project_id` integer not null,
  `status` varchar not null,
  `fingerprint` varchar not null,
  `requested_slug` varchar,
  `requested_repository_url` varchar,
  `requested_default_branch` varchar,
  `requested_root` varchar,
  `previous_slug` varchar not null,
  `previous_repository_url` varchar not null,
  `previous_default_branch` varchar,
  `previous_root` varchar,
  `inventory` text,
  `evidence` text,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`project_id`) references `projects`(`id`) on delete cascade
);
CREATE TABLE IF NOT EXISTS `dependency_packages`(
  `id` integer not null primary key autoincrement,
  `ecosystem` varchar not null check(`ecosystem` in('composer', 'npm')),
  `name` varchar not null,
  `created_at` datetime,
  `updated_at` datetime
);
CREATE UNIQUE INDEX `dependency_packages_ecosystem_name_unique` on `dependency_packages`(
  `ecosystem`,
  `name`
);
CREATE UNIQUE INDEX `dependency_packages_id_ecosystem_unique` on `dependency_packages`(
  `id`,
  `ecosystem`
);
CREATE TABLE IF NOT EXISTS `instance_dependency_observations`(
  `id` integer not null primary key autoincrement,
  `instance_id` integer not null,
  `ecosystem` varchar not null check(`ecosystem` in('composer', 'npm')),
  `present` tinyint(1) not null,
  `observed_at` datetime not null,
  `project_root` text not null,
  `source_reference` text,
  `file_hashes` text not null,
  `format` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  `sub_project` varchar not null default '',
  foreign key(`instance_id`) references `instances`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `dependency_observation_id_ecosystem` on `instance_dependency_observations`(
  `id`,
  `ecosystem`
);
CREATE TABLE IF NOT EXISTS `instance_dependency_resolutions`(
  `id` integer not null primary key autoincrement,
  `observation_id` integer not null,
  `dependency_package_id` integer not null,
  `ecosystem` varchar not null check(`ecosystem` in('composer', 'npm')),
  `locator` text not null,
  `version` text not null,
  `regular` tinyint(1) not null,
  `development` tinyint(1) not null,
  `source_reference` text,
  `integrity` text,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`observation_id`, `ecosystem`) references `instance_dependency_observations`(`id`, `ecosystem`) on delete cascade,
  foreign key(`dependency_package_id`, `ecosystem`) references `dependency_packages`(`id`, `ecosystem`) on delete restrict
);
CREATE UNIQUE INDEX `dependency_resolution_locator` on `instance_dependency_resolutions`(
  `observation_id`,
  `locator`
);
CREATE UNIQUE INDEX `dependency_resolution_observation_id` on `instance_dependency_resolutions`(
  `observation_id`,
  `id`
);
CREATE INDEX `dependency_resolution_package_lookup` on `instance_dependency_resolutions`(
  `dependency_package_id`,
  `ecosystem`
);
CREATE TABLE IF NOT EXISTS `instance_dependency_edges`(
  `id` integer not null primary key autoincrement,
  `observation_id` integer not null,
  `from_resolution_id` integer,
  `to_resolution_id` integer,
  `name` varchar not null,
  `constraint` text not null,
  `kind` varchar not null check(`kind` in('dependency', 'peer')),
  `scope` varchar not null check(`scope` in('regular', 'development')),
  `optional` tinyint(1) not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`observation_id`) references `instance_dependency_observations`(`id`) on delete cascade,
  foreign key(`observation_id`, `from_resolution_id`) references `instance_dependency_resolutions`(`observation_id`, `id`) on delete cascade,
  foreign key(`observation_id`, `to_resolution_id`) references `instance_dependency_resolutions`(`observation_id`, `id`) on delete cascade
);
CREATE INDEX `dependency_edge_from_lookup` on `instance_dependency_edges`(
  `observation_id`,
  `from_resolution_id`
);
CREATE INDEX `dependency_edge_to_lookup` on `instance_dependency_edges`(
  `observation_id`,
  `to_resolution_id`
);
CREATE UNIQUE INDEX dependency_edge_identity ON `instance_dependency_edges`(
  observation_id,
  (from_resolution_id IS NULL),
  COALESCE(from_resolution_id, 0),
  (to_resolution_id IS NULL),
  COALESCE(to_resolution_id, 0),
  name,
  `constraint`,
  kind,
  scope,
  optional
);
CREATE TABLE IF NOT EXISTS `instance_dependency_scan_attempts`(
  `id` integer not null primary key autoincrement,
  `instance_id` integer not null,
  `ecosystem` varchar not null check(`ecosystem` in('composer', 'npm')),
  `attempted_at` datetime not null,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  `sub_project` varchar not null default '',
  `error_detail` varchar,
  foreign key(`instance_id`) references `instances`(`id`) on delete cascade
);
CREATE INDEX `dependency_attempt_latest` on `instance_dependency_scan_attempts`(
  `instance_id`,
  `ecosystem`,
  `id`
);
CREATE TABLE IF NOT EXISTS `vite_port_assignments`(
  `instance_id` integer not null,
  `node_id` integer not null,
  `port` integer not null,
  foreign key(`instance_id`) references `instances`(`id`) on delete cascade,
  foreign key(`node_id`) references `nodes`(`id`) on delete restrict,
  primary key(`instance_id`, `node_id`)
);
CREATE UNIQUE INDEX `vite_port_assignments_node_id_port_unique` on `vite_port_assignments`(
  `node_id`,
  `port`
);
CREATE TABLE IF NOT EXISTS `routes`(
  `id` integer not null primary key autoincrement,
  `project_id` integer,
  `node_id` integer,
  `cluster_id` integer,
  `generation_basis_node_id` integer,
  `domain` varchar not null,
  `provenance` varchar not null,
  `publication` varchar not null,
  `status` varchar not null default('pending'),
  `failed_step` varchar,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  `replaces_route_id` integer,
  `replaced_by_route_id` integer,
  `replacement_step` varchar,
  `target_set_intent` text,
  `target_set_step` varchar,
  `kind` varchar not null default('app'),
  `sites_published` tinyint(1) not null default '0',
  `transition_node_id` integer,
  `transition_cluster_id` integer,
  `transition_dns_moved_at` datetime,
  `web_root` varchar,
  foreign key(`replaced_by_route_id`) references routes(`id`) on delete set null on update no action,
  foreign key(`replaces_route_id`) references routes(`id`) on delete set null on update no action,
  foreign key(`generation_basis_node_id`) references nodes(`id`) on delete restrict on update no action,
  foreign key(`cluster_id`) references clusters(`id`) on delete restrict on update no action,
  foreign key(`node_id`) references nodes(`id`) on delete restrict on update no action,
  foreign key(`project_id`) references `projects`(`id`) on delete restrict on update no action
);
CREATE UNIQUE INDEX `routes_hostname_unique` on `routes`(`domain`);
CREATE INDEX `routes_status_index` on `routes`(`status`);
CREATE TABLE IF NOT EXISTS `route_custom_proxies`(
  `route_id` integer not null,
  `node_id` integer not null,
  `process_id` integer,
  `upstream` varchar not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`node_id`) references `nodes`(`id`) on delete restrict,
  foreign key(`process_id`) references `processes`(`id`) on delete restrict,
  foreign key(`route_id`) references `routes`(`id`) on delete cascade,
  primary key(`route_id`)
);
CREATE TABLE IF NOT EXISTS `instance_deployments`(
  `id` integer not null primary key autoincrement,
  `instance_id` integer not null,
  `release` varchar,
  `branch` varchar,
  `commit` varchar,
  `started_at` datetime not null,
  `finished_at` datetime,
  `duration_seconds` integer,
  `status` varchar not null,
  `failed_step` varchar,
  `error_code` varchar,
  `selected_release` varchar,
  `triggered_by` varchar,
  `events` text,
  `created_at` datetime,
  `updated_at` datetime,
  `kind` varchar not null default 'deploy',
  foreign key(`instance_id`) references `instances`(`id`) on delete cascade
);
CREATE TABLE IF NOT EXISTS `database_users`(
  `id` integer not null primary key autoincrement,
  `database_connection_id` integer not null,
  `username` varchar not null,
  `privileges` text not null,
  `created_by` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  `password` text,
  foreign key(`database_connection_id`) references `database_connections`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `database_users_database_connection_id_username_unique` on `database_users`(
  `database_connection_id`,
  `username`
);
CREATE TABLE IF NOT EXISTS `route_analytics_trackings`(
  `route_id` integer not null,
  `instance_id` integer not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`instance_id`) references `instances`(`id`) on delete cascade,
  foreign key(`route_id`) references `routes`(`id`) on delete cascade,
  primary key(`route_id`)
);
CREATE TABLE IF NOT EXISTS `instance_transfers`(
  `id` varchar not null,
  `instance_id` integer,
  `source_node_id` integer not null,
  `destination_node_id` integer not null,
  `requested_name` varchar,
  `destination_name` varchar not null,
  `destination_path` text not null,
  `destination_domain` varchar not null,
  `sqlite_source_path` text,
  `source_layout` varchar not null,
  `source_path` text not null,
  `common_repository_path` text,
  `source_route_id` integer not null,
  `destination_route_id` integer,
  `status` varchar not null,
  `current_step` varchar not null,
  `failed_step` varchar,
  `error_code` varchar,
  `recovery_evidence` text,
  `cutover_at` datetime,
  `completed_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  `source_router_node_id` integer,
  `imported_environment_keys` text,
  `web_root_route_ids` text,
  foreign key(`instance_id`) references `instances`(`id`) on delete restrict,
  primary key(`id`)
);
CREATE TABLE IF NOT EXISTS `project_lifecycle_steps`(
  `id` integer not null primary key autoincrement,
  `project_id` integer not null,
  `phase` varchar not null,
  `name` varchar not null,
  `command` text not null,
  `timeout_seconds` integer not null,
  `position` integer not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`project_id`) references `projects`(`id`) on delete cascade
);
CREATE TABLE IF NOT EXISTS `project_node_exclusions`(
  `id` integer not null primary key autoincrement,
  `project_id` integer not null,
  `node_id` integer not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`project_id`) references `projects`(`id`) on delete cascade,
  foreign key(`node_id`) references `nodes`(`id`) on delete cascade
);
CREATE TABLE IF NOT EXISTS `task_checks`(
  `id` integer not null primary key autoincrement,
  `task_id` integer not null,
  `task_comment_id` integer,
  `status` varchar not null,
  `pid` integer not null,
  `process_started` varchar not null,
  `head_before` varchar not null,
  `tree_before` varchar not null,
  `head_after` varchar,
  `tree_after` varchar,
  `exit_code` integer,
  `changed_paths` text,
  `output` text,
  `started_at` datetime not null,
  `finished_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  `kind` varchar not null default 'handoff',
  `failed_step` varchar,
  `deliverable_evidence` text,
  foreign key(`task_comment_id`) references task_comments(`id`) on delete cascade on update no action,
  foreign key(`task_id`) references tasks(`id`) on delete cascade on update no action
);
CREATE INDEX `task_checks_task_id_id_index` on `task_checks`(`task_id`, `id`);
CREATE TABLE IF NOT EXISTS `agent_thread_send_leases`(
  `id` integer not null primary key autoincrement,
  `agent_thread_id` integer not null,
  `owner_token` varchar not null,
  `expires_at` datetime not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`agent_thread_id`) references `agent_threads`(`id`) on delete cascade
);
CREATE INDEX `agent_thread_send_lease_active_idx` on `agent_thread_send_leases`(
  `agent_thread_id`,
  `expires_at`
);
CREATE UNIQUE INDEX `agent_thread_send_leases_owner_token_unique` on `agent_thread_send_leases`(
  `owner_token`
);
CREATE INDEX `agent_thread_send_leases_expires_at_index` on `agent_thread_send_leases`(
  `expires_at`
);
CREATE UNIQUE INDEX `instance_deploy_steps_instance_id_name_unique` on `instance_deploy_steps`(
  `instance_id`,
  `name`
);
CREATE UNIQUE INDEX `instance_deploy_steps_instance_id_phase_position_unique` on `instance_deploy_steps`(
  `instance_id`,
  `phase`,
  `position`
);
CREATE INDEX `instance_deployments_instance_id_started_at_index` on `instance_deployments`(
  `instance_id`,
  `started_at`
);
CREATE UNIQUE INDEX `instance_environment_values_instance_id_env_key_unique` on `instance_environment_values`(
  `instance_id`,
  `env_key`
);
CREATE UNIQUE INDEX `instance_removal_members_instance_removal_id_instance_id_unique` on `instance_removal_members`(
  `instance_removal_id`,
  `instance_id`
);
CREATE UNIQUE INDEX `instance_removal_members_instance_removal_id_position_unique` on `instance_removal_members`(
  `instance_removal_id`,
  `position`
);
CREATE UNIQUE INDEX `instance_removal_members_live_unique`
ON `instance_removal_members`(
  `instance_id`
)
WHERE row_deleted_at IS NULL;
CREATE INDEX `instance_removals_status_index` on `instance_removals`(`status`);
CREATE INDEX `instance_transfers_instance_id_status_index` on `instance_transfers`(
  `instance_id`,
  `status`
);
CREATE UNIQUE INDEX `instances_project_id_name_unique` on `instances`(
  `project_id`,
  `name`
);
CREATE INDEX `instances_clone_candidate_id_index` on `instances`(
  `clone_candidate_id`
);
CREATE UNIQUE INDEX `instances_node_id_registration_original_path_unique` on `instances`(
  `node_id`,
  `registration_original_path`
);
CREATE UNIQUE INDEX `instances_node_id_vite_port_unique` on `instances`(
  `node_id`,
  `vite_port`
);
CREATE INDEX `instances_registration_request_id_index` on `instances`(
  `registration_request_id`
);
CREATE INDEX `instances_status_index` on `instances`(`status`);
CREATE INDEX `project_updates_project_id_status_index` on `project_updates`(
  `project_id`,
  `status`
);
CREATE UNIQUE INDEX `projects_code_unique` on `projects`(`code`);
CREATE UNIQUE INDEX `projects_repository_identity_unique` on `projects`(
  `repository_identity`
);
CREATE UNIQUE INDEX `projects_slug_unique` on `projects`(`slug`);
CREATE UNIQUE INDEX `database_connection_targets_instance_id_prefix_unique` on `database_connection_targets`(
  `instance_id`,
  `prefix`
);
CREATE UNIQUE INDEX `process_definitions_project_id_name_unique` on `process_definitions`(
  `project_id`,
  `name`
);
CREATE UNIQUE INDEX `project_lifecycle_steps_project_id_phase_name_unique` on `project_lifecycle_steps`(
  `project_id`,
  `phase`,
  `name`
);
CREATE UNIQUE INDEX `project_lifecycle_steps_project_id_phase_position_unique` on `project_lifecycle_steps`(
  `project_id`,
  `phase`,
  `position`
);
CREATE UNIQUE INDEX `project_node_exclusions_project_id_node_id_unique` on `project_node_exclusions`(
  `project_id`,
  `node_id`
);
CREATE UNIQUE INDEX `route_targets_route_id_instance_id_unique` on `route_targets`(
  `route_id`,
  `instance_id`
);
CREATE UNIQUE INDEX `schedule_definitions_project_id_name_unique` on `schedule_definitions`(
  `project_id`,
  `name`
);
CREATE TRIGGER route_targets_reject_analytics_tracking
BEFORE INSERT ON route_targets
WHEN (SELECT kind FROM routes WHERE id = NEW.route_id) = 'analytics_tracking'
BEGIN
    SELECT RAISE(ABORT, 'An analytics tracking Route cannot own Instance targets.');
END;
CREATE TRIGGER route_targets_reject_custom_proxy
BEFORE INSERT ON route_targets
WHEN (SELECT kind FROM routes WHERE id = NEW.route_id) = 'custom_proxy'
BEGIN
    SELECT RAISE(ABORT, 'A custom proxy Route cannot own Instance targets.');
END;
CREATE TRIGGER production_route_target_roles_update
BEFORE UPDATE OF node_id, role, status ON node_roles
WHEN OLD.node_id IN (
    SELECT instances.node_id
    FROM instances
    JOIN route_targets ON route_targets.instance_id = instances.id
    WHERE (
        SELECT COUNT(*) FROM route_targets AS members
        WHERE members.route_id = route_targets.route_id
    ) > 1
)
    AND NOT (
        (NEW.node_id = OLD.node_id AND NEW.role = 'app-prod' AND NEW.status = 'active')
        OR EXISTS (
            SELECT 1 FROM node_roles AS other
            WHERE other.node_id = OLD.node_id
                AND other.id <> OLD.id
                AND other.role = 'app-prod'
                AND other.status = 'active'
        )
    )
BEGIN
    SELECT RAISE(ABORT, 'Shared production Route target Node requires an active app-prod role.');
END;
CREATE TRIGGER production_route_target_roles_delete
BEFORE DELETE ON node_roles
WHEN OLD.role = 'app-prod'
    AND OLD.status = 'active'
    AND OLD.node_id IN (
        SELECT instances.node_id
        FROM instances
        JOIN route_targets ON route_targets.instance_id = instances.id
        WHERE (
            SELECT COUNT(*) FROM route_targets AS members
            WHERE members.route_id = route_targets.route_id
        ) > 1
    )
    AND NOT EXISTS (
        SELECT 1 FROM node_roles AS other
        WHERE other.node_id = OLD.node_id
            AND other.id <> OLD.id
            AND other.role = 'app-prod'
            AND other.status = 'active'
    )
BEGIN
    SELECT RAISE(ABORT, 'Shared production Route target Node requires an active app-prod role.');
END;
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
CREATE TRIGGER production_route_target_nodes_update
BEFORE UPDATE OF cluster_id, status ON nodes
WHEN EXISTS (
    SELECT 1
    FROM instances
    JOIN route_targets ON route_targets.instance_id = instances.id
    JOIN routes ON routes.id = route_targets.route_id
    WHERE instances.node_id = OLD.id
        AND (SELECT COUNT(*) FROM route_targets AS members WHERE members.route_id = routes.id) > 1
        AND (NEW.status <> 'active' OR NEW.cluster_id IS NOT routes.cluster_id)
)
BEGIN
    SELECT RAISE(ABORT, 'Shared production Route target Node cannot become inactive or change Cluster.');
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
        SELECT 1
        FROM instance_removal_members
        JOIN instance_removals
            ON instance_removals.id = instance_removal_members.instance_removal_id
        WHERE instance_removal_members.instance_id = OLD.instance_id
            AND instance_removal_members.route_id = OLD.route_id
            AND instance_removal_members.source_prepared_at IS NOT NULL
            AND instance_removal_members.route_cleared_at IS NULL
            AND instance_removal_members.row_deleted_at IS NULL
            AND instance_removals.status IN ('removing', 'failed')
    )
)
BEGIN
    SELECT RAISE(ABORT, 'An active AppInstance requires exactly one authoritative Route.');
END;
CREATE TRIGGER route_targets_active_update
BEFORE UPDATE OF instance_id ON route_targets
WHEN OLD.instance_id <> NEW.instance_id
    AND (
        (
            (SELECT type FROM projects WHERE id = (
                SELECT project_id FROM instances WHERE id = OLD.instance_id
            )) = 'laravel-app'
            AND (SELECT status FROM instances WHERE id = OLD.instance_id) = 'active'
            AND (SELECT status FROM routes WHERE id = OLD.route_id) IN ('active', 'activating')
        )
        OR (SELECT status FROM instances WHERE id = OLD.instance_id) = 'removing'
    )
BEGIN
    SELECT RAISE(ABORT, 'An active AppInstance requires exactly one authoritative Route.');
END;
CREATE TRIGGER routes_active_target_delete
BEFORE DELETE ON routes
WHEN EXISTS (
    SELECT 1 FROM route_targets
    JOIN instances ON instances.id = route_targets.instance_id
    JOIN projects ON projects.id = instances.project_id
    WHERE route_targets.route_id = OLD.id
        AND (
            (projects.type = 'laravel-app' AND instances.status = 'active' AND OLD.status IN ('active', 'activating'))
            OR (
                instances.status = 'removing'
                AND NOT EXISTS (
                    SELECT 1
                    FROM instance_removal_members
                    JOIN instance_removals ON instance_removals.id =
                        instance_removal_members.instance_removal_id
                    WHERE instance_removal_members.instance_id = instances.id
                        AND instance_removal_members.route_id = OLD.id
                        AND instance_removal_members.source_prepared_at IS NOT NULL
                        AND instance_removal_members.route_cleared_at IS NULL
                        AND instance_removal_members.row_deleted_at IS NULL
                        AND instance_removals.status IN ('removing', 'failed')
                )
            )
        )
)
BEGIN
    SELECT RAISE(ABORT, 'An active AppInstance requires exactly one authoritative Route.');
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
CREATE TRIGGER production_route_target_instances_update
BEFORE UPDATE OF project_id, node_id ON instances
WHEN EXISTS (
    SELECT 1
    FROM route_targets
    JOIN routes ON routes.id = route_targets.route_id
    WHERE route_targets.instance_id = OLD.id
        AND (SELECT COUNT(*) FROM route_targets AS members WHERE members.route_id = routes.id) > 1
        AND (
            NEW.project_id <> routes.project_id
            OR NOT EXISTS (SELECT 1 FROM node_roles AS production_role WHERE production_role.node_id = NEW.node_id AND production_role.role = 'app-prod' AND production_role.status = 'active')
            OR 1 <> COALESCE((
                SELECT active FROM active_app_prod_nodes WHERE node_id = NEW.node_id
            ), 0)
            OR (SELECT cluster_id FROM active_app_prod_nodes WHERE node_id = NEW.node_id)
                IS NOT routes.cluster_id
            OR EXISTS (
                SELECT 1
                FROM route_targets AS sibling
                JOIN instances AS sibling_instance
                    ON sibling_instance.id = sibling.instance_id
                WHERE sibling.route_id = routes.id
                    AND sibling.instance_id <> OLD.id
                    AND sibling_instance.node_id = NEW.node_id
            )
        )
)
BEGIN
    SELECT RAISE(ABORT, 'Shared production Route target AppInstance cannot invalidate its target set.');
END;
CREATE TRIGGER `instance_removal_members_delete`
BEFORE DELETE ON instance_removal_members
BEGIN
    SELECT RAISE(ABORT, 'AppInstance removal member evidence is immutable.');
END;
CREATE TRIGGER `instance_removal_members_immutable`
BEFORE UPDATE ON instance_removal_members
WHEN NEW.instance_removal_id <> OLD.instance_removal_id
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
    OR NEW.common_repository_path IS NOT OLD.common_repository_path
    OR NEW.source_identity IS NOT OLD.source_identity
    OR NEW.linked_worktree_paths <> OLD.linked_worktree_paths
    OR NEW.source_digest <> OLD.source_digest
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
    SELECT RAISE(ABORT, 'Invalid AppInstance removal member contract.');
END;
CREATE TRIGGER `instance_removal_members_source_commit_immutable`
BEFORE UPDATE ON instance_removal_members
WHEN NEW.source_commit IS NOT OLD.source_commit
BEGIN
    SELECT RAISE(ABORT, 'Invalid AppInstance removal member contract.');
END;
CREATE TRIGGER `instance_removals_delete`
BEFORE DELETE ON instance_removals
BEGIN
    SELECT RAISE(ABORT, 'AppInstance removal evidence is immutable.');
END;
CREATE TRIGGER `instances_production_placement_insert` BEFORE INSERT ON instances WHEN EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = NEW.node_id AND node_roles.role = 'app-prod' AND node_roles.status IN ('active', 'removing')) AND EXISTS (SELECT 1 FROM instances WHERE project_id = NEW.project_id AND node_id = NEW.node_id AND EXISTS (SELECT 1 FROM node_roles AS existing_role WHERE existing_role.node_id = instances.node_id AND existing_role.role = 'app-prod' AND existing_role.status IN ('active', 'removing'))) BEGIN SELECT RAISE(ABORT, 'Production AppInstance placement already exists.'); END;
CREATE TRIGGER `instances_production_placement_update` BEFORE UPDATE OF project_id, node_id ON instances WHEN EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = NEW.node_id AND node_roles.role = 'app-prod' AND node_roles.status IN ('active', 'removing')) AND EXISTS (SELECT 1 FROM instances WHERE id <> OLD.id AND project_id = NEW.project_id AND node_id = NEW.node_id AND EXISTS (SELECT 1 FROM node_roles AS existing_role WHERE existing_role.node_id = instances.node_id AND existing_role.role = 'app-prod' AND existing_role.status IN ('active', 'removing'))) BEGIN SELECT RAISE(ABORT, 'Production AppInstance placement already exists.'); END;
CREATE TRIGGER `instances_removal_status_insert`
BEFORE INSERT ON instances
WHEN NEW.status = 'removing'
BEGIN
    SELECT RAISE(ABORT, 'A removing AppInstance requires recorded removal membership.');
END;
CREATE TRIGGER `projects_code_insert` BEFORE insert ON projects WHEN NEW.code IS NULL OR NEW.code NOT GLOB '[A-Z][A-Z][A-Z]' BEGIN SELECT RAISE(ABORT, 'Project code must contain three uppercase letters'); END;
CREATE TRIGGER `projects_code_update` BEFORE update ON projects WHEN NEW.code IS NULL OR NEW.code NOT GLOB '[A-Z][A-Z][A-Z]' BEGIN SELECT RAISE(ABORT, 'Project code must contain three uppercase letters'); END;
CREATE TABLE IF NOT EXISTS `project_document_storages`(
  `id` integer not null,
  `endpoint` varchar,
  `region` varchar,
  `bucket` varchar,
  `access_key_id` text,
  `secret_access_key` text,
  `created_at` datetime,
  `updated_at` datetime,
  primary key(`id`)
);
CREATE TABLE IF NOT EXISTS `project_document_entries`(
  `id` integer not null primary key autoincrement,
  `project_id` integer not null,
  `parent_id` integer,
  `kind` varchar not null,
  `name` varchar not null collate 'BINARY',
  `sibling_scope` integer not null,
  `revision` integer not null default '1',
  `current_version_id` integer,
  `archived_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`project_id`) references `projects`(`id`) on delete restrict,
  foreign key(`parent_id`) references `project_document_entries`(`id`) on delete restrict
);
CREATE UNIQUE INDEX `project_document_entries_project_id_sibling_scope_name_unique` on `project_document_entries`(
  `project_id`,
  `sibling_scope`,
  `name`
);
CREATE TABLE IF NOT EXISTS `project_document_uploads`(
  `id` integer not null primary key autoincrement,
  `project_id` integer not null,
  `entry_id` integer,
  `storage_key` varchar not null,
  `state` varchar not null default 'active',
  `created_at` datetime,
  `updated_at` datetime
);
CREATE INDEX `project_document_uploads_state_created_at_index` on `project_document_uploads`(
  `state`,
  `created_at`
);
CREATE UNIQUE INDEX `project_document_uploads_storage_key_unique` on `project_document_uploads`(
  `storage_key`
);
CREATE TABLE IF NOT EXISTS `project_document_versions`(
  `id` integer not null primary key autoincrement,
  `entry_id` integer not null,
  `upload_id` integer not null,
  `number` integer not null,
  `media_type` varchar not null,
  `size_bytes` integer not null,
  `sha256` varchar not null,
  `storage_key` varchar not null,
  `created_by_node_id` integer,
  `created_at` datetime not null,
  foreign key(`entry_id`) references `project_document_entries`(`id`) on delete cascade,
  foreign key(`upload_id`) references `project_document_uploads`(`id`) on delete restrict,
  foreign key(`created_by_node_id`) references `nodes`(`id`) on delete set null
);
CREATE UNIQUE INDEX `project_document_versions_entry_id_number_unique` on `project_document_versions`(
  `entry_id`,
  `number`
);
CREATE UNIQUE INDEX `project_document_versions_upload_id_unique` on `project_document_versions`(
  `upload_id`
);
CREATE UNIQUE INDEX `project_document_versions_storage_key_unique` on `project_document_versions`(
  `storage_key`
);
CREATE TABLE IF NOT EXISTS `project_document_cleanups`(
  `id` integer not null primary key autoincrement,
  `storage_key` varchar not null,
  `retained_fence` tinyint(1) not null default '0',
  `pending` tinyint(1) not null default '1',
  `next_attempt_at` datetime not null,
  `attempts` integer not null default '0',
  `last_error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  `claim_token` varchar,
  `claim_expires_at` datetime
);
CREATE UNIQUE INDEX `project_document_cleanups_storage_key_unique` on `project_document_cleanups`(
  `storage_key`
);
CREATE TABLE IF NOT EXISTS `agent_threads`(
  `id` integer not null primary key autoincrement,
  `task_group_id` integer not null,
  `task_id` integer,
  `node_id` integer,
  `role` varchar not null,
  `external_id` varchar not null,
  `created_at` datetime,
  `updated_at` datetime,
  `model` varchar,
  `effort` varchar,
  `driver` varchar not null default('t3'),
  `runtime_key` varchar not null,
  `state` varchar,
  `observed_at` datetime,
  `observation_version` integer not null default('0'),
  `observation_error` text,
  `error` text,
  `tokens` integer,
  `lines_added` integer,
  `lines_deleted` integer,
  `input_tokens` integer,
  `cached_input_tokens` integer,
  `output_tokens` integer,
  `model_calls` integer,
  `peak_context_tokens` integer,
  `t3_input_tokens` integer,
  `t3_cached_input_tokens` integer,
  `t3_output_tokens` integer,
  `t3_model_calls` integer,
  `t3_peak_context_tokens` integer,
  `t3_counted_total_processed_tokens` integer,
  `t3_observed_total_processed_tokens` integer,
  `t3_event_sequence` integer,
  `t3_metrics_partial` tinyint(1) not null default('0'),
  `t3_metrics_initialized` tinyint(1) not null default('0'),
  `t3_metrics_collected_at` datetime,
  `t3_metrics_final_at` datetime,
  `t3_metrics_retry_at` datetime,
  `t3_metrics_attempts` integer not null default('0'),
  `t3_metrics_activity_version` integer not null default('0'),
  `t3_metrics_observed_activity_version` integer,
  `archived_at` datetime,
  `archive_command_id` varchar,
  `archive_attempts` integer not null default('0'),
  `archive_retry_at` datetime,
  foreign key(`task_id`) references tasks(`id`) on delete cascade on update no action,
  foreign key(`node_id`) references nodes(`id`) on delete set null on update no action,
  foreign key(`task_group_id`) references `tasks`(`id`) on delete cascade
);
CREATE INDEX `agent_threads_archive_retry_at_index` on `agent_threads`(
  `archive_retry_at`
);
CREATE UNIQUE INDEX `agent_threads_driver_runtime_key_external_id_unique` on `agent_threads`(
  `driver`,
  `runtime_key`,
  `external_id`
);
CREATE INDEX `agent_threads_t3_collection_idx` on `agent_threads`(
  `driver`,
  `t3_metrics_final_at`,
  `t3_metrics_retry_at`,
  `t3_metrics_collected_at`
);
CREATE TABLE IF NOT EXISTS `task_comments`(
  `id` integer not null primary key autoincrement,
  `task_group_id` integer not null,
  `task_id` integer not null,
  `agent_thread_id` integer,
  `completion_attempt` integer,
  `type` varchar not null,
  `body` text not null,
  `author` varchar not null,
  `review_attempt` integer,
  `commit_sha` varchar,
  `posted_at` datetime not null,
  `created_at` datetime,
  `updated_at` datetime,
  `receipt_hash` varchar,
  `pull_request` text,
  `deliverables` text,
  `cause` varchar,
  `request_id` varchar,
  foreign key(`agent_thread_id`) references agent_threads(`id`) on delete set null on update no action,
  foreign key(`task_id`) references tasks(`id`) on delete cascade on update no action,
  foreign key(`task_group_id`) references `tasks`(`id`) on delete cascade
);
CREATE INDEX `task_comments_task_id_posted_at_index` on `task_comments`(
  `task_id`,
  `posted_at`
);
CREATE UNIQUE INDEX `task_comments_task_id_receipt_hash_unique` on `task_comments`(
  `task_id`,
  `receipt_hash`
);
CREATE TABLE IF NOT EXISTS `jev_decisions`(
  `id` integer not null primary key autoincrement,
  `purpose` varchar not null,
  `call_started_at` datetime not null,
  `task_group_id` integer,
  `task_id` integer,
  `task_ids` text,
  `approval_comment_id` integer,
  `approval_changes` text,
  `approval_changes_digest` varchar,
  `approval_changes_redacted` tinyint(1),
  `merged_pull_request_number` integer,
  `merge_commit_sha` varchar,
  `merged_at` datetime,
  `merge_changes` text,
  `merge_changes_digest` varchar,
  `merge_changes_redacted` tinyint(1),
  `merge_body_digest` varchar,
  `agent_thread_id` varchar,
  `questions` text not null,
  `input_state` text not null,
  `answers` text,
  `labels` text,
  `provider_model` varchar,
  `latency_ms` integer,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`task_id`) references tasks(`id`) on delete set null on update no action,
  foreign key(`task_group_id`) references `tasks`(`id`) on delete set null
);
CREATE INDEX `jev_decisions_purpose_created_at_index` on `jev_decisions`(
  `purpose`,
  `created_at`
);
CREATE TABLE IF NOT EXISTS `project_development_deploy_steps`(
  `id` integer not null primary key autoincrement,
  `project_id` integer not null,
  `name` varchar not null,
  `command` text not null,
  `timeout_seconds` integer not null,
  `required` tinyint(1) not null default '1',
  `position` integer not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`project_id`) references `projects`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `project_development_deploy_steps_project_id_name_unique` on `project_development_deploy_steps`(
  `project_id`,
  `name`
);
CREATE UNIQUE INDEX `project_development_deploy_steps_project_id_position_unique` on `project_development_deploy_steps`(
  `project_id`,
  `position`
);
CREATE INDEX `project_document_cleanups_next_attempt_at_id_index` on `project_document_cleanups`(
  `next_attempt_at`,
  `id`
);
CREATE TABLE IF NOT EXISTS `problem_fingerprints`(
  `id` integer not null primary key autoincrement,
  `fingerprint` varchar not null,
  `source` varchar not null,
  `first_seen` datetime,
  `last_seen` datetime,
  `occurrences` integer not null default '0',
  `evidence` text not null,
  `task_group_id` integer,
  `muted_until` datetime,
  `filed_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`task_group_id`) references `tasks`(`id`) on delete set null
);
CREATE INDEX `problem_fingerprints_source_index` on `problem_fingerprints`(
  `source`
);
CREATE UNIQUE INDEX `problem_fingerprints_fingerprint_unique` on `problem_fingerprints`(
  `fingerprint`
);
CREATE TABLE IF NOT EXISTS `problem_collector_state`(
  `id` integer not null primary key autoincrement,
  `activity_cursor` integer,
  `log_path` varchar,
  `log_inode` integer,
  `log_offset` integer,
  `created_at` datetime,
  `updated_at` datetime
);
CREATE TABLE IF NOT EXISTS `task_questions`(
  `id` integer not null primary key autoincrement,
  `task_id` integer not null,
  `subtask_id` integer not null,
  `attempt` integer not null,
  `asked_by` varchar not null,
  `question` text not null,
  `status` varchar not null,
  `answered_by` varchar,
  `answer` text,
  `cause` varchar,
  `asked_at` datetime not null,
  `escalated_at` datetime,
  `answered_at` datetime,
  `opened_comment_id` integer,
  `resolution_comment_id` integer,
  `answered_comment_id` integer,
  `created_at` datetime,
  `updated_at` datetime,
  `consult` tinyint(1) not null default '0',
  foreign key(`task_id`) references `tasks`(`id`) on delete cascade,
  foreign key(`subtask_id`) references `tasks`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `task_questions_opened_comment_id_unique` on `task_questions`(
  `opened_comment_id`
);
CREATE INDEX `task_questions_asked_at_index` on `task_questions`(`asked_at`);
CREATE INDEX `task_questions_status_cause_index` on `task_questions`(
  `status`,
  `cause`
);
CREATE TABLE IF NOT EXISTS `database_servers`(
  `id` integer not null primary key autoincrement,
  `slug` varchar not null,
  `node_id` integer not null,
  `process_id` integer,
  `tag` varchar not null,
  `port` integer not null,
  `root_password` text not null,
  `status` varchar not null,
  `failed_step` varchar,
  `error_code` varchar,
  `created_at` datetime,
  `updated_at` datetime,
  `driver` varchar not null default 'mysql',
  `default_open` tinyint(1) not null default '0',
  foreign key(`node_id`) references `nodes`(`id`) on delete restrict,
  foreign key(`process_id`) references `processes`(`id`) on delete set null
);
CREATE UNIQUE INDEX `database_servers_slug_unique` on `database_servers`(
  `slug`
);
CREATE TABLE IF NOT EXISTS `database_connections`(
  `id` integer not null primary key autoincrement,
  `slug` varchar not null,
  `driver` varchar not null,
  `node_id` integer,
  `host` varchar,
  `port` integer,
  `database` varchar,
  `path` varchar,
  `username` varchar,
  `password` text,
  `created_at` datetime,
  `updated_at` datetime,
  `database_server_id` integer,
  `owner_instance_id` integer,
  `test_database` varchar,
  `clone_step` varchar,
  foreign key(`node_id`) references nodes(`id`) on delete set null on update no action,
  foreign key(`database_server_id`) references `database_servers`(`id`) on delete restrict,
  foreign key(`owner_instance_id`) references `instances`(`id`) on delete set null
);
CREATE UNIQUE INDEX `database_connections_slug_unique` on `database_connections`(
  `slug`
);
CREATE TABLE IF NOT EXISTS `task_github_review_scans`(
  `group_id` integer not null,
  `sequence` integer not null default '0',
  `snapshot` text not null,
  foreign key(`group_id`) references `tasks`(`id`) on delete cascade,
  primary key(`group_id`)
);
CREATE TABLE IF NOT EXISTS `task_github_review_observations`(
  `id` integer not null primary key autoincrement,
  `group_id` integer not null,
  `repository` varchar not null,
  `pull_request_number` integer not null,
  `reviewer_id` integer not null,
  `review_id` integer not null,
  `source` text not null,
  `latest` text not null,
  foreign key(`group_id`) references `tasks`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `task_github_approval_source` on `task_github_review_observations`(
  `group_id`,
  `repository`,
  `pull_request_number`,
  `review_id`
);
CREATE TABLE IF NOT EXISTS `task_github_review_consumptions`(
  `id` integer not null primary key autoincrement,
  `group_id` integer not null,
  `repository` varchar not null,
  `pull_request_number` integer not null,
  `review_id` integer not null,
  `identity` varchar not null,
  `operator_task_id` integer,
  `fixup_id` integer,
  `digest` varchar not null,
  `packet` text not null,
  `created_at` datetime not null,
  foreign key(`group_id`) references `tasks`(`id`) on delete cascade,
  foreign key(`fixup_id`) references `tasks`(`id`) on delete set null
);
CREATE UNIQUE INDEX `task_github_review_consumption_source` on `task_github_review_consumptions`(
  `group_id`,
  `repository`,
  `pull_request_number`,
  `review_id`
);
CREATE TABLE IF NOT EXISTS `task_github_review_feedback`(
  `group_id` integer not null,
  `causes` text not null,
  foreign key(`group_id`) references `tasks`(`id`) on delete cascade,
  primary key(`group_id`)
);
CREATE TRIGGER `instance_removals_immutable`
BEFORE UPDATE ON instance_removals
WHEN NEW.id <> OLD.id
    OR NEW.requested_instance_id <> OLD.requested_instance_id
    OR NEW.requested_name <> OLD.requested_name
    OR (NEW.force <> OLD.force AND NOT (OLD.force = 0 AND NEW.force = 1 AND OLD.status = 'failed' AND NEW.status = 'failed'))
    OR NEW.inventory_digest <> OLD.inventory_digest
    OR NEW.total <> OLD.total
    OR NEW.status NOT IN ('removing', 'failed', 'completed')
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
BEGIN
    SELECT RAISE(ABORT, 'Invalid AppInstance removal contract.');
END;
CREATE TRIGGER `instance_removal_members_source_commit_insert`
BEFORE INSERT ON instance_removal_members
WHEN NEW.environment = 'development' AND (
    NEW.source_commit IS NULL
    OR (length(NEW.source_commit) NOT IN (40, 64) AND NOT (NEW.source_commit = '' AND NEW.starting_commit IS NULL AND NEW.environment = 'development' AND NEW.source_layout IN ('checkout', 'worktree')))
)
BEGIN
    SELECT RAISE(ABORT, 'Invalid AppInstance removal member contract.');
END;
CREATE TABLE IF NOT EXISTS `gateway_releases`(
  `id` integer not null primary key autoincrement,
  `release_id` varchar,
  `sha` varchar,
  `trigger` varchar not null,
  `outcome` varchar not null,
  `migrations_ran` tinyint(1) not null default('0'),
  `retryable` tinyint(1) not null default('0'),
  `cleanup_paused` tinyint(1) not null default('0'),
  `snapshot_path` varchar,
  `previous_release_id` varchar,
  `phases` text not null,
  `error_code` varchar,
  `message` text,
  `duration_ms` integer not null,
  `created_at` datetime,
  `updated_at` datetime,
  `requested` varchar,
  `force` tinyint(1) not null default '0',
  `alert` text
);
CREATE INDEX `gateway_releases_outcome_index` on `gateway_releases`(`outcome`);
CREATE INDEX `gateway_releases_release_id_index` on `gateway_releases`(
  `release_id`
);
CREATE UNIQUE INDEX `dependency_observation_instance_source_ecosystem` on `instance_dependency_observations`(
  `instance_id`,
  `sub_project`,
  `ecosystem`
);
CREATE INDEX `dependency_attempt_source_latest` on `instance_dependency_scan_attempts`(
  `instance_id`,
  `sub_project`,
  `ecosystem`,
  `id`
);
CREATE TABLE IF NOT EXISTS `package_cache_snapshots`(
  `project_id` integer not null,
  `commit` varchar,
  `fill_pending` tinyint(1) not null default '0',
  `blobs` text not null,
  `artifacts` text not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`project_id`) references `projects`(`id`) on delete cascade,
  primary key(`project_id`)
);
CREATE TABLE IF NOT EXISTS `package_cache_entries`(
  `id` integer not null primary key autoincrement,
  `project_id` integer not null,
  `host_node_id` integer not null,
  `manager` varchar not null,
  `package` varchar not null,
  `version` varchar not null,
  `integrity` varchar not null,
  `key` varchar not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`project_id`) references `projects`(`id`) on delete cascade,
  foreign key(`host_node_id`) references `nodes`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `package_cache_entries_identity_unique` on `package_cache_entries`(
  `project_id`,
  `host_node_id`,
  `package`,
  `version`,
  `key`
);
CREATE INDEX `package_cache_entries_package_version_index` on `package_cache_entries`(
  `package`,
  `version`
);
CREATE TABLE IF NOT EXISTS `fleet_rollouts`(
  `id` integer not null primary key autoincrement,
  `gateway_release_id` integer,
  `commit` varchar not null,
  `status` varchar not null,
  `desired_state` text not null,
  `order` text not null,
  `halted_node_id` integer,
  `error_code` varchar,
  `message` text,
  `alert` text,
  `notices` text,
  `started_at` datetime,
  `finished_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`gateway_release_id`) references `gateway_releases`(`id`) on delete set null,
  foreign key(`halted_node_id`) references `nodes`(`id`) on delete set null
);
CREATE UNIQUE INDEX `fleet_rollouts_gateway_release_id_unique` on `fleet_rollouts`(
  `gateway_release_id`
);
CREATE INDEX `fleet_rollouts_commit_index` on `fleet_rollouts`(`commit`);
CREATE INDEX `fleet_rollouts_status_index` on `fleet_rollouts`(`status`);
CREATE TABLE IF NOT EXISTS `fleet_rollout_nodes`(
  `id` integer not null primary key autoincrement,
  `fleet_rollout_id` integer not null,
  `node_id` integer,
  `node_name` varchar not null,
  `position` integer not null,
  `outcome` varchar not null,
  `step` varchar,
  `error_code` varchar,
  `message` text,
  `evidence` text,
  `cli_version` varchar,
  `agent_version` varchar,
  `footprint_digest` varchar,
  `attempts` integer not null default '0',
  `started_at` datetime,
  `finished_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`fleet_rollout_id`) references `fleet_rollouts`(`id`) on delete cascade,
  foreign key(`node_id`) references `nodes`(`id`) on delete set null
);
CREATE UNIQUE INDEX `fleet_rollout_nodes_fleet_rollout_id_node_id_unique` on `fleet_rollout_nodes`(
  `fleet_rollout_id`,
  `node_id`
);
CREATE TABLE IF NOT EXISTS `node_footprints`(
  `id` integer not null primary key autoincrement,
  `node_id` integer not null,
  `digest` varchar,
  `artifacts` text,
  `converged_at` datetime,
  `cli_state` varchar,
  `cli_checked_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`node_id`) references `nodes`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `node_footprints_node_id_unique` on `node_footprints`(
  `node_id`
);
CREATE TABLE IF NOT EXISTS `task_reviewed_commits`(
  `id` integer not null primary key autoincrement,
  `task_id` integer not null,
  `sha` varchar not null,
  `source` varchar not null,
  `review_task_id` integer,
  `pushed_at` datetime,
  `github_review_id` integer,
  `created_at` datetime,
  `updated_at` datetime,
  `inherited_approval_sha` varchar,
  foreign key(`task_id`) references `tasks`(`id`) on delete cascade,
  foreign key(`review_task_id`) references `tasks`(`id`) on delete set null
);
CREATE UNIQUE INDEX `task_reviewed_commits_task_id_sha_unique` on `task_reviewed_commits`(
  `task_id`,
  `sha`
);
CREATE TABLE IF NOT EXISTS `ai_router_process_sources`(
  `process_id` integer not null,
  `source_revision` varchar,
  `activation_pending` tinyint(1) not null default '0',
  foreign key(`process_id`) references `processes`(`id`) on delete cascade,
  primary key(`process_id`)
);
CREATE TABLE IF NOT EXISTS `task_workspace_leases`(
  `id` integer not null primary key autoincrement,
  `instance_id` integer not null,
  `group_id` integer not null,
  `task_id` integer,
  `task_context` varchar,
  `acquisition_sequence` integer,
  `active_instance_id` integer,
  `kind` varchar not null,
  `owner` varchar not null,
  `status` varchar not null default 'queued',
  `thread_id` integer,
  `check_id` integer,
  `pid` integer,
  `process_started` varchar,
  `process_head` varchar,
  `process_tree` varchar,
  `previous_turn_id` varchar,
  `dispatch_key` varchar,
  `dispatch_hash` varchar,
  `dispatch_message` text,
  `dispatch_generated` tinyint(1) not null default '0',
  `dispatch_completed` tinyint(1) not null default '0',
  `review_baseline_required` tinyint(1) not null default '0',
  `saw_working` tinyint(1) not null default '0',
  `adopted` tinyint(1) not null default '0',
  `remote_uncertain` tinyint(1) not null default '0',
  `orbit_mutation` tinyint(1) not null default '0',
  `dispatch_started_at` datetime,
  `gateway_pid` integer,
  `gateway_started` varchar,
  `head_before` varchar,
  `tree_before` varchar,
  `head_after` varchar,
  `tree_after` varchar,
  `acquired_at` datetime,
  `released_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  `gateway_host` varchar,
  `gateway_boot` varchar,
  `journaled` tinyint(1) not null default '0',
  `completion_pending` tinyint(1) not null default '0',
  `check_run_id` varchar,
  `actor_kind` varchar,
  `actor_owner` varchar,
  `recovery_sealed` tinyint(1) not null default '0',
  `causality_uncertain` tinyint(1) not null default '0'
);
CREATE INDEX `task_workspace_leases_instance_id_index` on `task_workspace_leases`(
  `instance_id`
);
CREATE INDEX `task_workspace_leases_group_id_index` on `task_workspace_leases`(
  `group_id`
);
CREATE INDEX `task_workspace_leases_task_id_index` on `task_workspace_leases`(
  `task_id`
);
CREATE UNIQUE INDEX `task_workspace_leases_active_instance_id_unique` on `task_workspace_leases`(
  `active_instance_id`
);
CREATE TABLE IF NOT EXISTS `tasks`(
  `id` integer not null primary key autoincrement,
  `parent_id` integer,
  `project_id` integer,
  `taskable_type` varchar,
  `taskable_id` integer,
  `title` varchar not null,
  `brief` text not null,
  `status` varchar not null default('todo'),
  `position` integer,
  `pr_url` varchar,
  `implementer_model` varchar,
  `reviewer_model` varchar,
  `tokens` integer,
  `line_diff` integer,
  `lines_added` integer,
  `lines_deleted` integer,
  `duration_ms` integer,
  `started_at` datetime,
  `settled_at` datetime,
  `reserved_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  `reviewer_agent_thread_id` integer,
  `implementer_agent_thread_id` integer,
  `assistance_requested` tinyint(1) not null default('0'),
  `assistance_reason` text,
  `implementer_agent_driver` varchar,
  `reviewer_agent_driver` varchar,
  `agent_unavailable_since` datetime,
  `agent_unavailable_notified_at` datetime,
  `type` varchar,
  `completion_summary` text,
  `subtask_start_commit` varchar,
  `completion_attempt` integer,
  `completion_handoff_comment_id` integer,
  `completion_reminder_attempt` integer,
  `completion_reminder_input_id` varchar,
  `completion_handoff_attempt` integer,
  `completion_handoff_turn_id` varchar,
  `review_attempt` integer,
  `review_handled_comment_id` integer,
  `review_reminder_attempt` integer,
  `review_reminder_input_id` varchar,
  `review_notified_attempt` integer,
  `review_notified_turn_id` varchar,
  `review_workspace_head` varchar,
  `review_workspace_tree` varchar,
  `deliverables` text,
  `fixup_problem` text,
  `fixup_head_sha` varchar,
  `communication_failures` integer,
  `resolution_delivered_comment_id` integer,
  `pi_restart_resumes` integer,
  `pi_restart_key` varchar,
  `pi_restart_thread_id` integer,
  `pi_restart_source_turn_id` varchar,
  `pi_restart_reservation` varchar,
  `pi_restart_session_revision` varchar,
  `continuation_of_task_id` integer,
  `assistance_kind` varchar,
  `assistance_question` text,
  `questions` integer not null default('0'),
  `escalations` integer not null default('0'),
  `direction_relay_comment_id` integer,
  `direction_answer_key` varchar,
  `direction_answer_source_turn_id` varchar,
  `consult_comment_id` integer,
  `topology` text,
  `watched_pr_url` varchar,
  `watched_pr_number` integer,
  `watched_pr_state` varchar,
  `watched_pr_completion` varchar,
  `ended_pr_notice_thread_id` integer,
  `ended_pr_notice_key` varchar,
  `ended_pr_notice_state` varchar,
  `capacity_wait_reason` text,
  `preview` tinyint(1),
  `pr_branch` varchar,
  `merge_status` varchar,
  `merge_reason` text,
  `merged_sha` varchar,
  `pending_resolution_reason` text,
  `pending_resolution_message` text,
  `pending_resolution_comment_id` integer,
  `review_workspace_lease_id` integer,
  `deliverable_correction_check_id` integer,
  `deliverable_correction_resume` text,
  `provider_retry` text,
  foreign key(`continuation_of_task_id`) references tasks(`id`) on delete set null on update no action,
  foreign key(`resolution_delivered_comment_id`) references task_comments(`id`) on delete set null on update no action,
  foreign key(`review_handled_comment_id`) references task_comments(`id`) on delete set null on update no action,
  foreign key(`completion_handoff_comment_id`) references task_comments(`id`) on delete set null on update no action,
  foreign key(`pi_restart_thread_id`) references agent_threads(`id`) on delete set null on update no action,
  foreign key(`implementer_agent_thread_id`) references agent_threads(`id`) on delete set null on update no action,
  foreign key(`reviewer_agent_thread_id`) references agent_threads(`id`) on delete set null on update no action,
  foreign key(`project_id`) references projects(`id`) on delete restrict on update no action,
  foreign key(`parent_id`) references tasks(`id`) on delete cascade on update no action,
  foreign key(`pending_resolution_comment_id`) references `task_comments`(`id`) on delete set null
);
CREATE UNIQUE INDEX `tasks_parent_id_position_unique` on `tasks`(
  `parent_id`,
  `position`
);
CREATE INDEX `tasks_project_id_status_index` on `tasks`(
  `project_id`,
  `status`
);
CREATE INDEX `tasks_status_index` on `tasks`(`status`);
CREATE INDEX `tasks_taskable_type_taskable_id_index` on `tasks`(
  `taskable_type`,
  `taskable_id`
);
CREATE INDEX `task_workspace_leases_instance_id_status_index` on `task_workspace_leases`(
  `instance_id`,
  `status`
);
CREATE TABLE IF NOT EXISTS `task_workspace_operations`(
  `id` integer not null primary key autoincrement,
  `lease_id` integer not null,
  `operation` varchar not null,
  `binding` varchar not null,
  `connection` text not null,
  `status` varchar not null default 'pending',
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`lease_id`) references `task_workspace_leases`(`id`) on delete restrict
);
CREATE INDEX `task_workspace_operations_lease_id_status_index` on `task_workspace_operations`(
  `lease_id`,
  `status`
);
CREATE UNIQUE INDEX `task_workspace_operations_operation_unique` on `task_workspace_operations`(
  `operation`
);
CREATE TABLE IF NOT EXISTS `route_removal_residues`(
  `id` integer not null primary key autoincrement,
  `node_id` integer not null,
  `route_id` integer not null,
  `domain` varchar not null,
  `steps` text not null,
  `attempts` integer not null default '0',
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`node_id`) references `nodes`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `route_removal_residues_node_id_route_id_unique` on `route_removal_residues`(
  `node_id`,
  `route_id`
);
CREATE UNIQUE INDEX `instances_node_id_ssr_port_unique` on `instances`(
  `node_id`,
  `ssr_port`
);
CREATE TABLE IF NOT EXISTS `ssr_port_assignments`(
  `instance_id` integer not null,
  `node_id` integer not null,
  `port` integer not null,
  foreign key(`instance_id`) references `instances`(`id`) on delete cascade,
  foreign key(`node_id`) references `nodes`(`id`) on delete restrict,
  primary key(`instance_id`, `node_id`)
);
CREATE UNIQUE INDEX `ssr_port_assignments_node_id_port_unique` on `ssr_port_assignments`(
  `node_id`,
  `port`
);
CREATE TRIGGER route_targets_contract_insert
BEFORE INSERT ON route_targets
WHEN (
    NEW.position < 0 OR ((SELECT web_root FROM routes WHERE id = NEW.route_id) IS NOT NULL AND EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id))
    OR (SELECT project_id FROM instances WHERE id = NEW.instance_id)
        <> (SELECT project_id FROM routes WHERE id = NEW.route_id)
    OR (
        (SELECT node_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
        AND (SELECT node_id FROM routes WHERE id = NEW.route_id)
            <> (SELECT node_id FROM instances WHERE id = NEW.instance_id)
    )
    OR ((SELECT web_root FROM routes WHERE id = NEW.route_id) IS NULL AND (SELECT COUNT(*) FROM (SELECT 1 FROM route_targets JOIN routes AS counted ON counted.id = route_targets.route_id WHERE route_targets.instance_id = NEW.instance_id AND counted.web_root IS NULL)) >= 2)
    OR (
        (SELECT web_root FROM routes WHERE id = NEW.route_id) IS NULL AND EXISTS (SELECT 1 FROM route_targets JOIN routes AS counted ON counted.id = route_targets.route_id WHERE route_targets.instance_id = NEW.instance_id AND counted.web_root IS NULL) AND NOT EXISTS (
            SELECT 1
            FROM route_targets AS existing
            JOIN routes AS existing_route ON existing_route.id = existing.route_id
            JOIN routes AS new_route ON new_route.id = NEW.route_id
            WHERE existing.instance_id = NEW.instance_id
                AND (
                    new_route.replaces_route_id = existing.route_id
                    OR existing_route.replaces_route_id = NEW.route_id
                    OR new_route.replaced_by_route_id = existing.route_id
                    OR existing_route.replaced_by_route_id = NEW.route_id
                )
        )
    )
    OR EXISTS (
        SELECT 1
        FROM route_targets AS existing
        JOIN instances AS existing_instance ON existing_instance.id = existing.instance_id
        JOIN instances AS proposed_instance ON proposed_instance.id = NEW.instance_id
        WHERE existing.route_id = NEW.route_id
            AND existing_instance.node_id = proposed_instance.node_id
    )
    OR (
        (SELECT provenance FROM routes WHERE id = NEW.route_id) = 'generated'
        AND EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id)
    )
    OR (
        EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id)
        AND (SELECT provenance FROM routes WHERE id = NEW.route_id) = 'explicit'
        AND (SELECT cluster_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
        AND (
            NOT EXISTS (SELECT 1 FROM instances AS target_instance JOIN node_roles AS production_role ON production_role.node_id = target_instance.node_id WHERE target_instance.id = NEW.instance_id AND production_role.role = 'app-prod' AND production_role.status = 'active')
            OR (SELECT project_id FROM instances WHERE id = NEW.instance_id)
                <> (SELECT project_id FROM routes WHERE id = NEW.route_id)
            OR NOT EXISTS (
                SELECT 1 FROM active_app_prod_nodes
                WHERE node_id = (SELECT node_id FROM instances WHERE id = NEW.instance_id)
                    AND cluster_id IS (SELECT cluster_id FROM routes WHERE id = NEW.route_id)
                    AND active = 1
            )
        )
    )
    OR (
        (SELECT status FROM routes WHERE id = NEW.route_id) IN ('active', 'activating', 'retiring')
        AND NEW.position <> (
            SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.route_id
        )
    )
)
BEGIN
    SELECT RAISE(ABORT, 'Invalid Route target contract.');
END;
CREATE TRIGGER route_targets_contract_update
BEFORE UPDATE OF route_id, instance_id, position ON route_targets
WHEN (
    NEW.position < 0 OR ((SELECT web_root FROM routes WHERE id = NEW.route_id) IS NOT NULL AND EXISTS (SELECT 1 FROM route_targets AS other WHERE other.route_id = NEW.route_id AND other.id <> OLD.id))
    OR (SELECT project_id FROM instances WHERE id = NEW.instance_id)
        <> (SELECT project_id FROM routes WHERE id = NEW.route_id)
    OR (
        (SELECT node_id FROM routes WHERE id = NEW.route_id) IS NOT NULL
        AND (SELECT node_id FROM routes WHERE id = NEW.route_id)
            <> (SELECT node_id FROM instances WHERE id = NEW.instance_id)
    )
    OR EXISTS (
        SELECT 1 FROM route_targets
        WHERE instance_id = NEW.instance_id AND id <> OLD.id AND (SELECT web_root FROM routes WHERE id = NEW.route_id) IS NULL AND (SELECT web_root FROM routes WHERE id = route_targets.route_id) IS NULL AND NOT EXISTS (
                SELECT 1
                FROM routes AS existing_route
                JOIN routes AS new_route ON new_route.id = NEW.route_id
                WHERE existing_route.id = route_targets.route_id
                    AND (
                        new_route.replaces_route_id = existing_route.id
                        OR existing_route.replaces_route_id = NEW.route_id
                        OR new_route.replaced_by_route_id = existing_route.id
                        OR existing_route.replaced_by_route_id = NEW.route_id
                    )
            )
    )
    OR (
        NEW.route_id = OLD.route_id
        AND (SELECT status FROM routes WHERE id = NEW.route_id) IN ('active', 'activating', 'retiring')
        AND NEW.position >= (
            SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.route_id
        )
    )
    OR (
        NEW.route_id <> OLD.route_id
        AND (SELECT status FROM routes WHERE id = NEW.route_id) IN ('active', 'activating', 'retiring')
        AND NEW.position > (
            SELECT COUNT(*) FROM route_targets WHERE route_id = NEW.route_id
        )
    )
)
BEGIN
    SELECT RAISE(ABORT, 'Invalid Route target contract.');
END;
CREATE TRIGGER `instances_active_route_update`
BEFORE UPDATE OF status ON instances
WHEN NEW.status = 'active'
    AND (SELECT type FROM projects WHERE id = NEW.project_id) = 'laravel-app'
    AND (
        SELECT COUNT(*)
        FROM route_targets
        JOIN routes ON routes.id = route_targets.route_id
        WHERE route_targets.instance_id = NEW.id AND routes.status IN ('active', 'activating') AND routes.web_root IS NULL
    ) <> 1
BEGIN
    SELECT RAISE(ABORT, 'An active AppInstance requires exactly one authoritative Route.');
END;
CREATE TABLE IF NOT EXISTS `conn_profiles`(
  `id` integer not null primary key autoincrement,
  `name` varchar not null,
  `settings` text not null,
  `settings_version` integer not null default '1',
  `created_at` datetime,
  `updated_at` datetime,
  `section_meta` text
);
CREATE TABLE IF NOT EXISTS `conn_profile_bindings`(
  `id` integer not null primary key autoincrement,
  `node_id` integer not null,
  `conn_profile_id` integer not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`node_id`) references `nodes`(`id`) on delete cascade,
  foreign key(`conn_profile_id`) references `conn_profiles`(`id`) on delete cascade
);
CREATE TABLE IF NOT EXISTS `conn_environments`(
  `id` integer not null primary key autoincrement,
  `environment_id` varchar not null,
  `label` varchar not null,
  `url` varchar not null,
  `node_id` integer,
  `server_version` varchar,
  `admin_session` text not null,
  `admin_session_expires_at` datetime not null,
  `registered_at` datetime not null,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`node_id`) references `nodes`(`id`) on delete set null
);
CREATE TABLE IF NOT EXISTS `conn_pairings`(
  `id` integer not null primary key autoincrement,
  `node_id` integer not null,
  `conn_environment_id` integer not null,
  `pairing_link_id` varchar not null,
  `client_label` varchar not null,
  `expires_at` datetime not null,
  `revoked_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`node_id`) references `nodes`(`id`) on delete cascade,
  foreign key(`conn_environment_id`) references `conn_environments`(`id`) on delete cascade
);
CREATE TABLE IF NOT EXISTS `jobs`(
  `id` integer not null primary key autoincrement,
  `queue` varchar not null,
  `payload` text not null,
  `attempts` integer not null,
  `reserved_at` integer,
  `available_at` integer not null,
  `created_at` integer not null
);
CREATE INDEX `jobs_queue_index` on `jobs`(`queue`);
CREATE TABLE IF NOT EXISTS `failed_jobs`(
  `id` integer not null primary key autoincrement,
  `uuid` varchar not null,
  `connection` varchar not null,
  `queue` varchar not null,
  `payload` text not null,
  `exception` text not null,
  `failed_at` datetime not null default CURRENT_TIMESTAMP
);
CREATE INDEX `failed_jobs_connection_queue_failed_at_index` on `failed_jobs`(
  `connection`,
  `queue`,
  `failed_at`
);
CREATE UNIQUE INDEX `failed_jobs_uuid_unique` on `failed_jobs`(`uuid`);
CREATE TABLE IF NOT EXISTS `job_batches`(
  `id` varchar not null,
  `name` varchar not null,
  `total_jobs` integer not null,
  `pending_jobs` integer not null,
  `failed_jobs` integer not null,
  `failed_job_ids` text not null,
  `options` text,
  `cancelled_at` integer,
  `created_at` integer not null,
  `finished_at` integer,
  primary key(`id`)
);
CREATE UNIQUE INDEX `conn_profiles_name_unique` on `conn_profiles`(`name`);
CREATE UNIQUE INDEX `conn_profile_bindings_node_id_unique` on `conn_profile_bindings`(
  `node_id`
);
CREATE UNIQUE INDEX `conn_environments_environment_id_unique` on `conn_environments`(
  `environment_id`
);
CREATE INDEX `conn_pairings_conn_environment_id_node_id_index` on `conn_pairings`(
  `conn_environment_id`,
  `node_id`
);
CREATE TABLE IF NOT EXISTS `vault_items`(
  `id` integer not null primary key autoincrement,
  `source` varchar not null,
  `cipher_id` varchar not null,
  `fingerprint` varchar not null,
  `revision_date` varchar,
  `created_at` datetime,
  `updated_at` datetime
);
CREATE UNIQUE INDEX `vault_items_source_unique` on `vault_items`(`source`);
CREATE TABLE IF NOT EXISTS `task_vms`(
  `id` integer not null primary key autoincrement,
  `group_id` integer,
  `host_node_id` integer,
  `node_id` integer,
  `provider` varchar not null,
  `name` varchar not null,
  `state` varchar not null,
  `address` varchar,
  `wireguard_ip` varchar not null,
  `pi_token` text,
  `model_key` text,
  `error_code` varchar,
  `error_message` text,
  `ready_at` datetime,
  `destroyed_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  `ai_router_origin` varchar,
  `claimed_at` datetime,
  `warmed_at` datetime,
  `image_fingerprint` varchar,
  `provider_id` varchar,
  `ssh_host_key` text,
  `size` varchar not null default 'normal',
  `topology` text,
  `topology_state` varchar,
  `topology_run` varchar,
  `topology_started_at` datetime,
  `topology_error` text,
  `publish_commit` varchar,
  `publish_branch` varchar,
  `publish_state` varchar,
  `publish_error` text,
  `publish_queued_at` datetime,
  `publish_started_at` datetime,
  `recipe_identity` varchar,
  foreign key(`group_id`) references tasks(`id`) on delete no action on update no action,
  foreign key(`host_node_id`) references nodes(`id`) on delete no action on update no action,
  foreign key(`node_id`) references nodes(`id`) on delete set null on update no action
);
CREATE INDEX `task_vms_group_id_index` on `task_vms`(`group_id`);
CREATE INDEX `task_vms_host_node_id_state_index` on `task_vms`(
  `host_node_id`,
  `state`
);
CREATE UNIQUE INDEX `task_vms_name_unique` on `task_vms`(`name`);
CREATE UNIQUE INDEX `task_vms_node_id_unique` on `task_vms`(`node_id`);
CREATE INDEX `task_vms_state_index` on `task_vms`(`state`);
CREATE UNIQUE INDEX task_vms_live_group_id_unique ON task_vms(
  group_id
) WHERE state != 'destroyed';
CREATE UNIQUE INDEX task_vms_live_wireguard_ip_unique ON task_vms(
  wireguard_ip
) WHERE state != 'destroyed';
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
                        AND (status = 'active' OR (instances.status IN ('reserved', 'checkout_prepared', 'source_resolved') AND (instances.failed_step IS NOT NULL AND instances.error_code IS NOT NULL OR (((instances.source_layout = 'checkout' AND instances.source_prepare_id IS NOT NULL) OR (instances.source_layout = 'worktree' AND instances.status = 'reserved' AND instances.starting_commit IS NULL AND instances.task_workspace_routed IS NOT NULL)) AND instances.registration_request_id IS NULL AND (instances.task_workspace_routed IS NOT 0 OR instances.starting_commit IS NULL))) AND EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = instances.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing'))) OR (status = 'source_resolved' AND (NOT EXISTS ( SELECT 1 FROM route_targets WHERE route_targets.instance_id = instances.id ) OR (EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = instances.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing')) AND (SELECT COUNT(DISTINCT route_id) FROM route_targets WHERE instance_id = instances.id) = 1 AND EXISTS (SELECT 1 FROM routes JOIN route_targets ON route_targets.route_id = routes.id WHERE route_targets.instance_id = instances.id AND routes.project_id = instances.project_id AND routes.status IN ('pending', 'failed') AND (SELECT COUNT(*) FROM route_targets AS targets WHERE targets.route_id = routes.id) = 1)))))
    )
BEGIN
    SELECT RAISE(ABORT, 'Invalid AppInstance removal contract.');
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
            AND (instances.status = 'active' OR (NEW.runtime_published = 0 AND (instances.status IN ('reserved', 'checkout_prepared', 'source_resolved') AND (instances.failed_step IS NOT NULL AND instances.error_code IS NOT NULL OR (((instances.source_layout = 'checkout' AND instances.source_prepare_id IS NOT NULL) OR (instances.source_layout = 'worktree' AND instances.status = 'reserved' AND instances.starting_commit IS NULL AND instances.task_workspace_routed IS NOT NULL)) AND instances.registration_request_id IS NULL AND (instances.task_workspace_routed IS NOT 0 OR instances.starting_commit IS NULL))) AND EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = instances.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing'))))
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
                OR (NEW.starting_commit IS NULL AND NOT (NEW.runtime_published = 0 AND EXISTS (SELECT 1 FROM instances WHERE instances.id = NEW.instance_id AND (instances.status IN ('reserved', 'checkout_prepared', 'source_resolved') AND (instances.failed_step IS NOT NULL AND instances.error_code IS NOT NULL OR (((instances.source_layout = 'checkout' AND instances.source_prepare_id IS NOT NULL) OR (instances.source_layout = 'worktree' AND instances.status = 'reserved' AND instances.starting_commit IS NULL AND instances.task_workspace_routed IS NOT NULL)) AND instances.registration_request_id IS NULL AND (instances.task_workspace_routed IS NOT 0 OR instances.starting_commit IS NULL))) AND EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = instances.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing'))))))
        OR NEW.common_repository_path IS NULL
        OR NEW.source_identity IS NULL
    ))
BEGIN
    SELECT RAISE(ABORT, 'Invalid AppInstance removal member contract.');
END;
CREATE TRIGGER `instances_removal_status_update`
BEFORE UPDATE OF status ON instances
WHEN (OLD.status = 'removing' AND NEW.status <> 'removing')
    OR (NEW.status = 'removing' AND OLD.status <> 'removing' AND (
        (OLD.status NOT IN ('active', 'source_resolved') AND NOT (OLD.status IN ('reserved', 'checkout_prepared', 'source_resolved') AND (OLD.failed_step IS NOT NULL AND OLD.error_code IS NOT NULL OR (((OLD.source_layout = 'checkout' AND OLD.source_prepare_id IS NOT NULL) OR (OLD.source_layout = 'worktree' AND OLD.status = 'reserved' AND OLD.starting_commit IS NULL AND OLD.task_workspace_routed IS NOT NULL)) AND OLD.registration_request_id IS NULL AND (OLD.task_workspace_routed IS NOT 0 OR OLD.starting_commit IS NULL))) AND EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = OLD.node_id AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing'))))
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
    SELECT RAISE(ABORT, 'A removing AppInstance requires recorded removal membership.');
END;
CREATE UNIQUE INDEX instances_node_id_checkout_path_unique ON instances(
  node_id,
  checkout_path
);
CREATE TABLE IF NOT EXISTS `task_vm_image_builds`(
  `id` integer not null primary key autoincrement,
  `host_node_id` integer not null,
  `source_alias` varchar,
  `source_fingerprint` varchar,
  `from_stock` tinyint(1) not null default '0',
  `builder_vm_name` varchar,
  `image_fingerprint` varchar,
  `current_step` varchar,
  `current_program` varchar,
  `state` varchar not null default 'running',
  `steps` text not null default '[]', `created_at` datetime,
  `updated_at` datetime,
  `submission_pending_at` varchar,
  `cleanup_started_at` datetime,
  `attempt` integer not null default '1',
  `recipe_identity` varchar,
  `recipe_json` text,
  `recipe_generation` integer,
  `images` text not null default '{}',
  `promoted_at` datetime,
  `fleet_convergence` text,
  foreign key(`host_node_id`) references `nodes`(`id`) on delete restrict
);
CREATE UNIQUE INDEX task_vm_image_builds_running_host_unique ON task_vm_image_builds(
  host_node_id
) WHERE state = 'running';
CREATE TABLE IF NOT EXISTS `task_vm_image_recipe`(
  `id` integer not null,
  `identity` varchar,
  `recipe_json` text,
  `generation` integer not null default '0',
  `upcloud_rebuild_after_attempt` integer,
  primary key(`id`)
);
CREATE TABLE IF NOT EXISTS `operations`(
  `id` integer not null primary key autoincrement,
  `kind` varchar not null,
  `target_type` varchar not null,
  `target_id` integer not null,
  `node_id` integer,
  `lock_key` varchar,
  `input` text not null,
  `state` text,
  `result` text,
  `status` varchar not null,
  `sequence` integer not null default '1',
  `current_step` varchar,
  `failed_step` varchar,
  `error_code` varchar,
  `attempts` integer not null default '0',
  `worker` varchar,
  `triggered_by` varchar,
  `request_id` varchar,
  `cancel_requested_at` datetime,
  `dispatched_at` datetime,
  `started_at` datetime,
  `finished_at` datetime,
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`node_id`) references `nodes`(`id`) on delete set null
);
CREATE INDEX `operations_status_id_index` on `operations`(`status`, `id`);
CREATE INDEX `operations_target_type_target_id_id_index` on `operations`(
  `target_type`,
  `target_id`,
  `id`
);
CREATE UNIQUE INDEX `operations_lock_key_unique` on `operations`(`lock_key`);
CREATE TABLE IF NOT EXISTS `operation_steps`(
  `id` integer not null primary key autoincrement,
  `operation_id` integer not null,
  `position` integer not null,
  `name` varchar not null,
  `status` varchar not null,
  `resumable` tinyint(1) not null,
  `sequence` integer not null,
  `started_at` datetime,
  `finished_at` datetime,
  `error_code` varchar,
  `output` text,
  `output_truncated` tinyint(1) not null default '0',
  `created_at` datetime,
  `updated_at` datetime,
  foreign key(`operation_id`) references `operations`(`id`) on delete cascade
);
CREATE UNIQUE INDEX `operation_steps_operation_id_name_unique` on `operation_steps`(
  `operation_id`,
  `name`
);
CREATE UNIQUE INDEX `operation_steps_operation_id_position_unique` on `operation_steps`(
  `operation_id`,
  `position`
);
CREATE INDEX `operation_steps_operation_id_sequence_index` on `operation_steps`(
  `operation_id`,
  `sequence`
);
CREATE TABLE IF NOT EXISTS `upcloud_task_vm_image_builds`(
  `id` integer not null primary key autoincrement,
  `recipe_identity` varchar not null,
  `state` varchar not null default 'running',
  `started_at` datetime not null,
  `finished_at` datetime,
  `template_uuid` varchar,
  `failure` text
);
CREATE INDEX `upcloud_task_vm_image_builds_recipe_identity_index` on `upcloud_task_vm_image_builds`(
  `recipe_identity`
);
CREATE UNIQUE INDEX `instances_task_workspace_owner_id_unique` on `instances`(
  `task_workspace_owner_id`
);
CREATE TRIGGER instances_task_workspace_owner_immutable
BEFORE UPDATE OF task_workspace_owner_id ON instances
WHEN NEW.task_workspace_owner_id IS NOT NULL AND NEW.task_workspace_owner_id IS NOT OLD.task_workspace_owner_id
BEGIN
    SELECT RAISE(ABORT, 'A task workspace owner never changes.');
END;
CREATE TABLE IF NOT EXISTS `ai_router_cutovers`(
  `node_id` integer not null,
  `process_id` integer not null,
  foreign key(`node_id`) references `nodes`(`id`) on delete cascade,
  primary key(`node_id`)
);
CREATE INDEX `task_vm_image_builds_host_node_id_recipe_identity_index` on `task_vm_image_builds`(
  `host_node_id`,
  `recipe_identity`
);

INSERT INTO migrations VALUES(1,'2026_07_26_000000_create_horizon_new_dawn_batch_metadata_table',1);
INSERT INTO migrations VALUES(2,'2026_08_25_000001_create_nodes_table',1);
INSERT INTO migrations VALUES(3,'2026_08_25_000002_create_node_roles_table',1);
INSERT INTO migrations VALUES(4,'2026_08_25_000003_create_apps_table',1);
INSERT INTO migrations VALUES(5,'2026_08_25_000004_create_instances_table',1);
INSERT INTO migrations VALUES(6,'2026_08_25_000005_create_workspaces_table',1);
INSERT INTO migrations VALUES(7,'2026_08_25_000006_create_processes_table',1);
INSERT INTO migrations VALUES(8,'2026_08_25_000007_create_firewall_rules_table',1);
INSERT INTO migrations VALUES(9,'2026_08_25_000008_create_settings_table',1);
INSERT INTO migrations VALUES(10,'2026_08_25_000009_create_activity_log_table',1);
INSERT INTO migrations VALUES(11,'2026_08_25_125328_enforce_one_instance_per_app_and_node',1);
INSERT INTO migrations VALUES(12,'2026_08_25_140000_add_tld_to_nodes_table',1);
INSERT INTO migrations VALUES(13,'2026_08_26_000000_create_node_access_table',1);
INSERT INTO migrations VALUES(14,'2026_08_26_000001_create_tool_managers_table',1);
INSERT INTO migrations VALUES(15,'2026_08_26_000002_create_tools_table',1);
INSERT INTO migrations VALUES(16,'2026_08_28_000000_add_node_user',1);
INSERT INTO migrations VALUES(17,'2026_08_28_000001_drop_node_ssh_user',1);
INSERT INTO migrations VALUES(18,'2026_08_30_000000_add_node_settings_and_workspace_origin',1);
INSERT INTO migrations VALUES(19,'2026_08_31_165346_add_clusters_and_node_network_identity',1);
INSERT INTO migrations VALUES(20,'2026_09_01_120814_add_cluster_ingress_role',1);
INSERT INTO migrations VALUES(21,'2026_09_03_000000_add_source_defaults_to_apps',1);
INSERT INTO migrations VALUES(22,'2026_09_03_010000_create_app_instances_table',1);
INSERT INTO migrations VALUES(23,'2026_09_04_000000_create_routes_table',1);
INSERT INTO migrations VALUES(24,'2026_09_05_000000_provision_development_app_instances',1);
INSERT INTO migrations VALUES(25,'2026_09_06_000000_add_repository_identity_to_apps',1);
INSERT INTO migrations VALUES(26,'2026_09_07_000000_migrate_app_and_app_instance_source_identity',1);
INSERT INTO migrations VALUES(27,'2026_09_07_091259_remove_obsolete_wireguard_address_from_nodes_table',1);
INSERT INTO migrations VALUES(28,'2026_09_08_000000_persist_app_instance_removal_inventory',1);
INSERT INTO migrations VALUES(29,'2026_09_08_200000_enable_production_route_target_sets',1);
INSERT INTO migrations VALUES(30,'2026_09_08_204126_add_source_commit_to_app_instance_removal_members',1);
INSERT INTO migrations VALUES(31,'2026_09_09_000000_add_app_instance_registration_evidence',1);
INSERT INTO migrations VALUES(32,'2026_09_09_015031_add_source_profile_to_app_instances_table',1);
INSERT INTO migrations VALUES(33,'2026_09_09_033524_allow_nullable_branches_in_app_instance_removal_evidence',1);
INSERT INTO migrations VALUES(34,'2026_09_09_060000_add_production_identity_to_app_instances_table',1);
INSERT INTO migrations VALUES(35,'2026_09_09_070000_add_hostname_change_state_to_routes_table',1);
INSERT INTO migrations VALUES(36,'2026_09_09_104800_add_registration_cleanup_identity_to_app_instances',1);
INSERT INTO migrations VALUES(37,'2026_09_09_120000_add_registration_migration_recovery_to_app_instances',1);
INSERT INTO migrations VALUES(38,'2026_09_09_200000_create_app_instance_environment_values_table',1);
INSERT INTO migrations VALUES(39,'2026_09_10_000000_add_production_php_runtime_identity_to_app_instances',1);
INSERT INTO migrations VALUES(40,'2026_09_10_223836_create_app_instance_deployment_layouts_table',1);
INSERT INTO migrations VALUES(41,'2026_09_11_000000_add_deployment_config_to_app_instances',1);
INSERT INTO migrations VALUES(42,'2026_09_11_000000_create_schedules_table',1);
INSERT INTO migrations VALUES(43,'2026_09_11_010000_create_app_runtime_definitions',1);
INSERT INTO migrations VALUES(44,'2026_09_11_020000_add_runtime_definition_copies',1);
INSERT INTO migrations VALUES(45,'2026_09_12_000000_add_clone_evidence_to_app_instances',1);
INSERT INTO migrations VALUES(46,'2026_09_12_090000_enable_production_route_hostname_changes',1);
INSERT INTO migrations VALUES(47,'2026_09_13_210000_create_herdr_sessions',1);
INSERT INTO migrations VALUES(48,'2026_09_14_000000_create_database_connections_table',1);
INSERT INTO migrations VALUES(49,'2026_09_14_120000_create_database_connection_targets_table',1);
INSERT INTO migrations VALUES(50,'2026_09_14_130000_add_keep_alive_to_processes_table',1);
INSERT INTO migrations VALUES(51,'2026_09_14_145247_add_management_to_herdr_sessions_table',1);
INSERT INTO migrations VALUES(52,'2026_09_14_180000_store_deploy_steps_as_named_records',1);
INSERT INTO migrations VALUES(53,'2026_09_14_210000_drop_app_instance_deployment_layouts_table',1);
INSERT INTO migrations VALUES(54,'2026_09_14_220000_replace_application_hostnames_with_route_domains',1);
INSERT INTO migrations VALUES(55,'2026_09_14_230000_drop_legacy_instances_and_workspaces_tables',1);
INSERT INTO migrations VALUES(56,'2026_09_15_000000_add_public_publication_to_routes',1);
INSERT INTO migrations VALUES(57,'2026_09_15_000000_make_process_working_directory_nullable',1);
INSERT INTO migrations VALUES(58,'2026_09_15_000001_add_retired_runtime_to_processes',1);
INSERT INTO migrations VALUES(59,'2026_09_15_000002_add_process_activation_revisions',1);
INSERT INTO migrations VALUES(60,'2026_09_15_000003_add_process_activation_pending_intent',1);
INSERT INTO migrations VALUES(61,'2026_09_15_060000_create_app_instance_transfers_table',1);
INSERT INTO migrations VALUES(62,'2026_09_15_120000_create_app_updates_table',1);
INSERT INTO migrations VALUES(63,'2026_09_15_130000_allow_active_route_failed_step_during_replacement',1);
INSERT INTO migrations VALUES(64,'2026_09_15_180000_add_route_target_set_intent',1);
INSERT INTO migrations VALUES(65,'2026_09_15_190000_allow_production_target_set_mutations',1);
INSERT INTO migrations VALUES(66,'2026_09_15_200000_create_instance_dependency_inventory',1);
INSERT INTO migrations VALUES(67,'2026_09_15_220319_add_source_router_to_app_instance_transfers',1);
INSERT INTO migrations VALUES(68,'2026_09_16_000000_add_vite_port_assignments',1);
INSERT INTO migrations VALUES(69,'2026_09_16_120000_add_custom_proxy_routes',1);
INSERT INTO migrations VALUES(70,'2026_09_16_130000_add_agentation_port_to_app_instances',1);
INSERT INTO migrations VALUES(71,'2026_09_18_000000_create_app_instance_deployments_table',1);
INSERT INTO migrations VALUES(72,'2026_09_18_010000_create_database_users_table',1);
INSERT INTO migrations VALUES(73,'2026_09_20_120000_add_analytics_tracking_routes',1);
INSERT INTO migrations VALUES(74,'2026_09_20_180000_create_task_groups_and_tasks_tables',1);
INSERT INTO migrations VALUES(75,'2026_09_20_180000_drop_public_publication_from_routes',1);
INSERT INTO migrations VALUES(76,'2026_09_20_210000_add_project_type_and_normalize_app_prod_mode',1);
INSERT INTO migrations VALUES(77,'2026_09_21_124805_create_task_agent_sessions_table',1);
INSERT INTO migrations VALUES(78,'2026_09_21_130000_retain_transfer_history_after_instance_removal',1);
INSERT INTO migrations VALUES(79,'2026_09_21_131116_add_line_change_counts_to_tasks',1);
INSERT INTO migrations VALUES(80,'2026_09_21_132029_add_code_to_apps',1);
INSERT INTO migrations VALUES(81,'2026_09_21_150000_add_model_and_effort_to_task_agent_sessions',1);
INSERT INTO migrations VALUES(82,'2026_09_21_213825_create_agent_threads_from_task_agent_sessions',1);
INSERT INTO migrations VALUES(83,'2026_09_22_143449_create_annotations_and_annotation_events_tables',1);
INSERT INTO migrations VALUES(84,'2026_09_22_160124_back_annotations_with_tasks',1);
INSERT INTO migrations VALUES(85,'2026_09_22_220000_create_task_comments_table',1);
INSERT INTO migrations VALUES(86,'2026_09_22_230000_add_subtask_start_commit_to_tasks',1);
INSERT INTO migrations VALUES(87,'2026_09_23_100000_add_completion_handoff_state_to_tasks',1);
INSERT INTO migrations VALUES(88,'2026_09_23_110000_add_assistance_state_to_tasks',1);
INSERT INTO migrations VALUES(89,'2026_09_23_120000_add_rubric_reminder_input_to_tasks',1);
INSERT INTO migrations VALUES(90,'2026_09_23_120000_store_project_lifecycle_steps',1);
INSERT INTO migrations VALUES(91,'2026_09_23_130000_add_review_notified_attempt_to_tasks',1);
INSERT INTO migrations VALUES(92,'2026_09_23_140000_add_review_notified_turn_to_tasks',1);
INSERT INTO migrations VALUES(93,'2026_09_23_150000_add_implementation_handoff_evidence_to_tasks',1);
INSERT INTO migrations VALUES(94,'2026_09_23_160000_split_task_group_agent_driver_by_role',1);
INSERT INTO migrations VALUES(95,'2026_09_23_160000_store_project_node_exclusions',1);
INSERT INTO migrations VALUES(96,'2026_09_23_170000_allow_routeless_app_instance_removal_members',1);
INSERT INTO migrations VALUES(97,'2026_09_23_180000_rename_task_statuses_to_backlog_and_todo',1);
INSERT INTO migrations VALUES(98,'2026_09_23_180000_store_run_receipts_as_task_comments',1);
INSERT INTO migrations VALUES(99,'2026_09_23_190000_add_plan_to_task_groups',1);
INSERT INTO migrations VALUES(100,'2026_09_23_190000_create_task_checks_table',1);
INSERT INTO migrations VALUES(101,'2026_09_23_200000_add_baseline_checks_to_task_checks',1);
INSERT INTO migrations VALUES(102,'2026_09_23_210000_allow_removal_of_routeless_source_resolved_instances',1);
INSERT INTO migrations VALUES(103,'2026_09_23_220000_record_whether_removed_instances_published_a_runtime',1);
INSERT INTO migrations VALUES(104,'2026_09_24_000000_add_deliverables_to_tasks',1);
INSERT INTO migrations VALUES(105,'2026_09_24_120000_store_route_site_transitions',1);
INSERT INTO migrations VALUES(106,'2026_09_25_120000_drop_herdr_tables',1);
INSERT INTO migrations VALUES(107,'2026_09_25_130000_record_published_caddy_site_certificates',1);
INSERT INTO migrations VALUES(108,'2026_09_25_140000_add_agent_secret_to_nodes',1);
INSERT INTO migrations VALUES(109,'2026_09_25_140000_add_task_check_to_apps',1);
INSERT INTO migrations VALUES(110,'2026_09_25_160000_add_reserved_at_to_task_groups',1);
INSERT INTO migrations VALUES(111,'2026_09_26_090000_cap_project_lifecycle_step_timeouts',1);
INSERT INTO migrations VALUES(112,'2026_09_26_120000_add_review_workspace_to_tasks',1);
INSERT INTO migrations VALUES(113,'2026_09_26_120000_drop_plan_from_task_groups',1);
INSERT INTO migrations VALUES(114,'2026_09_26_180000_add_fixup_problem_to_tasks',1);
INSERT INTO migrations VALUES(115,'2026_09_27_120000_add_token_metrics_to_agent_threads',1);
INSERT INTO migrations VALUES(116,'2026_09_27_180000_add_pi_restart_resume_to_tasks',1);
INSERT INTO migrations VALUES(117,'2026_09_27_190000_cancel_open_subtasks_of_cancelled_groups',1);
INSERT INTO migrations VALUES(118,'2026_09_27_200000_add_t3_metrics_checkpoint_to_agent_threads',1);
INSERT INTO migrations VALUES(119,'2026_09_27_210000_bound_t3_metrics_collection',1);
INSERT INTO migrations VALUES(120,'2026_09_28_090000_create_jev_decisions_table',1);
INSERT INTO migrations VALUES(121,'2026_09_28_120000_add_thread_archiving_to_agent_threads',1);
INSERT INTO migrations VALUES(122,'2026_09_29_120000_add_archive_backoff_to_agent_threads',1);
INSERT INTO migrations VALUES(123,'2026_09_29_125000_add_continuation_source_to_tasks',1);
INSERT INTO migrations VALUES(124,'2026_09_29_130000_convert_test_deliverables_to_commands',1);
INSERT INTO migrations VALUES(125,'2026_09_30_090000_drop_agent_secret_exempt_from_nodes',1);
INSERT INTO migrations VALUES(126,'2026_09_30_090000_rewrite_app_instance_morph_types_to_alias',1);
INSERT INTO migrations VALUES(127,'2026_09_30_100000_drop_environment_from_app_instances',1);
INSERT INTO migrations VALUES(128,'2026_09_30_100100_remove_development_app_runtime_definitions',1);
INSERT INTO migrations VALUES(129,'2026_10_01_000000_drop_protected_from_tools_table',1);
INSERT INTO migrations VALUES(130,'2026_10_01_000001_move_extension_switches_to_gateway_extension_keys',1);
INSERT INTO migrations VALUES(131,'2026_10_02_000000_track_transfer_imported_environment_keys',1);
INSERT INTO migrations VALUES(132,'2026_10_03_000000_drop_app_era_instance_leftovers',1);
INSERT INTO migrations VALUES(133,'2026_10_04_000000_rename_app_domain_to_project_and_instance',1);
INSERT INTO migrations VALUES(134,'2026_10_04_184844_create_project_document_storages_table',1);
INSERT INTO migrations VALUES(135,'2026_10_04_223721_create_project_documents_tables',1);
INSERT INTO migrations VALUES(136,'2026_10_05_000000_merge_task_groups_into_tasks',1);
INSERT INTO migrations VALUES(137,'2026_10_05_120000_create_project_development_deploy_steps',1);
INSERT INTO migrations VALUES(138,'2026_10_05_165014_add_claims_to_project_document_cleanups_table',1);
INSERT INTO migrations VALUES(139,'2026_10_06_000000_add_task_workspace_routing',1);
INSERT INTO migrations VALUES(140,'2026_10_06_000000_create_task_definitions_table',1);
INSERT INTO migrations VALUES(141,'2026_10_06_090000_create_problem_fingerprints',1);
INSERT INTO migrations VALUES(142,'2026_10_07_000000_add_source_access_to_projects',1);
INSERT INTO migrations VALUES(143,'2026_10_07_000000_create_task_questions',1);
INSERT INTO migrations VALUES(144,'2026_10_07_000001_add_assistance_kind_to_tasks',1);
INSERT INTO migrations VALUES(145,'2026_10_07_000002_add_question_metrics_to_tasks',1);
INSERT INTO migrations VALUES(146,'2026_10_07_000003_add_consult_marker_to_tasks',1);
INSERT INTO migrations VALUES(147,'2026_10_07_000200_create_task_sandboxes_table',1);
INSERT INTO migrations VALUES(148,'2026_10_07_064540_add_fleet_enrollment_to_task_sandboxes',1);
INSERT INTO migrations VALUES(149,'2026_10_07_093102_add_pi_ready_at_to_task_sandboxes',1);
INSERT INTO migrations VALUES(150,'2026_10_07_110449_add_task_sandbox_id_to_task_checks',1);
INSERT INTO migrations VALUES(151,'2026_10_07_132615_add_topology_to_tasks',1);
INSERT INTO migrations VALUES(152,'2026_10_08_000000_add_watched_pr_url_to_tasks',1);
INSERT INTO migrations VALUES(153,'2026_10_08_000000_create_database_servers_table',1);
INSERT INTO migrations VALUES(154,'2026_10_08_000100_add_server_ownership_to_database_connections_table',1);
INSERT INTO migrations VALUES(155,'2026_10_08_090000_clear_assistance_on_ended_tasks',1);
INSERT INTO migrations VALUES(156,'2026_10_08_102823_add_warm_pool_to_task_sandboxes',1);
INSERT INTO migrations VALUES(157,'2026_10_09_000000_allow_failed_creation_removal',1);
INSERT INTO migrations VALUES(158,'2026_10_09_000000_allow_pre_activation_instance_removal',1);
INSERT INTO migrations VALUES(159,'2026_10_09_000000_create_task_github_review_observations_table',1);
INSERT INTO migrations VALUES(160,'2026_10_09_000100_create_task_github_review_consumptions_table',1);
INSERT INTO migrations VALUES(161,'2026_10_10_000000_add_development_release_layout_to_instances',1);
INSERT INTO migrations VALUES(162,'2026_10_10_000000_add_instance_source_prepare_id',1);
INSERT INTO migrations VALUES(163,'2026_10_10_000000_allow_force_takeover_of_failed_instance_removal',1);
INSERT INTO migrations VALUES(164,'2026_10_10_000001_allow_owned_interrupted_creation_removal',1);
INSERT INTO migrations VALUES(165,'2026_10_10_000002_add_instance_seed',1);
INSERT INTO migrations VALUES(166,'2026_10_10_000003_record_instance_seed_selection',1);
INSERT INTO migrations VALUES(167,'2026_10_11_000000_add_annotator_port_to_instances',1);
INSERT INTO migrations VALUES(168,'2026_10_11_000001_reserve_annotation_ports_until_proxy_withdrawal',1);
INSERT INTO migrations VALUES(169,'2026_10_11_000002_add_development_projection_pending_to_instances',1);
INSERT INTO migrations VALUES(170,'2026_10_11_000002_reserve_task_topology_resume_source_turn',1);
INSERT INTO migrations VALUES(171,'2026_10_11_000003_allow_reserved_task_worktree_removal',1);
INSERT INTO migrations VALUES(172,'2026_10_12_000000_allow_reserved_worktree_null_prepare_removal',1);
INSERT INTO migrations VALUES(173,'2026_10_12_000100_add_task_compute_to_projects_and_tasks',1);
INSERT INTO migrations VALUES(174,'2026_10_12_000200_add_task_sandbox_to_instances',1);
INSERT INTO migrations VALUES(175,'2026_10_12_000300_add_pi_token_to_task_sandboxes_table',1);
INSERT INTO migrations VALUES(176,'2026_10_12_000400_scope_instance_checkout_uniqueness_to_shared_workspaces',1);
INSERT INTO migrations VALUES(177,'2026_10_12_000500_add_model_key_to_task_sandboxes_table',1);
INSERT INTO migrations VALUES(178,'2026_10_12_000600_add_review_timing_to_task_sandboxes_table',1);
INSERT INTO migrations VALUES(179,'2026_10_12_000700_add_resume_intent_to_task_sandboxes_table',1);
INSERT INTO migrations VALUES(180,'2026_10_12_000800_add_preview_to_tasks_table',1);
INSERT INTO migrations VALUES(181,'2026_10_13_000000_create_gateway_releases_table',1);
INSERT INTO migrations VALUES(182,'2026_10_14_000000_track_gateway_release_requests',1);
INSERT INTO migrations VALUES(183,'2026_10_14_160936_add_sub_projects_to_instance_dependencies',1);
INSERT INTO migrations VALUES(184,'2026_10_14_201229_add_error_detail_to_instance_dependency_scan_attempts',1);
INSERT INTO migrations VALUES(185,'2026_10_14_221145_create_package_cache_tables',1);
INSERT INTO migrations VALUES(186,'2026_10_15_000000_create_fleet_rollouts_table',1);
INSERT INTO migrations VALUES(187,'2026_10_15_052820_drop_task_definitions_table',1);
INSERT INTO migrations VALUES(188,'2026_10_15_144506_add_restart_on_deploy_to_processes_table',1);
INSERT INTO migrations VALUES(189,'2026_10_15_144537_add_shared_paths_to_instances_table',1);
INSERT INTO migrations VALUES(190,'2026_10_16_000000_add_review_and_merge',1);
INSERT INTO migrations VALUES(191,'2026_10_16_074137_move_proxycli_process_sources_to_extension_table',1);
INSERT INTO migrations VALUES(192,'2026_10_16_074802_create_task_workspace_leases_table',1);
INSERT INTO migrations VALUES(193,'2026_10_16_081237_add_pending_resolution_comment_id_to_tasks',1);
INSERT INTO migrations VALUES(194,'2026_10_16_111138_create_task_workspace_operations_table',1);
INSERT INTO migrations VALUES(195,'2026_10_16_125523_add_recovery_fencing_to_task_workspace_leases',1);
INSERT INTO migrations VALUES(196,'2026_10_16_154001_add_review_workspace_lease_id_to_tasks',1);
INSERT INTO migrations VALUES(197,'2026_10_17_000000_create_route_removal_residues_table',1);
INSERT INTO migrations VALUES(198,'2026_10_18_000000_add_deliverable_correction_recovery_to_tasks',1);
INSERT INTO migrations VALUES(199,'2026_10_18_160435_drop_task_notification_columns',1);
INSERT INTO migrations VALUES(200,'2026_10_18_162229_remove_proxycli_process_port_setting',1);
INSERT INTO migrations VALUES(201,'2026_10_18_180000_add_request_id_to_task_comments',1);
INSERT INTO migrations VALUES(202,'2026_10_18_183900_drop_doctor_resume_key_from_problem_collector_state',1);
INSERT INTO migrations VALUES(203,'2026_10_19_000000_allow_owned_project_sandbox_instance_removal',1);
INSERT INTO migrations VALUES(204,'2026_10_19_000000_allow_source_resolved_workspace_route_removal',1);
INSERT INTO migrations VALUES(205,'2026_10_19_000001_record_instance_first_setup_pending',1);
INSERT INTO migrations VALUES(206,'2026_10_19_111105_reset_existing_node_dns_overrides',1);
INSERT INTO migrations VALUES(207,'2026_10_19_120215_reset_invalid_global_vpn_dns_server',1);
INSERT INTO migrations VALUES(208,'2026_10_20_000000_add_ssr_port_assignments',1);
INSERT INTO migrations VALUES(209,'2026_10_21_000000_record_instance_environment_owned_keys',1);
INSERT INTO migrations VALUES(210,'2026_10_22_000000_add_ssh_jump_node_id_to_nodes',1);
INSERT INTO migrations VALUES(211,'2026_10_22_000000_add_web_root_to_routes',1);
INSERT INTO migrations VALUES(212,'2026_10_22_000000_create_task_vms_table',1);
INSERT INTO migrations VALUES(213,'2026_10_23_000000_record_transfer_web_root_routes',1);
INSERT INTO migrations VALUES(214,'2026_10_24_000000_create_t3_tables',1);
INSERT INTO migrations VALUES(215,'2026_10_25_000000_create_jobs_table',1);
INSERT INTO migrations VALUES(216,'2026_10_25_000001_create_failed_jobs_table',1);
INSERT INTO migrations VALUES(217,'2026_10_25_000002_add_model_proxy_origin_to_task_vms',1);
INSERT INTO migrations VALUES(218,'2026_10_25_000002_create_job_batches_table',1);
INSERT INTO migrations VALUES(219,'2026_10_26_000000_rename_t3_tables_to_conn',1);
INSERT INTO migrations VALUES(220,'2026_10_27_000000_add_section_meta_to_conn_profiles',1);
INSERT INTO migrations VALUES(221,'2026_10_28_000000_add_warm_pool_to_task_vms',1);
INSERT INTO migrations VALUES(222,'2026_10_28_000000_publish_database_credentials_to_vaultwarden',1);
INSERT INTO migrations VALUES(223,'2026_10_29_000000_add_worker_shared_to_instances',1);
INSERT INTO migrations VALUES(224,'2026_10_30_000000_add_upcloud_to_task_vms',1);
INSERT INTO migrations VALUES(225,'2026_10_31_000000_add_size_to_task_vms',1);
INSERT INTO migrations VALUES(226,'2026_11_01_000000_add_topology_to_task_vms',1);
INSERT INTO migrations VALUES(227,'2026_11_01_000000_close_checks_of_destroyed_task_sandboxes',1);
INSERT INTO migrations VALUES(228,'2026_11_01_000100_drop_task_sandboxes',1);
INSERT INTO migrations VALUES(229,'2026_11_02_000000_add_publish_to_task_vms',1);
INSERT INTO migrations VALUES(230,'2026_11_02_000000_drop_shared_task_mode',1);
INSERT INTO migrations VALUES(231,'2026_11_03_000000_add_publish_started_at_to_task_vms',1);
INSERT INTO migrations VALUES(232,'2026_11_04_000000_create_task_vm_image_builds_table',1);
INSERT INTO migrations VALUES(233,'2026_11_04_000001_fence_task_vm_image_build_handoffs',1);
INSERT INTO migrations VALUES(234,'2026_11_04_000002_fence_task_vm_image_build_attempts',1);
INSERT INTO migrations VALUES(235,'2026_11_05_000000_add_provider_retry_to_tasks',1);
INSERT INTO migrations VALUES(236,'2026_11_05_000000_migrate_task_vm_host_settings',1);
INSERT INTO migrations VALUES(237,'2026_11_06_000000_add_recipe_to_task_vm_image_builds',1);
INSERT INTO migrations VALUES(238,'2026_11_07_000000_add_recipe_identity_to_task_vms',1);
INSERT INTO migrations VALUES(239,'2026_11_08_000000_retire_task_vm_warm_pool',1);
INSERT INTO migrations VALUES(240,'2026_11_09_000000_add_fleet_convergence_to_task_vm_image_builds',1);
INSERT INTO migrations VALUES(241,'2026_11_09_000000_create_operations_tables',1);
INSERT INTO migrations VALUES(242,'2026_11_09_000000_enable_documents_extension_for_existing_documents',1);
INSERT INTO migrations VALUES(243,'2026_11_10_000000_add_automatic_deployments',1);
INSERT INTO migrations VALUES(244,'2026_11_10_000000_add_vp_environment_to_instances',1);
INSERT INTO migrations VALUES(245,'2026_11_10_000000_create_upcloud_task_vm_image_builds_table',1);
INSERT INTO migrations VALUES(246,'2026_11_10_000000_enable_vault_extension_for_existing_vault',1);
INSERT INTO migrations VALUES(247,'2026_11_10_000001_enable_conn_extension_for_existing_conn',1);
INSERT INTO migrations VALUES(248,'2026_11_11_000000_migrate_legacy_vp_deploy_steps',1);
INSERT INTO migrations VALUES(249,'2026_11_12_000000_add_environment_runtime_pending_at_to_instances',1);
INSERT INTO migrations VALUES(250,'2026_11_12_000000_drop_payload_and_instance_from_annotation_events',1);
INSERT INTO migrations VALUES(251,'2026_11_13_000000_add_driver_to_database_servers_table',1);
INSERT INTO migrations VALUES(252,'2026_11_13_000000_refuse_private_cluster_and_node_tlds',1);
INSERT INTO migrations VALUES(253,'2026_11_14_000000_remove_annotations',1);
INSERT INTO migrations VALUES(254,'2026_11_15_000000_record_task_workspace_owners',1);
INSERT INTO migrations VALUES(255,'2026_11_16_000000_classify_task_removal_failures',1);
INSERT INTO migrations VALUES(256,'2026_11_16_000000_normalize_release_incidents',1);
INSERT INTO migrations VALUES(257,'2026_11_16_000001_rename_ai_router_state',1);
INSERT INTO migrations VALUES(258,'2026_11_17_000000_redact_stored_task_check_output',1);
INSERT INTO migrations VALUES(259,'2026_11_30_000000_add_failure_to_upcloud_image_builds',1);
INSERT INTO migrations VALUES(260,'2026_12_01_000000_remove_task_base_merge_gates',1);
INSERT INTO migrations VALUES(261,'2026_12_02_000000_repair_image_build_convergence_halts',1);
INSERT INTO migrations VALUES(262,'2026_12_03_000000_allow_cold_recipe_image_rebuilds',1);

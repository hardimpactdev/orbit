<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Keep the last squashed migration's name: fully migrated Gateways have already run it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_rules', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('node_id');
            $table->string('name');
            $table->string('action');
            $table->string('source')->nullable();
            $table->string('protocol');
            $table->string('port');
            $table->string('status')->default(DB::raw('\'provisioning\''));
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('restrict');
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('scope_type');
            $table->integer('scope_id')->default(DB::raw('\'0\''));
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(DB::raw('\'0\''));
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('activity_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->integer('subject_id')->nullable();
            $table->string('causer_type')->nullable();
            $table->integer('causer_id')->nullable();
            $table->string('event')->nullable();
            $table->text('properties')->nullable();
            $table->text('attribute_changes')->nullable();
            $table->string('batch_uuid')->nullable();
            $table->string('request_id');
            $table->string('command');
            $table->integer('caller_node_id')->nullable();
            $table->integer('target_node_id')->nullable();
            $table->string('caller_ip')->nullable();
            $table->string('status');
            $table->integer('duration_ms')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['caller_node_id'])->references(['id'])->on('nodes')->onDelete('set null');
            $table->foreign(['target_node_id'])->references(['id'])->on('nodes')->onDelete('set null');
        });

        Schema::create('node_access', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('consumer_node_id');
            $table->integer('serving_node_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['consumer_node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
            $table->foreign(['serving_node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
        });

        Schema::create('tool_managers', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('node_id');
            $table->string('name');
            $table->string('status');
            $table->string('installed_version')->nullable();
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
        });

        Schema::create('tools', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('node_id');
            $table->integer('tool_manager_id');
            $table->string('package');
            $table->string('version_constraint')->nullable();
            $table->string('status');
            $table->string('installed_version')->nullable();
            $table->string('failed_operation')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
            $table->foreign(['tool_manager_id'])->references(['id'])->on('tool_managers')->onDelete('cascade');
        });

        Schema::create('clusters', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('tld')->nullable();
            $table->enum('state', ['inactive', 'active'])->default(DB::raw('\'inactive\''));
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('nodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('status')->default(DB::raw('\'provisioning\''));
            $table->string('platform')->default(DB::raw('\'linux\''));
            $table->string('architecture')->nullable();
            $table->string('public_ssh_host');
            $table->integer('public_ssh_port')->default(DB::raw('\'22\''));
            $table->text('wireguard_public_key')->nullable();
            $table->string('wireguard_endpoint_override')->nullable();
            $table->string('dns_server_override')->nullable();
            $table->string('ssh_host_key_type')->nullable();
            $table->text('ssh_host_key')->nullable();
            $table->string('ssh_host_fingerprint')->nullable();
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('tld')->nullable();
            $table->string('user')->default(DB::raw('\'orbit\''));
            $table->text('settings')->nullable();
            $table->integer('cluster_id')->nullable();
            $table->string('wireguard_ip')->nullable();
            $table->string('lan_ip')->nullable();
            $table->string('agent_secret_hash')->nullable();
            $table->integer('ssh_jump_node_id')->nullable();
            $table->foreign(['ssh_jump_node_id'])->references(['id'])->on('nodes')->onDelete('set null');
            $table->foreign(['cluster_id'])->references(['id'])->on('clusters')->onDelete('restrict');
        });

        Schema::create('node_roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('node_id');
            $table->string('role');
            $table->string('status')->default(DB::raw('\'provisioning\''));
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->integer('cluster_id')->nullable();
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('restrict');
            $table->foreign(['cluster_id'])->references(['id'])->on('clusters')->onDelete('restrict');
        });

        Schema::create('route_targets', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('route_id');
            $table->integer('instance_id');
            $table->integer('position');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['route_id'])->references(['id'])->on('routes')->onDelete('cascade');
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('slug');
            $table->text('repository_url');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('default_branch')->nullable();
            $table->string('root')->nullable();
            $table->text('repository_identity');
            $table->string('type')->default(DB::raw('\'laravel-app\''));
            $table->string('code')->nullable();
            $table->text('task_check')->nullable();
            $table->boolean('task_workspace_routed')->default(DB::raw('\'1\''));
            $table->string('source_access')->default(DB::raw('\'github_app\''));
            $table->boolean('review_and_merge')->default(DB::raw('\'0\''));
            $table->string('merge_check')->nullable();
        });

        Schema::create('instances', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->integer('node_id');
            $table->string('name');
            $table->text('checkout_path');
            $table->string('root')->nullable();
            $table->string('branch')->nullable();
            $table->string('starting_commit')->nullable();
            $table->enum('status', ['reserved', 'checkout_prepared', 'source_resolved', 'active', 'removing'])->default(DB::raw('\'reserved\''));
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('selected_php_version')->nullable();
            $table->string('provisioning_step')->nullable();
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->string('source_layout')->default(DB::raw('\'checkout\''));
            $table->string('branch_override')->nullable();
            $table->text('registration_original_path')->nullable();
            $table->string('registration_request_id')->nullable();
            $table->boolean('registration_primary')->default(DB::raw('\'0\''));
            $table->boolean('registration_include_worktrees')->default(DB::raw('\'0\''));
            $table->text('registration_repository_url')->nullable();
            $table->string('registration_repository_identity')->nullable();
            $table->string('registration_source_digest')->nullable();
            $table->boolean('registration_detached')->default(DB::raw('\'0\''));
            $table->string('registration_default_branch')->nullable();
            $table->string('registration_inferred_slug')->nullable();
            $table->string('registration_inferred_root')->nullable();
            $table->text('registration_common_repository_path')->nullable();
            $table->text('registration_worktree_paths')->nullable();
            $table->string('registration_relocation_state')->nullable();
            $table->text('registration_authoritative_path')->nullable();
            $table->string('registration_route_domain')->nullable();
            $table->string('registration_route_provenance')->nullable();
            $table->dateTime('registration_completed_at')->nullable();
            $table->boolean('source_is_laravel')->nullable();
            $table->string('production_user')->nullable();
            $table->text('production_home')->nullable();
            $table->integer('registration_source_device')->nullable();
            $table->integer('registration_source_inode')->nullable();
            $table->string('production_php_service')->nullable();
            $table->string('production_php_pool')->nullable();
            $table->text('production_php_socket')->nullable();
            $table->string('deployment_branch')->nullable();
            $table->dateTime('runtime_definitions_captured_at')->nullable();
            $table->integer('clone_candidate_id')->nullable();
            $table->string('clone_candidate_commit')->nullable();
            $table->string('clone_requested_branch')->nullable();
            $table->string('clone_preview_name')->nullable();
            $table->string('clone_preview_domain')->nullable();
            $table->text('clone_sqlite_source_path')->nullable();
            $table->dateTime('clone_completed_at')->nullable();
            $table->integer('vite_port')->nullable();
            $table->boolean('task_workspace_routed')->nullable();
            $table->boolean('development_release_layout')->default(DB::raw('\'0\''));
            $table->string('source_prepare_id')->nullable();
            $table->text('seed_path')->nullable();
            $table->string('seed_commit')->nullable();
            $table->text('seed_repository')->nullable();
            $table->boolean('seed_selected')->default(DB::raw('\'0\''));
            $table->boolean('development_projection_pending')->default(DB::raw('\'1\''));
            $table->text('dependency_sub_projects')->nullable();
            $table->text('shared_paths')->nullable();
            $table->boolean('first_setup_pending')->default(DB::raw('\'0\''));
            $table->integer('ssr_port')->nullable();
            $table->text('environment_owned_keys')->nullable();
            $table->boolean('auto_deploy')->default(DB::raw('\'0\''));
            $table->integer('auto_deploy_resumed_after')->default(DB::raw('\'0\''));
            $table->text('auto_deploy_state')->nullable();
            $table->text('vp_environment')->nullable();
            $table->dateTime('environment_runtime_pending_at')->nullable();
            $table->integer('task_workspace_owner_id')->nullable();
            $table->foreign(['task_workspace_owner_id'])->references(['id'])->on('tasks')->onDelete('set null');
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('restrict');
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('restrict');
        });

        Schema::create('instance_removals', function (Blueprint $table): void {
            $table->string('id');
            $table->integer('requested_instance_id');
            $table->string('requested_name');
            $table->boolean('force');
            $table->string('inventory_digest');
            $table->integer('total');
            $table->enum('status', ['removing', 'failed', 'completed']);
            $table->string('current_step')->nullable();
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->primary(['id']);
        });

        Schema::create('instance_removal_members', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('instance_removal_id');
            $table->integer('position');
            $table->integer('instance_id');
            $table->integer('project_id');
            $table->integer('node_id');
            $table->integer('route_id')->nullable();
            $table->string('name');
            $table->string('environment');
            $table->string('source_layout');
            $table->string('repository_identity')->nullable();
            $table->text('checkout_path')->nullable();
            $table->text('root')->nullable();
            $table->string('branch')->nullable();
            $table->string('starting_commit')->nullable();
            $table->text('common_repository_path')->nullable();
            $table->string('source_identity')->nullable();
            $table->text('linked_worktree_paths');
            $table->string('source_digest');
            $table->dateTime('source_prepared_at')->nullable();
            $table->dateTime('route_cleared_at')->nullable();
            $table->string('route_outcome')->nullable();
            $table->dateTime('source_finalized_at')->nullable();
            $table->string('finalization_receipt')->nullable();
            $table->dateTime('runtime_cleaned_at')->nullable();
            $table->dateTime('row_deleted_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('source_commit')->nullable();
            $table->boolean('runtime_published')->default(DB::raw('\'1\''));
            $table->foreign(['instance_removal_id'])->references(['id'])->on('instance_removals')->onDelete('cascade');
        });

        Schema::create('active_app_prod_nodes', function (Blueprint $table): void {
            $table->integer('node_id');
            $table->integer('cluster_id')->nullable();
            $table->boolean('active')->default(DB::raw('\'0\''));
            $table->primary(['node_id']);
        });

        Schema::create('instance_environment_values', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('instance_id');
            $table->string('env_key');
            $table->text('env_value');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
        });

        Schema::create('schedules', function (Blueprint $table): void {
            $table->string('id');
            $table->string('target_type');
            $table->integer('target_id');
            $table->integer('host_node_id');
            $table->string('name');
            $table->string('calendar');
            $table->text('command');
            $table->integer('timeout_seconds')->default(DB::raw('\'3600\''));
            $table->string('desired_timer_state');
            $table->string('status')->default(DB::raw('\'provisioning\''));
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->string('last_run_status')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('source_definition_id')->nullable();
            $table->primary(['id']);
            $table->foreign(['host_node_id'])->references(['id'])->on('nodes')->onDelete('restrict');
        });

        Schema::create('process_definitions', function (Blueprint $table): void {
            $table->string('id');
            $table->integer('project_id');
            $table->string('name');
            $table->text('environments');
            $table->text('spec');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->primary(['id']);
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('cascade');
        });

        Schema::create('schedule_definitions', function (Blueprint $table): void {
            $table->string('id');
            $table->integer('project_id');
            $table->string('name');
            $table->text('environments');
            $table->text('spec');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->primary(['id']);
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('cascade');
        });

        Schema::create('database_connection_targets', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('database_connection_id');
            $table->integer('instance_id');
            $table->string('prefix');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['database_connection_id'])->references(['id'])->on('database_connections')->onDelete('restrict');
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
        });

        Schema::create('instance_deploy_steps', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('instance_id');
            $table->string('name');
            $table->string('phase');
            $table->text('command');
            $table->integer('timeout_seconds');
            $table->integer('position');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
        });

        Schema::create('processes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('owner_type');
            $table->integer('owner_id');
            $table->string('name');
            $table->string('runtime');
            $table->text('working_directory')->nullable();
            $table->text('runtime_config');
            $table->string('restart_policy')->default(DB::raw('\'unless-stopped\''));
            $table->string('desired_state')->default(DB::raw('\'stopped\''));
            $table->string('status')->default(DB::raw('\'provisioning\''));
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('source_definition_id')->nullable();
            $table->boolean('keep_alive')->default(DB::raw('\'0\''));
            $table->string('retired_runtime')->nullable();
            $table->string('systemd_activation_hash')->nullable();
            $table->boolean('systemd_activation_pending')->default(DB::raw('\'0\''));
            $table->boolean('restart_on_deploy')->default(DB::raw('\'1\''));
        });

        Schema::create('project_updates', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->string('status');
            $table->string('fingerprint');
            $table->string('requested_slug')->nullable();
            $table->string('requested_repository_url')->nullable();
            $table->string('requested_default_branch')->nullable();
            $table->string('requested_root')->nullable();
            $table->string('previous_slug');
            $table->string('previous_repository_url');
            $table->string('previous_default_branch')->nullable();
            $table->string('previous_root')->nullable();
            $table->text('inventory')->nullable();
            $table->text('evidence')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('cascade');
        });

        Schema::create('dependency_packages', function (Blueprint $table): void {
            $table->increments('id');
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->string('name');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('instance_dependency_observations', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('instance_id');
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->boolean('present');
            $table->dateTime('observed_at');
            $table->text('project_root');
            $table->text('source_reference')->nullable();
            $table->text('file_hashes');
            $table->string('format')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('sub_project')->default(DB::raw('\'\''));
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
        });

        Schema::create('instance_dependency_resolutions', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('observation_id');
            $table->integer('dependency_package_id');
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->text('locator');
            $table->text('version');
            $table->boolean('regular');
            $table->boolean('development');
            $table->text('source_reference')->nullable();
            $table->text('integrity')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['observation_id', 'ecosystem'])->references(['id', 'ecosystem'])->on('instance_dependency_observations')->onDelete('cascade');
            $table->foreign(['dependency_package_id', 'ecosystem'])->references(['id', 'ecosystem'])->on('dependency_packages')->onDelete('restrict');
        });

        Schema::create('instance_dependency_edges', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('observation_id');
            $table->integer('from_resolution_id')->nullable();
            $table->integer('to_resolution_id')->nullable();
            $table->string('name');
            $table->text('constraint');
            $table->enum('kind', ['dependency', 'peer']);
            $table->enum('scope', ['regular', 'development']);
            $table->boolean('optional');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['observation_id'])->references(['id'])->on('instance_dependency_observations')->onDelete('cascade');
            $table->foreign(['observation_id', 'from_resolution_id'])->references(['observation_id', 'id'])->on('instance_dependency_resolutions')->onDelete('cascade');
            $table->foreign(['observation_id', 'to_resolution_id'])->references(['observation_id', 'id'])->on('instance_dependency_resolutions')->onDelete('cascade');
        });

        Schema::create('instance_dependency_scan_attempts', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('instance_id');
            $table->enum('ecosystem', ['composer', 'npm']);
            $table->dateTime('attempted_at');
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('sub_project')->default(DB::raw('\'\''));
            $table->string('error_detail')->nullable();
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
        });

        Schema::create('vite_port_assignments', function (Blueprint $table): void {
            $table->integer('instance_id');
            $table->integer('node_id');
            $table->integer('port');
            $table->primary(['instance_id', 'node_id']);
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('restrict');
        });

        Schema::create('routes', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id')->nullable();
            $table->integer('node_id')->nullable();
            $table->integer('cluster_id')->nullable();
            $table->integer('generation_basis_node_id')->nullable();
            $table->string('domain');
            $table->string('provenance');
            $table->string('publication');
            $table->string('status')->default(DB::raw('\'pending\''));
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->integer('replaces_route_id')->nullable();
            $table->integer('replaced_by_route_id')->nullable();
            $table->string('replacement_step')->nullable();
            $table->text('target_set_intent')->nullable();
            $table->string('target_set_step')->nullable();
            $table->string('kind')->default(DB::raw('\'app\''));
            $table->boolean('sites_published')->default(DB::raw('\'0\''));
            $table->integer('transition_node_id')->nullable();
            $table->integer('transition_cluster_id')->nullable();
            $table->dateTime('transition_dns_moved_at')->nullable();
            $table->string('web_root')->nullable();
            $table->foreign(['replaced_by_route_id'])->references(['id'])->on('routes')->onDelete('set null');
            $table->foreign(['replaces_route_id'])->references(['id'])->on('routes')->onDelete('set null');
            $table->foreign(['generation_basis_node_id'])->references(['id'])->on('nodes')->onDelete('restrict');
            $table->foreign(['cluster_id'])->references(['id'])->on('clusters')->onDelete('restrict');
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('restrict');
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('restrict');
        });

        Schema::create('route_custom_proxies', function (Blueprint $table): void {
            $table->integer('route_id');
            $table->integer('node_id');
            $table->integer('process_id')->nullable();
            $table->string('upstream');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->primary(['route_id']);
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('restrict');
            $table->foreign(['process_id'])->references(['id'])->on('processes')->onDelete('restrict');
            $table->foreign(['route_id'])->references(['id'])->on('routes')->onDelete('cascade');
        });

        Schema::create('instance_deployments', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('instance_id');
            $table->string('release')->nullable();
            $table->string('branch')->nullable();
            $table->string('commit')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->string('status');
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->string('selected_release')->nullable();
            $table->string('triggered_by')->nullable();
            $table->text('events')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('kind')->default(DB::raw('\'deploy\''));
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
        });

        Schema::create('database_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('database_connection_id');
            $table->string('username');
            $table->text('privileges');
            $table->string('created_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->text('password')->nullable();
            $table->foreign(['database_connection_id'])->references(['id'])->on('database_connections')->onDelete('cascade');
        });

        Schema::create('route_analytics_trackings', function (Blueprint $table): void {
            $table->integer('route_id');
            $table->integer('instance_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->primary(['route_id']);
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
            $table->foreign(['route_id'])->references(['id'])->on('routes')->onDelete('cascade');
        });

        Schema::create('instance_transfers', function (Blueprint $table): void {
            $table->string('id');
            $table->integer('instance_id')->nullable();
            $table->integer('source_node_id');
            $table->integer('destination_node_id');
            $table->string('requested_name')->nullable();
            $table->string('destination_name');
            $table->text('destination_path');
            $table->string('destination_domain');
            $table->text('sqlite_source_path')->nullable();
            $table->string('source_layout');
            $table->text('source_path');
            $table->text('common_repository_path')->nullable();
            $table->integer('source_route_id');
            $table->integer('destination_route_id')->nullable();
            $table->string('status');
            $table->string('current_step');
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->text('recovery_evidence')->nullable();
            $table->dateTime('cutover_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->integer('source_router_node_id')->nullable();
            $table->text('imported_environment_keys')->nullable();
            $table->text('web_root_route_ids')->nullable();
            $table->primary(['id']);
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('restrict');
        });

        Schema::create('project_lifecycle_steps', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->string('phase');
            $table->string('name');
            $table->text('command');
            $table->integer('timeout_seconds');
            $table->integer('position');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('cascade');
        });

        Schema::create('project_node_exclusions', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->integer('node_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('cascade');
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
        });

        Schema::create('task_checks', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('task_id');
            $table->integer('task_comment_id')->nullable();
            $table->string('status');
            $table->integer('pid');
            $table->string('process_started');
            $table->string('head_before');
            $table->string('tree_before');
            $table->string('head_after')->nullable();
            $table->string('tree_after')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('changed_paths')->nullable();
            $table->text('output')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('kind')->default(DB::raw('\'handoff\''));
            $table->string('failed_step')->nullable();
            $table->text('deliverable_evidence')->nullable();
            $table->foreign(['task_comment_id'])->references(['id'])->on('task_comments')->onDelete('cascade');
            $table->foreign(['task_id'])->references(['id'])->on('tasks')->onDelete('cascade');
        });

        Schema::create('agent_thread_send_leases', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('agent_thread_id');
            $table->string('owner_token');
            $table->dateTime('expires_at');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['agent_thread_id'])->references(['id'])->on('agent_threads')->onDelete('cascade');
        });

        Schema::create('project_document_storages', function (Blueprint $table): void {
            $table->integer('id');
            $table->string('endpoint')->nullable();
            $table->string('region')->nullable();
            $table->string('bucket')->nullable();
            $table->text('access_key_id')->nullable();
            $table->text('secret_access_key')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->primary(['id']);
        });

        Schema::create('project_document_entries', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->integer('parent_id')->nullable();
            $table->string('kind');
            $table->string('name')->collation('BINARY');
            $table->integer('sibling_scope');
            $table->integer('revision')->default(DB::raw('\'1\''));
            $table->integer('current_version_id')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('restrict');
            $table->foreign(['parent_id'])->references(['id'])->on('project_document_entries')->onDelete('restrict');
        });

        Schema::create('project_document_uploads', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->integer('entry_id')->nullable();
            $table->string('storage_key');
            $table->string('state')->default(DB::raw('\'active\''));
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('project_document_versions', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('entry_id');
            $table->integer('upload_id');
            $table->integer('number');
            $table->string('media_type');
            $table->integer('size_bytes');
            $table->string('sha256');
            $table->string('storage_key');
            $table->integer('created_by_node_id')->nullable();
            $table->dateTime('created_at');
            $table->foreign(['entry_id'])->references(['id'])->on('project_document_entries')->onDelete('cascade');
            $table->foreign(['upload_id'])->references(['id'])->on('project_document_uploads')->onDelete('restrict');
            $table->foreign(['created_by_node_id'])->references(['id'])->on('nodes')->onDelete('set null');
        });

        Schema::create('project_document_cleanups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('storage_key');
            $table->boolean('retained_fence')->default(DB::raw('\'0\''));
            $table->boolean('pending')->default(DB::raw('\'1\''));
            $table->dateTime('next_attempt_at');
            $table->integer('attempts')->default(DB::raw('\'0\''));
            $table->string('last_error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('claim_token')->nullable();
            $table->dateTime('claim_expires_at')->nullable();
        });

        Schema::create('agent_threads', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('task_group_id');
            $table->integer('task_id')->nullable();
            $table->integer('node_id')->nullable();
            $table->string('role');
            $table->string('external_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('model')->nullable();
            $table->string('effort')->nullable();
            $table->string('driver')->default(DB::raw('\'t3\''));
            $table->string('runtime_key');
            $table->string('state')->nullable();
            $table->dateTime('observed_at')->nullable();
            $table->integer('observation_version')->default(DB::raw('\'0\''));
            $table->text('observation_error')->nullable();
            $table->text('error')->nullable();
            $table->integer('tokens')->nullable();
            $table->integer('lines_added')->nullable();
            $table->integer('lines_deleted')->nullable();
            $table->integer('input_tokens')->nullable();
            $table->integer('cached_input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('model_calls')->nullable();
            $table->integer('peak_context_tokens')->nullable();
            $table->integer('t3_input_tokens')->nullable();
            $table->integer('t3_cached_input_tokens')->nullable();
            $table->integer('t3_output_tokens')->nullable();
            $table->integer('t3_model_calls')->nullable();
            $table->integer('t3_peak_context_tokens')->nullable();
            $table->integer('t3_counted_total_processed_tokens')->nullable();
            $table->integer('t3_observed_total_processed_tokens')->nullable();
            $table->integer('t3_event_sequence')->nullable();
            $table->boolean('t3_metrics_partial')->default(DB::raw('\'0\''));
            $table->boolean('t3_metrics_initialized')->default(DB::raw('\'0\''));
            $table->dateTime('t3_metrics_collected_at')->nullable();
            $table->dateTime('t3_metrics_final_at')->nullable();
            $table->dateTime('t3_metrics_retry_at')->nullable();
            $table->integer('t3_metrics_attempts')->default(DB::raw('\'0\''));
            $table->integer('t3_metrics_activity_version')->default(DB::raw('\'0\''));
            $table->integer('t3_metrics_observed_activity_version')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->string('archive_command_id')->nullable();
            $table->integer('archive_attempts')->default(DB::raw('\'0\''));
            $table->dateTime('archive_retry_at')->nullable();
            $table->foreign(['task_id'])->references(['id'])->on('tasks')->onDelete('cascade');
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('set null');
            $table->foreign(['task_group_id'])->references(['id'])->on('tasks')->onDelete('cascade');
        });

        Schema::create('task_comments', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('task_group_id');
            $table->integer('task_id');
            $table->integer('agent_thread_id')->nullable();
            $table->integer('completion_attempt')->nullable();
            $table->string('type');
            $table->text('body');
            $table->string('author');
            $table->integer('review_attempt')->nullable();
            $table->string('commit_sha')->nullable();
            $table->dateTime('posted_at');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('receipt_hash')->nullable();
            $table->text('pull_request')->nullable();
            $table->text('deliverables')->nullable();
            $table->string('cause')->nullable();
            $table->string('request_id')->nullable();
            $table->foreign(['agent_thread_id'])->references(['id'])->on('agent_threads')->onDelete('set null');
            $table->foreign(['task_id'])->references(['id'])->on('tasks')->onDelete('cascade');
            $table->foreign(['task_group_id'])->references(['id'])->on('tasks')->onDelete('cascade');
        });

        Schema::create('jev_decisions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('purpose');
            $table->dateTime('call_started_at');
            $table->integer('task_group_id')->nullable();
            $table->integer('task_id')->nullable();
            $table->text('task_ids')->nullable();
            $table->integer('approval_comment_id')->nullable();
            $table->text('approval_changes')->nullable();
            $table->string('approval_changes_digest')->nullable();
            $table->boolean('approval_changes_redacted')->nullable();
            $table->integer('merged_pull_request_number')->nullable();
            $table->string('merge_commit_sha')->nullable();
            $table->dateTime('merged_at')->nullable();
            $table->text('merge_changes')->nullable();
            $table->string('merge_changes_digest')->nullable();
            $table->boolean('merge_changes_redacted')->nullable();
            $table->string('merge_body_digest')->nullable();
            $table->string('agent_thread_id')->nullable();
            $table->text('questions');
            $table->text('input_state');
            $table->text('answers')->nullable();
            $table->text('labels')->nullable();
            $table->string('provider_model')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['task_id'])->references(['id'])->on('tasks')->onDelete('set null');
            $table->foreign(['task_group_id'])->references(['id'])->on('tasks')->onDelete('set null');
        });

        Schema::create('project_development_deploy_steps', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->string('name');
            $table->text('command');
            $table->integer('timeout_seconds');
            $table->boolean('required')->default(DB::raw('\'1\''));
            $table->integer('position');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('cascade');
        });

        Schema::create('problem_fingerprints', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('fingerprint');
            $table->string('source');
            $table->dateTime('first_seen')->nullable();
            $table->dateTime('last_seen')->nullable();
            $table->integer('occurrences')->default(DB::raw('\'0\''));
            $table->text('evidence');
            $table->integer('task_group_id')->nullable();
            $table->dateTime('muted_until')->nullable();
            $table->dateTime('filed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['task_group_id'])->references(['id'])->on('tasks')->onDelete('set null');
        });

        Schema::create('problem_collector_state', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('activity_cursor')->nullable();
            $table->string('log_path')->nullable();
            $table->integer('log_inode')->nullable();
            $table->integer('log_offset')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('task_questions', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('task_id');
            $table->integer('subtask_id');
            $table->integer('attempt');
            $table->string('asked_by');
            $table->text('question');
            $table->string('status');
            $table->string('answered_by')->nullable();
            $table->text('answer')->nullable();
            $table->string('cause')->nullable();
            $table->dateTime('asked_at');
            $table->dateTime('escalated_at')->nullable();
            $table->dateTime('answered_at')->nullable();
            $table->integer('opened_comment_id')->nullable();
            $table->integer('resolution_comment_id')->nullable();
            $table->integer('answered_comment_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->boolean('consult')->default(DB::raw('\'0\''));
            $table->foreign(['task_id'])->references(['id'])->on('tasks')->onDelete('cascade');
            $table->foreign(['subtask_id'])->references(['id'])->on('tasks')->onDelete('cascade');
        });

        Schema::create('database_servers', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('slug');
            $table->integer('node_id');
            $table->integer('process_id')->nullable();
            $table->string('tag');
            $table->integer('port');
            $table->text('root_password');
            $table->string('status');
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('driver')->default(DB::raw('\'mysql\''));
            $table->boolean('default_open')->default(DB::raw('\'0\''));
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('restrict');
            $table->foreign(['process_id'])->references(['id'])->on('processes')->onDelete('set null');
        });

        Schema::create('database_connections', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('slug');
            $table->string('driver');
            $table->integer('node_id')->nullable();
            $table->string('host')->nullable();
            $table->integer('port')->nullable();
            $table->string('database')->nullable();
            $table->string('path')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->integer('database_server_id')->nullable();
            $table->integer('owner_instance_id')->nullable();
            $table->string('test_database')->nullable();
            $table->string('clone_step')->nullable();
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('set null');
            $table->foreign(['database_server_id'])->references(['id'])->on('database_servers')->onDelete('restrict');
            $table->foreign(['owner_instance_id'])->references(['id'])->on('instances')->onDelete('set null');
        });

        Schema::create('task_github_review_scans', function (Blueprint $table): void {
            $table->integer('group_id');
            $table->integer('sequence')->default(DB::raw('\'0\''));
            $table->text('snapshot');
            $table->primary(['group_id']);
            $table->foreign(['group_id'])->references(['id'])->on('tasks')->onDelete('cascade');
        });

        Schema::create('task_github_review_observations', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('group_id');
            $table->string('repository');
            $table->integer('pull_request_number');
            $table->integer('reviewer_id');
            $table->integer('review_id');
            $table->text('source');
            $table->text('latest');
            $table->foreign(['group_id'])->references(['id'])->on('tasks')->onDelete('cascade');
        });

        Schema::create('task_github_review_consumptions', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('group_id');
            $table->string('repository');
            $table->integer('pull_request_number');
            $table->integer('review_id');
            $table->string('identity');
            $table->integer('operator_task_id')->nullable();
            $table->integer('fixup_id')->nullable();
            $table->string('digest');
            $table->text('packet');
            $table->dateTime('created_at');
            $table->foreign(['group_id'])->references(['id'])->on('tasks')->onDelete('cascade');
            $table->foreign(['fixup_id'])->references(['id'])->on('tasks')->onDelete('set null');
        });

        Schema::create('task_github_review_feedback', function (Blueprint $table): void {
            $table->integer('group_id');
            $table->text('causes');
            $table->primary(['group_id']);
            $table->foreign(['group_id'])->references(['id'])->on('tasks')->onDelete('cascade');
        });

        Schema::create('gateway_releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('release_id')->nullable();
            $table->string('sha')->nullable();
            $table->string('trigger');
            $table->string('outcome');
            $table->boolean('migrations_ran')->default(DB::raw('\'0\''));
            $table->boolean('retryable')->default(DB::raw('\'0\''));
            $table->boolean('cleanup_paused')->default(DB::raw('\'0\''));
            $table->string('snapshot_path')->nullable();
            $table->string('previous_release_id')->nullable();
            $table->text('phases');
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->integer('duration_ms');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('requested')->nullable();
            $table->boolean('force')->default(DB::raw('\'0\''));
            $table->text('alert')->nullable();
        });

        Schema::create('package_cache_snapshots', function (Blueprint $table): void {
            $table->integer('project_id');
            $table->string('commit')->nullable();
            $table->boolean('fill_pending')->default(DB::raw('\'0\''));
            $table->text('blobs');
            $table->text('artifacts');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->primary(['project_id']);
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('cascade');
        });

        Schema::create('package_cache_entries', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->integer('host_node_id');
            $table->string('manager');
            $table->string('package');
            $table->string('version');
            $table->string('integrity');
            $table->string('key');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('cascade');
            $table->foreign(['host_node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
        });

        Schema::create('fleet_rollouts', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('gateway_release_id')->nullable();
            $table->string('commit');
            $table->string('status');
            $table->text('desired_state');
            $table->text('order');
            $table->integer('halted_node_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->text('alert')->nullable();
            $table->text('notices')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['gateway_release_id'])->references(['id'])->on('gateway_releases')->onDelete('set null');
            $table->foreign(['halted_node_id'])->references(['id'])->on('nodes')->onDelete('set null');
        });

        Schema::create('fleet_rollout_nodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('fleet_rollout_id');
            $table->integer('node_id')->nullable();
            $table->string('node_name');
            $table->integer('position');
            $table->string('outcome');
            $table->string('step')->nullable();
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->text('evidence')->nullable();
            $table->string('cli_version')->nullable();
            $table->string('agent_version')->nullable();
            $table->string('footprint_digest')->nullable();
            $table->integer('attempts')->default(DB::raw('\'0\''));
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['fleet_rollout_id'])->references(['id'])->on('fleet_rollouts')->onDelete('cascade');
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('set null');
        });

        Schema::create('node_footprints', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('node_id');
            $table->string('digest')->nullable();
            $table->text('artifacts')->nullable();
            $table->dateTime('converged_at')->nullable();
            $table->string('cli_state')->nullable();
            $table->dateTime('cli_checked_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
        });

        Schema::create('task_reviewed_commits', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('task_id');
            $table->string('sha');
            $table->string('source');
            $table->integer('review_task_id')->nullable();
            $table->dateTime('pushed_at')->nullable();
            $table->integer('github_review_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('inherited_approval_sha')->nullable();
            $table->foreign(['task_id'])->references(['id'])->on('tasks')->onDelete('cascade');
            $table->foreign(['review_task_id'])->references(['id'])->on('tasks')->onDelete('set null');
        });

        Schema::create('ai_router_process_sources', function (Blueprint $table): void {
            $table->integer('process_id');
            $table->string('source_revision')->nullable();
            $table->boolean('activation_pending')->default(DB::raw('\'0\''));
            $table->primary(['process_id']);
            $table->foreign(['process_id'])->references(['id'])->on('processes')->onDelete('cascade');
        });

        Schema::create('task_workspace_leases', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('instance_id');
            $table->integer('group_id');
            $table->integer('task_id')->nullable();
            $table->string('task_context')->nullable();
            $table->integer('acquisition_sequence')->nullable();
            $table->integer('active_instance_id')->nullable();
            $table->string('kind');
            $table->string('owner');
            $table->string('status')->default(DB::raw('\'queued\''));
            $table->integer('thread_id')->nullable();
            $table->integer('check_id')->nullable();
            $table->integer('pid')->nullable();
            $table->string('process_started')->nullable();
            $table->string('process_head')->nullable();
            $table->string('process_tree')->nullable();
            $table->string('previous_turn_id')->nullable();
            $table->string('dispatch_key')->nullable();
            $table->string('dispatch_hash')->nullable();
            $table->text('dispatch_message')->nullable();
            $table->boolean('dispatch_generated')->default(DB::raw('\'0\''));
            $table->boolean('dispatch_completed')->default(DB::raw('\'0\''));
            $table->boolean('review_baseline_required')->default(DB::raw('\'0\''));
            $table->boolean('saw_working')->default(DB::raw('\'0\''));
            $table->boolean('adopted')->default(DB::raw('\'0\''));
            $table->boolean('remote_uncertain')->default(DB::raw('\'0\''));
            $table->boolean('orbit_mutation')->default(DB::raw('\'0\''));
            $table->dateTime('dispatch_started_at')->nullable();
            $table->integer('gateway_pid')->nullable();
            $table->string('gateway_started')->nullable();
            $table->string('head_before')->nullable();
            $table->string('tree_before')->nullable();
            $table->string('head_after')->nullable();
            $table->string('tree_after')->nullable();
            $table->dateTime('acquired_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('gateway_host')->nullable();
            $table->string('gateway_boot')->nullable();
            $table->boolean('journaled')->default(DB::raw('\'0\''));
            $table->boolean('completion_pending')->default(DB::raw('\'0\''));
            $table->string('check_run_id')->nullable();
            $table->string('actor_kind')->nullable();
            $table->string('actor_owner')->nullable();
            $table->boolean('recovery_sealed')->default(DB::raw('\'0\''));
            $table->boolean('causality_uncertain')->default(DB::raw('\'0\''));
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('parent_id')->nullable();
            $table->integer('project_id')->nullable();
            $table->string('taskable_type')->nullable();
            $table->integer('taskable_id')->nullable();
            $table->string('title');
            $table->text('brief');
            $table->string('status')->default(DB::raw('\'todo\''));
            $table->integer('position')->nullable();
            $table->string('pr_url')->nullable();
            $table->string('implementer_model')->nullable();
            $table->string('reviewer_model')->nullable();
            $table->integer('tokens')->nullable();
            $table->integer('line_diff')->nullable();
            $table->integer('lines_added')->nullable();
            $table->integer('lines_deleted')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->dateTime('reserved_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->integer('reviewer_agent_thread_id')->nullable();
            $table->integer('implementer_agent_thread_id')->nullable();
            $table->boolean('assistance_requested')->default(DB::raw('\'0\''));
            $table->text('assistance_reason')->nullable();
            $table->string('implementer_agent_driver')->nullable();
            $table->string('reviewer_agent_driver')->nullable();
            $table->dateTime('agent_unavailable_since')->nullable();
            $table->dateTime('agent_unavailable_notified_at')->nullable();
            $table->string('type')->nullable();
            $table->text('completion_summary')->nullable();
            $table->string('subtask_start_commit')->nullable();
            $table->integer('completion_attempt')->nullable();
            $table->integer('completion_handoff_comment_id')->nullable();
            $table->integer('completion_reminder_attempt')->nullable();
            $table->string('completion_reminder_input_id')->nullable();
            $table->integer('completion_handoff_attempt')->nullable();
            $table->string('completion_handoff_turn_id')->nullable();
            $table->integer('review_attempt')->nullable();
            $table->integer('review_handled_comment_id')->nullable();
            $table->integer('review_reminder_attempt')->nullable();
            $table->string('review_reminder_input_id')->nullable();
            $table->integer('review_notified_attempt')->nullable();
            $table->string('review_notified_turn_id')->nullable();
            $table->string('review_workspace_head')->nullable();
            $table->string('review_workspace_tree')->nullable();
            $table->text('deliverables')->nullable();
            $table->text('fixup_problem')->nullable();
            $table->string('fixup_head_sha')->nullable();
            $table->integer('communication_failures')->nullable();
            $table->integer('resolution_delivered_comment_id')->nullable();
            $table->integer('pi_restart_resumes')->nullable();
            $table->string('pi_restart_key')->nullable();
            $table->integer('pi_restart_thread_id')->nullable();
            $table->string('pi_restart_source_turn_id')->nullable();
            $table->string('pi_restart_reservation')->nullable();
            $table->string('pi_restart_session_revision')->nullable();
            $table->integer('continuation_of_task_id')->nullable();
            $table->string('assistance_kind')->nullable();
            $table->text('assistance_question')->nullable();
            $table->integer('questions')->default(DB::raw('\'0\''));
            $table->integer('escalations')->default(DB::raw('\'0\''));
            $table->integer('direction_relay_comment_id')->nullable();
            $table->string('direction_answer_key')->nullable();
            $table->string('direction_answer_source_turn_id')->nullable();
            $table->integer('consult_comment_id')->nullable();
            $table->text('topology')->nullable();
            $table->string('watched_pr_url')->nullable();
            $table->integer('watched_pr_number')->nullable();
            $table->string('watched_pr_state')->nullable();
            $table->string('watched_pr_completion')->nullable();
            $table->integer('ended_pr_notice_thread_id')->nullable();
            $table->string('ended_pr_notice_key')->nullable();
            $table->string('ended_pr_notice_state')->nullable();
            $table->text('capacity_wait_reason')->nullable();
            $table->boolean('preview')->nullable();
            $table->string('pr_branch')->nullable();
            $table->string('merge_status')->nullable();
            $table->text('merge_reason')->nullable();
            $table->string('merged_sha')->nullable();
            $table->text('pending_resolution_reason')->nullable();
            $table->text('pending_resolution_message')->nullable();
            $table->integer('pending_resolution_comment_id')->nullable();
            $table->integer('review_workspace_lease_id')->nullable();
            $table->integer('deliverable_correction_check_id')->nullable();
            $table->text('deliverable_correction_resume')->nullable();
            $table->text('provider_retry')->nullable();
            $table->foreign(['continuation_of_task_id'])->references(['id'])->on('tasks')->onDelete('set null');
            $table->foreign(['resolution_delivered_comment_id'])->references(['id'])->on('task_comments')->onDelete('set null');
            $table->foreign(['review_handled_comment_id'])->references(['id'])->on('task_comments')->onDelete('set null');
            $table->foreign(['completion_handoff_comment_id'])->references(['id'])->on('task_comments')->onDelete('set null');
            $table->foreign(['pi_restart_thread_id'])->references(['id'])->on('agent_threads')->onDelete('set null');
            $table->foreign(['implementer_agent_thread_id'])->references(['id'])->on('agent_threads')->onDelete('set null');
            $table->foreign(['reviewer_agent_thread_id'])->references(['id'])->on('agent_threads')->onDelete('set null');
            $table->foreign(['project_id'])->references(['id'])->on('projects')->onDelete('restrict');
            $table->foreign(['parent_id'])->references(['id'])->on('tasks')->onDelete('cascade');
            $table->foreign(['pending_resolution_comment_id'])->references(['id'])->on('task_comments')->onDelete('set null');
        });

        Schema::create('task_workspace_operations', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('lease_id');
            $table->string('operation');
            $table->string('binding');
            $table->text('connection');
            $table->string('status')->default(DB::raw('\'pending\''));
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['lease_id'])->references(['id'])->on('task_workspace_leases')->onDelete('restrict');
        });

        Schema::create('route_removal_residues', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('node_id');
            $table->integer('route_id');
            $table->string('domain');
            $table->text('steps');
            $table->integer('attempts')->default(DB::raw('\'0\''));
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
        });

        Schema::create('ssr_port_assignments', function (Blueprint $table): void {
            $table->integer('instance_id');
            $table->integer('node_id');
            $table->integer('port');
            $table->primary(['instance_id', 'node_id']);
            $table->foreign(['instance_id'])->references(['id'])->on('instances')->onDelete('cascade');
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('restrict');
        });

        Schema::create('conn_profiles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->text('settings');
            $table->integer('settings_version')->default(DB::raw('\'1\''));
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->text('section_meta')->nullable();
        });

        Schema::create('conn_profile_bindings', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('node_id');
            $table->integer('conn_profile_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
            $table->foreign(['conn_profile_id'])->references(['id'])->on('conn_profiles')->onDelete('cascade');
        });

        Schema::create('conn_environments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('environment_id');
            $table->string('label');
            $table->string('url');
            $table->integer('node_id')->nullable();
            $table->string('server_version')->nullable();
            $table->text('admin_session');
            $table->dateTime('admin_session_expires_at');
            $table->dateTime('registered_at');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('set null');
        });

        Schema::create('conn_pairings', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('node_id');
            $table->integer('conn_environment_id');
            $table->string('pairing_link_id');
            $table->string('client_label');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
            $table->foreign(['conn_environment_id'])->references(['id'])->on('conn_environments')->onDelete('cascade');
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('queue');
            $table->text('payload');
            $table->integer('attempts');
            $table->integer('reserved_at')->nullable();
            $table->integer('available_at');
            $table->integer('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('uuid');
            $table->string('connection');
            $table->string('queue');
            $table->text('payload');
            $table->text('exception');
            $table->dateTime('failed_at')->default(DB::raw('CURRENT_TIMESTAMP'));
        });

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id');
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->text('failed_job_ids');
            $table->text('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
            $table->primary(['id']);
        });

        Schema::create('vault_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('source');
            $table->string('cipher_id');
            $table->string('fingerprint');
            $table->string('revision_date')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('task_vms', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('group_id')->nullable();
            $table->integer('host_node_id')->nullable();
            $table->integer('node_id')->nullable();
            $table->string('provider');
            $table->string('name');
            $table->string('state');
            $table->string('address')->nullable();
            $table->string('wireguard_ip');
            $table->text('pi_token')->nullable();
            $table->text('model_key')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('destroyed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('ai_router_origin')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('warmed_at')->nullable();
            $table->string('image_fingerprint')->nullable();
            $table->string('provider_id')->nullable();
            $table->text('ssh_host_key')->nullable();
            $table->string('size')->default(DB::raw('\'normal\''));
            $table->text('topology')->nullable();
            $table->string('topology_state')->nullable();
            $table->string('topology_run')->nullable();
            $table->dateTime('topology_started_at')->nullable();
            $table->text('topology_error')->nullable();
            $table->string('publish_commit')->nullable();
            $table->string('publish_branch')->nullable();
            $table->string('publish_state')->nullable();
            $table->text('publish_error')->nullable();
            $table->dateTime('publish_queued_at')->nullable();
            $table->dateTime('publish_started_at')->nullable();
            $table->string('recipe_identity')->nullable();
            $table->foreign(['group_id'])->references(['id'])->on('tasks');
            $table->foreign(['host_node_id'])->references(['id'])->on('nodes');
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('set null');
        });

        Schema::create('task_vm_image_builds', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('host_node_id');
            $table->string('source_alias')->nullable();
            $table->string('source_fingerprint')->nullable();
            $table->boolean('from_stock')->default(DB::raw('\'0\''));
            $table->string('builder_vm_name')->nullable();
            $table->string('image_fingerprint')->nullable();
            $table->string('current_step')->nullable();
            $table->string('current_program')->nullable();
            $table->string('state')->default(DB::raw('\'running\''));
            $table->text('steps')->default(DB::raw('\'[]\''));
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('submission_pending_at')->nullable();
            $table->dateTime('cleanup_started_at')->nullable();
            $table->integer('attempt')->default(DB::raw('\'1\''));
            $table->string('recipe_identity')->nullable();
            $table->text('recipe_json')->nullable();
            $table->integer('recipe_generation')->nullable();
            $table->text('images')->default(DB::raw('\'{}\''));
            $table->dateTime('promoted_at')->nullable();
            $table->text('fleet_convergence')->nullable();
            $table->foreign(['host_node_id'])->references(['id'])->on('nodes')->onDelete('restrict');
        });

        Schema::create('task_vm_image_recipe', function (Blueprint $table): void {
            $table->integer('id');
            $table->string('identity')->nullable();
            $table->text('recipe_json')->nullable();
            $table->integer('generation')->default(DB::raw('\'0\''));
            $table->integer('upcloud_rebuild_after_attempt')->nullable();
            $table->primary(['id']);
        });

        Schema::create('operations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('kind');
            $table->string('target_type');
            $table->integer('target_id');
            $table->integer('node_id')->nullable();
            $table->string('lock_key')->nullable();
            $table->text('input');
            $table->text('state')->nullable();
            $table->text('result')->nullable();
            $table->string('status');
            $table->integer('sequence')->default(DB::raw('\'1\''));
            $table->string('current_step')->nullable();
            $table->string('failed_step')->nullable();
            $table->string('error_code')->nullable();
            $table->integer('attempts')->default(DB::raw('\'0\''));
            $table->string('worker')->nullable();
            $table->string('triggered_by')->nullable();
            $table->string('request_id')->nullable();
            $table->dateTime('cancel_requested_at')->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('set null');
        });

        Schema::create('operation_steps', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('operation_id');
            $table->integer('position');
            $table->string('name');
            $table->string('status');
            $table->boolean('resumable');
            $table->integer('sequence');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('output')->nullable();
            $table->boolean('output_truncated')->default(DB::raw('\'0\''));
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['operation_id'])->references(['id'])->on('operations')->onDelete('cascade');
        });

        Schema::create('upcloud_task_vm_image_builds', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('recipe_identity');
            $table->string('state')->default(DB::raw('\'running\''));
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('template_uuid')->nullable();
            $table->text('failure')->nullable();
        });

        Schema::create('ai_router_cutovers', function (Blueprint $table): void {
            $table->integer('node_id');
            $table->integer('process_id');
            $table->primary(['node_id']);
            $table->foreign(['node_id'])->references(['id'])->on('nodes')->onDelete('cascade');
        });

        // SQLite-specific indexes and triggers, followed by the already-squashed history.
        // Laravel records this baseline itself as migration 262 after up() returns.
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX `firewall_rules_node_id_name_unique` on `firewall_rules`(
              `node_id`,
              `name`
            );
            CREATE INDEX `firewall_rules_status_index` on `firewall_rules`(`status`);
            CREATE UNIQUE INDEX `settings_scope_type_scope_id_key_unique` on `settings`(
              `scope_type`,
              `scope_id`,
              `key`
            );
            CREATE INDEX `settings_scope_type_index` on `settings`(`scope_type`);
            CREATE INDEX `subject` on `activity_log`(`subject_type`, `subject_id`);
            CREATE INDEX `causer` on `activity_log`(`causer_type`, `causer_id`);
            CREATE INDEX `activity_log_log_name_index` on `activity_log`(`log_name`);
            CREATE INDEX `activity_log_request_id_index` on `activity_log`(`request_id`);
            CREATE INDEX `activity_log_command_index` on `activity_log`(`command`);
            CREATE INDEX `activity_log_status_index` on `activity_log`(`status`);
            CREATE INDEX `activity_log_error_code_index` on `activity_log`(`error_code`);
            CREATE UNIQUE INDEX `node_access_consumer_node_id_serving_node_id_unique` on `node_access`(
              `consumer_node_id`,
              `serving_node_id`
            );
            CREATE UNIQUE INDEX `tool_managers_node_id_name_unique` on `tool_managers`(
              `node_id`,
              `name`
            );
            CREATE UNIQUE INDEX `tools_node_id_tool_manager_id_package_unique` on `tools`(
              `node_id`,
              `tool_manager_id`,
              `package`
            );
            CREATE INDEX `tools_node_id_status_index` on `tools`(`node_id`, `status`);
            CREATE UNIQUE INDEX `clusters_name_unique` on `clusters`(`name`);
            CREATE UNIQUE INDEX `clusters_tld_unique` on `clusters`(`tld`);
            CREATE INDEX `clusters_state_index` on `clusters`(`state`);
            CREATE UNIQUE INDEX `nodes_name_unique` on `nodes`(`name`);
            CREATE INDEX `nodes_status_index` on `nodes`(`status`);
            CREATE UNIQUE INDEX `nodes_tld_unique` on `nodes`(`tld`);
            CREATE UNIQUE INDEX `nodes_wireguard_ip_unique` on `nodes`(`wireguard_ip`);
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
            CREATE UNIQUE INDEX `schedules_target_definition_unique` on `schedules`(
              `target_type`,
              `target_id`,
              `source_definition_id`
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
            CREATE UNIQUE INDEX `dependency_packages_ecosystem_name_unique` on `dependency_packages`(
              `ecosystem`,
              `name`
            );
            CREATE UNIQUE INDEX `dependency_packages_id_ecosystem_unique` on `dependency_packages`(
              `id`,
              `ecosystem`
            );
            CREATE UNIQUE INDEX `dependency_observation_id_ecosystem` on `instance_dependency_observations`(
              `id`,
              `ecosystem`
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
            CREATE INDEX `dependency_attempt_latest` on `instance_dependency_scan_attempts`(
              `instance_id`,
              `ecosystem`,
              `id`
            );
            CREATE UNIQUE INDEX `vite_port_assignments_node_id_port_unique` on `vite_port_assignments`(
              `node_id`,
              `port`
            );
            CREATE UNIQUE INDEX `routes_hostname_unique` on `routes`(`domain`);
            CREATE INDEX `routes_status_index` on `routes`(`status`);
            CREATE UNIQUE INDEX `database_users_database_connection_id_username_unique` on `database_users`(
              `database_connection_id`,
              `username`
            );
            CREATE INDEX `task_checks_task_id_id_index` on `task_checks`(`task_id`, `id`);
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
                SELECT RAISE(ABORT, 'An active Instance requires exactly one authoritative Route.');
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
                SELECT RAISE(ABORT, 'An active Instance requires exactly one authoritative Route.');
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
                SELECT RAISE(ABORT, 'An active Instance requires exactly one authoritative Route.');
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
                SELECT RAISE(ABORT, 'Shared production Route target Instance cannot invalidate its target set.');
            END;
            CREATE TRIGGER `instance_removal_members_delete`
            BEFORE DELETE ON instance_removal_members
            BEGIN
                SELECT RAISE(ABORT, 'Instance removal member evidence is immutable.');
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
                SELECT RAISE(ABORT, 'Invalid Instance removal member contract.');
            END;
            CREATE TRIGGER `instance_removal_members_source_commit_immutable`
            BEFORE UPDATE ON instance_removal_members
            WHEN NEW.source_commit IS NOT OLD.source_commit
            BEGIN
                SELECT RAISE(ABORT, 'Invalid Instance removal member contract.');
            END;
            CREATE TRIGGER `instance_removals_delete`
            BEFORE DELETE ON instance_removals
            BEGIN
                SELECT RAISE(ABORT, 'Instance removal evidence is immutable.');
            END;
            CREATE TRIGGER `instances_production_placement_insert` BEFORE INSERT ON instances WHEN EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = NEW.node_id AND node_roles.role = 'app-prod' AND node_roles.status IN ('active', 'removing')) AND EXISTS (SELECT 1 FROM instances WHERE project_id = NEW.project_id AND node_id = NEW.node_id AND EXISTS (SELECT 1 FROM node_roles AS existing_role WHERE existing_role.node_id = instances.node_id AND existing_role.role = 'app-prod' AND existing_role.status IN ('active', 'removing'))) BEGIN SELECT RAISE(ABORT, 'Production Instance placement already exists.'); END;
            CREATE TRIGGER `instances_production_placement_update` BEFORE UPDATE OF project_id, node_id ON instances WHEN EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = NEW.node_id AND node_roles.role = 'app-prod' AND node_roles.status IN ('active', 'removing')) AND EXISTS (SELECT 1 FROM instances WHERE id <> OLD.id AND project_id = NEW.project_id AND node_id = NEW.node_id AND EXISTS (SELECT 1 FROM node_roles AS existing_role WHERE existing_role.node_id = instances.node_id AND existing_role.role = 'app-prod' AND existing_role.status IN ('active', 'removing'))) BEGIN SELECT RAISE(ABORT, 'Production Instance placement already exists.'); END;
            CREATE TRIGGER `instances_removal_status_insert`
            BEFORE INSERT ON instances
            WHEN NEW.status = 'removing'
            BEGIN
                SELECT RAISE(ABORT, 'A removing Instance requires recorded removal membership.');
            END;
            CREATE TRIGGER `projects_code_insert` BEFORE insert ON projects WHEN NEW.code IS NULL OR NEW.code NOT GLOB '[A-Z][A-Z][A-Z]' BEGIN SELECT RAISE(ABORT, 'Project code must contain three uppercase letters'); END;
            CREATE TRIGGER `projects_code_update` BEFORE update ON projects WHEN NEW.code IS NULL OR NEW.code NOT GLOB '[A-Z][A-Z][A-Z]' BEGIN SELECT RAISE(ABORT, 'Project code must contain three uppercase letters'); END;
            CREATE UNIQUE INDEX `project_document_entries_project_id_sibling_scope_name_unique` on `project_document_entries`(
              `project_id`,
              `sibling_scope`,
              `name`
            );
            CREATE INDEX `project_document_uploads_state_created_at_index` on `project_document_uploads`(
              `state`,
              `created_at`
            );
            CREATE UNIQUE INDEX `project_document_uploads_storage_key_unique` on `project_document_uploads`(
              `storage_key`
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
            CREATE UNIQUE INDEX `project_document_cleanups_storage_key_unique` on `project_document_cleanups`(
              `storage_key`
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
            CREATE INDEX `task_comments_task_id_posted_at_index` on `task_comments`(
              `task_id`,
              `posted_at`
            );
            CREATE UNIQUE INDEX `task_comments_task_id_receipt_hash_unique` on `task_comments`(
              `task_id`,
              `receipt_hash`
            );
            CREATE INDEX `jev_decisions_purpose_created_at_index` on `jev_decisions`(
              `purpose`,
              `created_at`
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
            CREATE INDEX `problem_fingerprints_source_index` on `problem_fingerprints`(
              `source`
            );
            CREATE UNIQUE INDEX `problem_fingerprints_fingerprint_unique` on `problem_fingerprints`(
              `fingerprint`
            );
            CREATE UNIQUE INDEX `task_questions_opened_comment_id_unique` on `task_questions`(
              `opened_comment_id`
            );
            CREATE INDEX `task_questions_asked_at_index` on `task_questions`(`asked_at`);
            CREATE INDEX `task_questions_status_cause_index` on `task_questions`(
              `status`,
              `cause`
            );
            CREATE UNIQUE INDEX `database_servers_slug_unique` on `database_servers`(
              `slug`
            );
            CREATE UNIQUE INDEX `database_connections_slug_unique` on `database_connections`(
              `slug`
            );
            CREATE UNIQUE INDEX `task_github_approval_source` on `task_github_review_observations`(
              `group_id`,
              `repository`,
              `pull_request_number`,
              `review_id`
            );
            CREATE UNIQUE INDEX `task_github_review_consumption_source` on `task_github_review_consumptions`(
              `group_id`,
              `repository`,
              `pull_request_number`,
              `review_id`
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
                SELECT RAISE(ABORT, 'Invalid Instance removal contract.');
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
            CREATE UNIQUE INDEX `fleet_rollouts_gateway_release_id_unique` on `fleet_rollouts`(
              `gateway_release_id`
            );
            CREATE INDEX `fleet_rollouts_commit_index` on `fleet_rollouts`(`commit`);
            CREATE INDEX `fleet_rollouts_status_index` on `fleet_rollouts`(`status`);
            CREATE UNIQUE INDEX `fleet_rollout_nodes_fleet_rollout_id_node_id_unique` on `fleet_rollout_nodes`(
              `fleet_rollout_id`,
              `node_id`
            );
            CREATE UNIQUE INDEX `node_footprints_node_id_unique` on `node_footprints`(
              `node_id`
            );
            CREATE UNIQUE INDEX `task_reviewed_commits_task_id_sha_unique` on `task_reviewed_commits`(
              `task_id`,
              `sha`
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
            CREATE INDEX `task_workspace_operations_lease_id_status_index` on `task_workspace_operations`(
              `lease_id`,
              `status`
            );
            CREATE UNIQUE INDEX `task_workspace_operations_operation_unique` on `task_workspace_operations`(
              `operation`
            );
            CREATE UNIQUE INDEX `route_removal_residues_node_id_route_id_unique` on `route_removal_residues`(
              `node_id`,
              `route_id`
            );
            CREATE UNIQUE INDEX `instances_node_id_ssr_port_unique` on `instances`(
              `node_id`,
              `ssr_port`
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
                SELECT RAISE(ABORT, 'An active Instance requires exactly one authoritative Route.');
            END;
            CREATE INDEX `jobs_queue_index` on `jobs`(`queue`);
            CREATE INDEX `failed_jobs_connection_queue_failed_at_index` on `failed_jobs`(
              `connection`,
              `queue`,
              `failed_at`
            );
            CREATE UNIQUE INDEX `failed_jobs_uuid_unique` on `failed_jobs`(`uuid`);
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
            CREATE UNIQUE INDEX `vault_items_source_unique` on `vault_items`(`source`);
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
                SELECT RAISE(ABORT, 'Invalid Instance removal contract.');
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
                SELECT RAISE(ABORT, 'Invalid Instance removal member contract.');
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
                SELECT RAISE(ABORT, 'A removing Instance requires recorded removal membership.');
            END;
            CREATE UNIQUE INDEX instances_node_id_checkout_path_unique ON instances(
              node_id,
              checkout_path
            );
            CREATE UNIQUE INDEX task_vm_image_builds_running_host_unique ON task_vm_image_builds(
              host_node_id
            ) WHERE state = 'running';
            CREATE INDEX `operations_status_id_index` on `operations`(`status`, `id`);
            CREATE INDEX `operations_target_type_target_id_id_index` on `operations`(
              `target_type`,
              `target_id`,
              `id`
            );
            CREATE UNIQUE INDEX `operations_lock_key_unique` on `operations`(`lock_key`);
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
            CREATE INDEX `task_vm_image_builds_host_node_id_recipe_identity_index` on `task_vm_image_builds`(
              `host_node_id`,
              `recipe_identity`
            );
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
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('The schema baseline cannot be rolled back.');
    }
};

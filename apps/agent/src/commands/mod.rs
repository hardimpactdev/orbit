//! The named commands this binary implements, by area.

pub mod accounts;
pub mod agent;
pub mod agent_footprint;
mod analytics_clickhouse;
pub mod application_log;
pub mod caddy;
pub mod caddy_access;
mod caddy_certificate;
mod caddy_package;
mod caddy_publication;
pub mod ci_runner;
pub mod cli;
mod cli_update;
pub mod clone_candidate;
mod composer_global;
mod composer_scope;
pub mod database_server;
pub mod dependencies;
pub mod deploy_step;
pub mod dev_certificates;
pub mod dev_deploy;
pub mod dev_source;
mod docker_migration;
mod doctor_node;
mod doctor_project;
mod doctor_public_route;
mod doctor_role;
mod doctor_route;
pub mod environment;
pub mod firewall;
mod gateway_adoption;
mod gateway_build;
mod gateway_certificate;
mod gateway_checkout;
mod gateway_database;
mod gateway_drain;
mod gateway_fpm;
mod gateway_horizon;
mod gateway_identity;
mod gateway_recovery;
mod gateway_releases;
mod gateway_scheduler;
mod gateway_services;
pub mod gateway_start_hooks;
mod gateway_units;
mod gateway_web;
mod gateway_web_build;
mod git_read;
mod guest;
pub mod health;
pub mod hibernation;
mod homebrew;
mod homebrew_scope;
pub mod horizon_queue;
pub mod http_check;
mod image_boot;
mod image_build;
mod image_identity;
pub mod image_recipe;
mod incus;
pub mod instances;
pub mod laravel;
mod macos_enrollment;
pub mod metrics_certificate;
mod metrics_exporter;
mod metrics_publication;
mod metrics_runtime;
mod node_bootstrap;
mod node_identity;
pub mod os_release;
mod package_cache;
pub mod peer_dns;
pub mod php_build;
mod php_fpm;
mod php_profile;
pub mod php_runtime;
mod php_shared;
pub mod pi_artifact;
pub mod pi_runtime;
pub mod plausible;
pub mod processes;
pub mod production;
pub mod production_home;
pub mod project_source;
pub mod recipe;
pub mod recipe_delivery;
pub mod registration;
pub mod role_prerequisites;
pub mod schedules;
pub mod service_metrics;
pub mod source;
pub mod sqlite_copy;
mod sqlite_query;
pub mod sqlite_seed;
pub mod sqlite_transfer;
mod ssh_host_key;
pub mod storage_root;
pub mod task_git;
pub mod task_git_export;
pub mod task_git_import;
pub(crate) mod task_topology;
mod task_vm_hub;
pub mod tls_check;
pub mod tmpfiles;
mod tool_output;
mod tools_apt;
pub mod topology_evidence;
pub mod transfer_source;
pub mod valkey_acl;
pub mod vite;
mod vp_global;
mod vp_materialize;
pub mod vp_project;
mod vp_scope;
mod vp_store;
mod vpn_ordering;
pub mod websocket_runtime;
pub mod wireguard;
mod wireguard_peer;

use crate::command::registry::{entry, Entry};
use crate::command::{Platform, Privilege};

/// A privilege on Linux, and no support elsewhere: the rule of every Node-only command.
pub fn linux_only(platform: Platform, privilege: Privilege) -> Option<Privilege> {
    (platform == Platform::Linux).then_some(privilege)
}

pub fn all() -> Vec<Entry> {
    vec![
        entry::<analytics_clickhouse::Configure>(),
        entry::<task_topology::Start>(),
        entry::<task_topology::Inspect>(),
        entry::<agent::Status>(),
        entry::<gateway_adoption::preparation::Check>(),
        entry::<gateway_adoption::preparation::Repository>(),
        entry::<gateway_adoption::preparation::Origin>(),
        entry::<gateway_adoption::preparation::Target>(),
        entry::<gateway_adoption::preparation::Environment>(),
        entry::<gateway_adoption::preparation::RestoreEnvironment>(),
        entry::<gateway_releases::Switch>(),
        entry::<gateway_releases::Forward>(),
        entry::<gateway_releases::Source>(),
        entry::<gateway_build::Inspect>(),
        entry::<gateway_build::Prepare>(),
        entry::<gateway_build::Finish>(),
        entry::<gateway_build::Refresh>(),
        entry::<gateway_build::Remove>(),
        entry::<gateway_build::Migrate>(),
        entry::<gateway_build::CheckMigrations>(),
        entry::<gateway_build::Handoff>(),
        entry::<gateway_drain::InspectHold>(),
        entry::<gateway_drain::InspectProcess>(),
        entry::<gateway_recovery::Protect>(),
        entry::<gateway_recovery::Verify>(),
        entry::<gateway_recovery::WriteReceipt>(),
        entry::<gateway_recovery::ReadReceipt>(),
        entry::<gateway_web_build::Inspect>(),
        entry::<gateway_web_build::Install>(),
        entry::<gateway_web_build::Publish>(),
        entry::<gateway_web_build::Restore>(),
        entry::<gateway_web_build::Remove>(),
        entry::<gateway_web_build::Prune>(),
        entry::<gateway_units::Converge>(),
        entry::<gateway_services::Fleet>(),
        entry::<gateway_services::View>(),
        entry::<gateway_services::Hibernator>(),
        entry::<gateway_services::StartFleet>(),
        entry::<gateway_horizon::Drain>(),
        entry::<gateway_scheduler::Drain>(),
        entry::<gateway_scheduler::Start>(),
        entry::<gateway_scheduler::Stop>(),
        entry::<gateway_scheduler::Restart>(),
        entry::<gateway_units::Start>(),
        entry::<gateway_units::Inspect>(),
        entry::<gateway_database::Inspect>(),
        entry::<gateway_database::Create>(),
        entry::<gateway_identity::Prepare>(),
        entry::<gateway_identity::SelfAccess>(),
        entry::<gateway_adoption::Storage>(),
        entry::<gateway_adoption::Switch>(),
        entry::<gateway_adoption::Restore>(),
        entry::<gateway_adoption::Finish>(),
        entry::<gateway_fpm::Converge>(),
        entry::<gateway_fpm::Connections>(),
        entry::<vp_store::Inspect>(),
        entry::<vp_store::Install>(),
        entry::<vp_store::Prune>(),
        entry::<vp_project::ReadProject>(),
        entry::<vp_scope::Inspect>(),
        entry::<vp_materialize::Materialize>(),
        entry::<composer_global::Version>(),
        entry::<composer_global::Candidate>(),
        entry::<composer_global::Inventory>(),
        entry::<composer_global::Plan>(),
        entry::<composer_global::Install>(),
        entry::<composer_global::Update>(),
        entry::<composer_global::Remove>(),
        entry::<homebrew_scope::Inspect>(),
        entry::<homebrew_scope::Materialize>(),
        entry::<homebrew::Version>(),
        entry::<homebrew::FormulaInventory>(),
        entry::<homebrew::formula::Candidate>(),
        entry::<homebrew::formula::Adoption>(),
        entry::<homebrew::formula::Install>(),
        entry::<homebrew::formula::Update>(),
        entry::<homebrew::formula::Remove>(),
        entry::<homebrew::cask::Inventory>(),
        entry::<homebrew::cask::Candidate>(),
        entry::<homebrew::cask::Adoption>(),
        entry::<homebrew::cask::Install>(),
        entry::<homebrew::cask::Update>(),
        entry::<homebrew::cask::Remove>(),
        entry::<homebrew::scan::Scan>(),
        entry::<composer_scope::Inspect>(),
        entry::<composer_scope::Materialize>(),
        entry::<tools_apt::Version>(),
        entry::<tools_apt::Candidate>(),
        entry::<tools_apt::Inventory>(),
        entry::<tools_apt::Plan>(),
        entry::<tools_apt::Install>(),
        entry::<tools_apt::Update>(),
        entry::<tools_apt::Remove>(),
        entry::<vp_global::Version>(),
        entry::<vp_global::Candidate>(),
        entry::<vp_global::Inventory>(),
        entry::<vp_global::Plan>(),
        entry::<vp_global::Install>(),
        entry::<vp_global::Update>(),
        entry::<vp_global::Remove>(),
        entry::<image_recipe::lifecycle::Inspect>(),
        entry::<image_recipe::lifecycle::Begin>(),
        entry::<image_recipe::lifecycle::Retry>(),
        entry::<image_recipe::verify::Verify>(),
        entry::<pi_artifact::Receive>(),
        entry::<pi_artifact::Install>(),
        entry::<pi_artifact::Inspect>(),
        entry::<pi_runtime::Configure>(),
        entry::<gateway_web::PrepareDirectory>(),
        entry::<gateway_certificate::Publish>(),
        entry::<gateway_checkout::Check>(),
        entry::<gateway_checkout::Prepare>(),
        entry::<vpn_ordering::OrderCaddy>(),
        entry::<vpn_ordering::RemoveDnsOrdering>(),
        entry::<php_build::operations::Inspect>(),
        entry::<php_build::operations::Install>(),
        entry::<php_profile::Configure>(),
        entry::<docker_migration::Plan>(),
        entry::<docker_migration::Prepare>(),
        entry::<docker_migration::Restore>(),
        entry::<docker_migration::Report>(),
        entry::<recipe_delivery::Verify>(),
        entry::<recipe::operations::Inspect>(),
        entry::<recipe::operations::IncusPreflight>(),
        entry::<recipe::operations::Apply>(),
        entry::<recipe::javascript::Install>(),
        entry::<image_build::WriteMarker>(),
        entry::<image_build::AppendLog>(),
        entry::<incus::ReadImages>(),
        entry::<incus::ReadInstances>(),
        entry::<incus::ReadInventory>(),
        entry::<incus::setup::PrepareHost>(),
        entry::<incus::volumes::ReadVolumes>(),
        entry::<incus::volumes::ChangeVolume>(),
        entry::<incus::vm::ReadVm>(),
        entry::<incus::vm::ChangeVm>(),
        entry::<image_identity::CleanImage>(),
        entry::<image_boot::PrepareBoot>(),
        entry::<image_boot::PrepareFirewall>(),
        entry::<image_boot::CheckImage>(),
        entry::<image_boot::CheckOwner>(),
        entry::<guest::ReadCloudInit>(),
        entry::<guest::ReadFingerprint>(),
        entry::<image_identity::PrepareIncus>(),
        entry::<incus::image::ReadInstanceImages>(),
        entry::<incus::image::ChangeImage>(),
        entry::<incus::seed::BuildSeed>(),
        entry::<incus::cache::MountCache>(),
        entry::<package_cache::HostCache>(),
        entry::<package_cache::SeedCache>(),
        entry::<task_vm_hub::PrepareHub>(),
        entry::<agent::Install>(),
        entry::<agent_footprint::ConfigureAgent>(),
        entry::<agent_footprint::RemoveAgent>(),
        entry::<storage_root::PrepareStorageRoot>(),
        entry::<tmpfiles::PublishTmpfiles>(),
        entry::<websocket_runtime::ConvergeRuntime>(),
        entry::<websocket_runtime::RemoveRuntime>(),
        entry::<websocket_runtime::RuntimeActive>(),
        entry::<ci_runner::CiRunnerHost>(),
        entry::<horizon_queue::ReadQueue>(),
        entry::<valkey_acl::WriteValkeyAcl>(),
        entry::<cli::InspectCli>(),
        entry::<cli::InstallCli>(),
        entry::<cli_update::SelfUpdate>(),
        entry::<os_release::ReadOsRelease>(),
        entry::<node_bootstrap::Bootstrap>(),
        entry::<macos_enrollment::Inspect>(),
        entry::<ssh_host_key::Scan>(),
        entry::<role_prerequisites::InstallPrerequisites>(),
        entry::<instances::InspectSource>(),
        entry::<instances::RemoveSource>(),
        entry::<instances::PrepareRemoval>(),
        entry::<instances::FinalizeRemoval>(),
        entry::<instances::ReadReceipt>(),
        entry::<instances::CleanReceipt>(),
        entry::<instances::ReadRemovalState>(),
        entry::<instances::CheckPublication>(),
        entry::<instances::InspectLaravelRoutes>(),
        entry::<dev_source::PrepareDevelopmentSource>(),
        entry::<dev_source::InspectDevelopmentSource>(),
        entry::<dev_source::ResolveDevelopmentSource>(),
        entry::<registration::InspectRegistrationSource>(),
        entry::<registration::RelocateRegistrationSource>(),
        entry::<registration::LaravelRegistrationReceipt>(),
        entry::<production::PrepareRelease>(),
        entry::<production::ActivateRelease>(),
        entry::<production::InspectRelease>(),
        entry::<production::ListReleases>(),
        entry::<production::PruneReleases>(),
        entry::<production::InspectRepositoryBinding>(),
        entry::<production::RebindRepository>(),
        entry::<production::PrepareServedWebRoots>(),
        entry::<production::CheckServedWebRoots>(),
        entry::<deploy_step::RunDeployStep>(),
        entry::<application_log::ReadApplicationLog>(),
        entry::<instances::CheckPathFree>(),
        entry::<environment::ReadEnvironment>(),
        entry::<environment::InspectEnvironmentSync>(),
        entry::<environment::WriteEnvironment>(),
        entry::<laravel::InspectProfile>(),
        entry::<laravel::WriteUrl>(),
        entry::<caddy_access::GrantDevelopmentCaddyAccess>(),
        entry::<clone_candidate::InspectCloneCandidate>(),
        entry::<sqlite_copy::CopySqlite>(),
        entry::<sqlite_transfer::ReadSnapshot>(),
        entry::<sqlite_transfer::ReceiveSnapshot>(),
        entry::<sqlite_query::Query>(),
        entry::<sqlite_query::Write>(),
        entry::<sqlite_seed::PrepareSqliteSeed>(),
        entry::<sqlite_seed::InstallSqliteSeed>(),
        entry::<transfer_source::TransferSource>(),
        entry::<transfer_source::ReadArchive>(),
        entry::<transfer_source::Materialize>(),
        entry::<vite::SelectVitePort>(),
        entry::<vite::CheckViteListener>(),
        entry::<vite::SetViteTraffic>(),
        entry::<firewall::native::Inspect>(),
        entry::<firewall::native::Rule>(),
        entry::<firewall::native::rules::Rules>(),
        entry::<firewall::native::rules::Router>(),
        entry::<firewall::native::route::RouteLan>(),
        entry::<firewall::native::gateway::GatewayVpn>(),
        entry::<tls_check::VerifyCertificate>(),
        entry::<http_check::VerifyHttp>(),
        entry::<topology_evidence::ReadTopologyEvidence>(),
        entry::<task_git::ReadRefs>(),
        entry::<task_git_export::ExportPack>(),
        entry::<task_git_import::ImportPack>(),
        entry::<caddy_certificate::InstallCertificate>(),
        entry::<caddy::Reload>(),
        entry::<caddy_publication::Publish>(),
        entry::<caddy_publication::CheckAddresses>(),
        entry::<caddy_publication::ReadLive>(),
        entry::<doctor_node::InspectNode>(),
        entry::<doctor_project::InspectProject>(),
        entry::<doctor_project::InspectDevelopmentSource>(),
        entry::<php_runtime::doctor::InspectProduction>(),
        entry::<doctor_role::InspectRole>(),
        entry::<doctor_route::InspectPrivateRoute>(),
        entry::<doctor_route::InspectApplicationUrls>(),
        entry::<doctor_public_route::InspectPublicRoute>(),
        entry::<doctor_route::InspectCustomProxy>(),
        entry::<caddy_certificate::RemoveCertificate>(),
        entry::<caddy_package::InstallPackage>(),
        entry::<health::AwaitHealthy>(),
        entry::<metrics_certificate::MetricsCertificate>(),
        entry::<metrics_exporter::NodeExporter>(),
        entry::<metrics_exporter::Cadvisor>(),
        entry::<metrics_runtime::MetricsRuntime>(),
        entry::<metrics_publication::Publication>(),
        entry::<metrics_runtime::MetricsHealth>(),
        entry::<metrics_runtime::MetricsCredentials>(),
        entry::<service_metrics::ServiceRuntime>(),
        entry::<project_source::UpdateProjectSource>(),
        entry::<database_server::DatabaseServerAdmin>(),
        entry::<hibernation::PrepareDirectories>(),
        entry::<hibernation::MarkHibernation>(),
        entry::<hibernation::ReadHibernationTimes>(),
        entry::<hibernation::InspectCheckout>(),
        entry::<hibernation::PruneCheckout>(),
        entry::<hibernation::RestoreDependencies>(),
        entry::<processes::doctor::InspectProcess>(),
        entry::<schedules::InspectSchedule>(),
        entry::<schedules::ScheduleOrphans>(),
        entry::<schedules::InstallSchedule>(),
        entry::<schedules::ActivateSchedule>(),
        entry::<schedules::RunSchedule>(),
        entry::<schedules::ReadScheduleLogs>(),
        entry::<schedules::RemoveSchedule>(),
        entry::<accounts::LookupAccount>(),
        entry::<processes::CheckRelease>(),
        entry::<processes::ReadStatus>(),
        entry::<processes::ControlProcess>(),
        entry::<processes::RemoveProcess>(),
        entry::<processes::StageUnit>(),
        entry::<processes::ActivateUnit>(),
        entry::<processes::ConvergeContainer>(),
        entry::<processes::ReadLogs>(),
        entry::<dev_certificates::RequestLeaf>(),
        entry::<dev_certificates::PublishLeaf>(),
        entry::<dev_certificates::InspectLeaf>(),
        entry::<dev_certificates::RemoveLeaf>(),
        entry::<dependencies::ReadFiles>(),
        entry::<dependencies::ReadReleaseFiles>(),
        entry::<dependencies::ProbeUpdate>(),
        entry::<dependencies::UpdateDependencies>(),
        entry::<dev_deploy::ResolveTarget>(),
        entry::<dev_deploy::CheckoutDefault>(),
        entry::<dev_deploy::RunStep>(),
        entry::<dev_deploy::RunLifecycleStep>(),
        entry::<production_home::PrepareUser>(),
        entry::<production_home::PrepareSource>(),
        entry::<production_home::ResolveSource>(),
        entry::<production_home::InspectProfile>(),
        entry::<production_home::GrantCaddy>(),
        entry::<production_home::ValidateLayout>(),
        entry::<production_home::ClearCurrent>(),
        entry::<production_home::RemoveHome>(),
        entry::<production_home::WriteDirectoryUrl>(),
        entry::<production_home::RefreshConfigCache>(),
        entry::<php_shared::Inspect>(),
        entry::<php_shared::Converge>(),
        entry::<php_runtime::ConvergeRuntime>(),
        entry::<php_runtime::RemoveRuntime>(),
        entry::<php_runtime::InspectPool>(),
        entry::<php_runtime::RefreshCache>(),
        entry::<wireguard::ProjectHub>(),
        entry::<wireguard_peer::PrepareKey>(),
        entry::<wireguard_peer::Publish>(),
        entry::<wireguard_peer::Commit>(),
        entry::<wireguard_peer::Rollback>(),
        entry::<wireguard::BootstrapHub>(),
        entry::<wireguard::InspectHub>(),
        entry::<peer_dns::InspectPeerDns>(),
        entry::<peer_dns::QueryDns>(),
        entry::<peer_dns::RepairPeerDns>(),
    ]
}

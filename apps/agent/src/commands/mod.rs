//! The named commands this binary implements, by area.

pub mod accounts;
pub mod agent;
pub mod agent_footprint;
pub mod application_log;
pub mod caddy;
pub mod caddy_access;
mod caddy_certificate;
mod caddy_observation;
mod caddy_package;
pub mod ci_runner;
pub mod cli;
mod composer_global;
pub mod deploy_step;
pub mod dev_certificates;
pub mod dev_deploy;
pub mod environment;
pub mod firewall;
pub mod gateway_start_hooks;
mod git_read;
mod guest;
pub mod hibernation;
mod homebrew;
mod homebrew_scope;
pub mod http_check;
mod incus;
pub mod instances;
pub mod laravel;
mod macos_enrollment;
mod metrics_publication;
mod node_bootstrap;
mod node_identity;
pub mod node_worker;
pub mod os_release;
mod package_cache;
pub mod peer_dns;
pub mod php_build;
mod php_fpm;
mod php_profile;
pub mod php_runtime;
mod php_shared;
pub mod plausible;
pub mod processes;
pub mod production;
pub mod production_home;
pub mod project_source;
pub mod recipe;
pub mod role_prerequisites;
pub mod schedules;
pub mod source;
mod ssh_host_key;
pub mod storage_root;
pub mod task_git;
pub mod task_git_import;
pub(crate) mod task_topology;
pub mod tls_check;
mod tool_output;
mod tools_apt;
pub mod topology_evidence;
pub mod vite;
mod vp_global;
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
        entry::<task_topology::Start>(),
        entry::<task_topology::Inspect>(),
        entry::<agent::Status>(),
        entry::<vp_store::Inspect>(),
        entry::<vp_scope::Inspect>(),
        entry::<composer_global::Inventory>(),
        entry::<homebrew::FormulaInventory>(),
        entry::<homebrew::cask::Inventory>(),
        entry::<tools_apt::Inventory>(),
        entry::<vp_global::Version>(),
        entry::<vp_global::Inventory>(),
        entry::<vpn_ordering::OrderCaddy>(),
        entry::<php_build::operations::Inspect>(),
        entry::<php_build::operations::Install>(),
        entry::<php_profile::Configure>(),
        entry::<recipe::javascript::Install>(),
        entry::<incus::ReadImages>(),
        entry::<incus::ReadInstances>(),
        entry::<incus::ReadInventory>(),
        entry::<incus::volumes::ReadVolumes>(),
        entry::<incus::vm::ReadVm>(),
        entry::<incus::vm::ChangeVm>(),
        entry::<guest::ReadCloudInit>(),
        entry::<guest::ReadFingerprint>(),
        entry::<incus::image::ReadInstanceImages>(),
        entry::<package_cache::HostCache>(),
        entry::<agent::Install>(),
        entry::<agent_footprint::ConfigureAgent>(),
        entry::<agent_footprint::RemoveAgent>(),
        entry::<storage_root::PrepareStorageRoot>(),
        entry::<websocket_runtime::ConvergeRuntime>(),
        entry::<websocket_runtime::RemoveRuntime>(),
        entry::<websocket_runtime::RuntimeActive>(),
        entry::<ci_runner::CiRunnerHost>(),
        entry::<cli::InstallCli>(),
        entry::<os_release::ReadOsRelease>(),
        entry::<node_bootstrap::Bootstrap>(),
        entry::<macos_enrollment::Inspect>(),
        entry::<ssh_host_key::Scan>(),
        entry::<role_prerequisites::InstallPrerequisites>(),
        entry::<instances::InspectLaravelRoutes>(),
        entry::<production::ActivateRelease>(),
        entry::<production::InspectRepositoryBinding>(),
        entry::<production::RebindRepository>(),
        entry::<application_log::ReadApplicationLog>(),
        entry::<environment::InspectEnvironmentSync>(),
        entry::<environment::WriteEnvironment>(),
        entry::<laravel::InspectProfile>(),
        entry::<laravel::WriteUrl>(),
        entry::<caddy_access::GrantDevelopmentCaddyAccess>(),
        entry::<vite::SelectVitePort>(),
        entry::<vite::CheckViteListener>(),
        entry::<vite::SetViteTraffic>(),
        entry::<firewall::native::rules::Rules>(),
        entry::<firewall::native::rules::Router>(),
        entry::<firewall::native::route::RouteLan>(),
        entry::<tls_check::VerifyCertificate>(),
        entry::<http_check::VerifyHttp>(),
        entry::<topology_evidence::ReadTopologyEvidence>(),
        entry::<task_git::ReadRefs>(),
        entry::<task_git_import::ImportPack>(),
        entry::<caddy_certificate::InstallCertificate>(),
        entry::<caddy::Reload>(),
        entry::<caddy_observation::CheckAddresses>(),
        entry::<caddy_observation::ReadLive>(),
        entry::<caddy_certificate::RemoveCertificate>(),
        entry::<caddy_package::InstallPackage>(),
        entry::<metrics_publication::Publication>(),
        entry::<project_source::UpdateProjectSource>(),
        entry::<hibernation::PrepareDirectories>(),
        entry::<hibernation::MarkHibernation>(),
        entry::<hibernation::ReadHibernationTimes>(),
        entry::<hibernation::InspectCheckout>(),
        entry::<hibernation::PruneCheckout>(),
        entry::<hibernation::RestoreDependencies>(),
        entry::<schedules::InstallSchedule>(),
        entry::<schedules::RunSchedule>(),
        entry::<crate::command::effects::ReadReceipt>(),
        entry::<crate::command::effects::ReserveEffect>(),
        entry::<crate::command::effects::CompleteEffect>(),
        entry::<schedules::ReadScheduleLogs>(),
        entry::<schedules::RemoveSchedule>(),
        entry::<accounts::LookupAccount>(),
        entry::<processes::CheckRelease>(),
        entry::<processes::ReadStatus>(),
        entry::<processes::ControlProcess>(),
        entry::<processes::RemoveProcess>(),
        entry::<processes::StageUnit>(),
        entry::<processes::ActivateUnit>(),
        entry::<processes::ReadLogs>(),
        entry::<dev_certificates::RequestLeaf>(),
        entry::<dev_certificates::PublishLeaf>(),
        entry::<dev_certificates::InspectLeaf>(),
        entry::<dev_certificates::RemoveLeaf>(),
        entry::<dev_deploy::RunLifecycleStep>(),
        entry::<production_home::WriteDirectoryUrl>(),
        entry::<php_shared::Inspect>(),
        entry::<php_shared::Converge>(),
        entry::<php_runtime::ConvergeRuntime>(),
        entry::<php_runtime::RemoveRuntime>(),
        entry::<php_runtime::RefreshCache>(),
        entry::<wireguard::ProjectHub>(),
        entry::<wireguard_peer::PrepareKey>(),
        entry::<wireguard_peer::Publish>(),
        entry::<wireguard_peer::Commit>(),
        entry::<wireguard_peer::Rollback>(),
        entry::<peer_dns::RepairPeerDns>(),
    ]
}

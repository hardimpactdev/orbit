//! The named commands this binary implements, by area.

pub mod accounts;
pub mod agent;
pub mod agent_footprint;
pub mod application_log;
pub mod caddy;
pub mod caddy_access;
pub mod ci_runner;
pub mod cli;
pub mod clone_candidate;
pub mod database_server;
pub mod dependencies;
pub mod deploy_step;
pub mod dev_certificates;
pub mod dev_deploy;
pub mod dev_source;
pub mod environment;
pub mod firewall;
pub mod health;
pub mod hibernation;
pub mod horizon_queue;
pub mod instances;
pub mod laravel;
pub mod metrics_certificate;
pub mod os_release;
pub mod peer_dns;
pub mod php_runtime;
pub mod processes;
pub mod production;
pub mod production_home;
pub mod project_source;
pub mod registration;
pub mod role_prerequisites;
pub mod schedules;
pub mod source;
pub mod sqlite_copy;
pub mod sqlite_seed;
pub mod storage_root;
pub mod tls_check;
pub mod transfer_source;
pub mod valkey_acl;
pub mod vite;
pub mod websocket_runtime;
pub mod wireguard;

use crate::command::registry::{entry, Entry};
use crate::command::{Platform, Privilege};

/// A privilege on Linux, and no support elsewhere: the rule of every Node-only command.
pub fn linux_only(platform: Platform, privilege: Privilege) -> Option<Privilege> {
    (platform == Platform::Linux).then_some(privilege)
}

/// Declares a command's error enum: each variant has its code, its message, and the fields that become
/// its `details`.
#[macro_export]
macro_rules! command_errors {
    ($vis:vis enum $name:ident {
        $( $variant:ident { $( $field:ident : $type:ty ),* $(,)? } => $code:literal, $message:literal; )+
    }) => {
        #[derive(Debug, PartialEq)]
        $vis enum $name { $( $variant { $( $field: $type ),* } ),+ }
        impl $crate::command::CommandError for $name {
            const CODES: &'static [&'static str] = &[ $( $code ),+ ];
            fn code(&self) -> &'static str {
                match self { $( Self::$variant { .. } => $code ),+ }
            }
            fn message(&self) -> String {
                match self { $( Self::$variant { .. } => $message.to_string() ),+ }
            }
            #[allow(unused_variables)]
            fn details(&self) -> serde_json::Value {
                match self { $( Self::$variant { $( $field ),* } => serde_json::json!({ $( stringify!($field): $field ),* }) ),+ }
            }
        }
    };
}

pub fn all() -> Vec<Entry> {
    vec![
        entry::<agent::Status>(),
        entry::<agent::Install>(),
        entry::<agent_footprint::ConfigureAgent>(),
        entry::<agent_footprint::RemoveAgent>(),
        entry::<storage_root::PrepareStorageRoot>(),
        entry::<websocket_runtime::ConvergeRuntime>(),
        entry::<websocket_runtime::RemoveRuntime>(),
        entry::<websocket_runtime::RuntimeActive>(),
        entry::<ci_runner::CiRunnerHost>(),
        entry::<horizon_queue::ReadQueue>(),
        entry::<valkey_acl::WriteValkeyAcl>(),
        entry::<cli::InspectCli>(),
        entry::<cli::InstallCli>(),
        entry::<os_release::ReadOsRelease>(),
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
        entry::<environment::WriteEnvironment>(),
        entry::<laravel::InspectProfile>(),
        entry::<laravel::WriteUrl>(),
        entry::<caddy_access::GrantDevelopmentCaddyAccess>(),
        entry::<clone_candidate::InspectCloneCandidate>(),
        entry::<sqlite_copy::CopySqlite>(),
        entry::<sqlite_seed::PrepareSqliteSeed>(),
        entry::<sqlite_seed::InstallSqliteSeed>(),
        entry::<transfer_source::TransferSource>(),
        entry::<vite::SelectVitePort>(),
        entry::<vite::CheckViteListener>(),
        entry::<vite::SetViteTraffic>(),
        entry::<firewall::FirewallStatus>(),
        entry::<firewall::ChangeFirewall>(),
        entry::<tls_check::VerifyCertificate>(),
        entry::<caddy::InstallCertificate>(),
        entry::<caddy::RemoveCertificate>(),
        entry::<caddy::InstallPackage>(),
        entry::<health::AwaitHealthy>(),
        entry::<metrics_certificate::MetricsCertificate>(),
        entry::<project_source::UpdateProjectSource>(),
        entry::<database_server::DatabaseServerAdmin>(),
        entry::<hibernation::MarkHibernation>(),
        entry::<hibernation::ReadHibernationTimes>(),
        entry::<hibernation::InspectCheckout>(),
        entry::<hibernation::PruneCheckout>(),
        entry::<hibernation::RestoreDependencies>(),
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
        entry::<php_runtime::ConvergeRuntime>(),
        entry::<php_runtime::RemoveRuntime>(),
        entry::<php_runtime::InspectPool>(),
        entry::<php_runtime::RefreshCache>(),
        entry::<wireguard::ProjectHub>(),
        entry::<wireguard::InspectHub>(),
        entry::<peer_dns::InspectPeerDns>(),
        entry::<peer_dns::QueryDns>(),
        entry::<peer_dns::RepairPeerDns>(),
    ]
}

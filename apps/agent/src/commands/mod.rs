//! The named commands this binary implements, by area.

pub mod agent;
pub mod application_log;
pub mod caddy_access;
pub mod clone_candidate;
pub mod dependencies;
pub mod deploy_step;
pub mod dev_deploy;
pub mod dev_source;
pub mod environment;
pub mod instances;
pub mod laravel;
pub mod php_runtime;
pub mod production;
pub mod production_home;
pub mod registration;
pub mod source;

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
        entry::<dependencies::ReadFiles>(),
        entry::<dependencies::ReadReleaseFiles>(),
        entry::<dependencies::ProbeUpdate>(),
        entry::<dependencies::UpdateDependencies>(),
        entry::<dev_deploy::ResolveTarget>(),
        entry::<dev_deploy::CheckoutDefault>(),
        entry::<dev_deploy::RunStep>(),
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
    ]
}

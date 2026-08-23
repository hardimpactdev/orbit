use crate::pending_update::{PendingDesktopUpdate, INSTALL_MODE_AUTOMATIC};

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum UpdateState {
    Idle,
    Checking,
    Downloading { version: String },
    Verified { version: String },
    RestartReady { version: String },
    Installing { version: String },
    Relaunching { version: String },
    VerifiedCurrent { version: String },
    Failed { message: String, retryable: bool },
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum UpdateEvent {
    StartCheck,
    CheckFailed,
    StartDownload,
    PartialDownload,
    IntegrityFailed,
    Verified,
    BeginInstall,
    InstallFailedBeforeReplacement,
    InterruptedInstall,
    Installed,
    CurrentVerified,
    Retry,
}

impl UpdateState {
    pub fn label(&self) -> String {
        match self {
            Self::Idle | Self::VerifiedCurrent { .. } => String::new(),
            Self::Checking => "Checking for Updates".to_string(),
            Self::Downloading { version } => format!("Downloading Orbit {version}"),
            Self::Verified { version } | Self::RestartReady { version } => {
                format!("Restart to Update Orbit {version}")
            }
            Self::Installing { version } | Self::Relaunching { version } => {
                format!("Installing Orbit {version}")
            }
            Self::Failed { .. } => "Update Failed - Retry".to_string(),
        }
    }

    pub fn shows_tray_dot(&self) -> bool {
        matches!(self, Self::RestartReady { .. } | Self::Verified { .. })
    }

    pub fn consumes_handoff_automatically(handoff: &PendingDesktopUpdate) -> bool {
        handoff.install_mode == INSTALL_MODE_AUTOMATIC
    }
}

pub fn transition(state: UpdateState, event: UpdateEvent, version: &str) -> UpdateState {
    match (state, event) {
        (UpdateState::Idle, UpdateEvent::StartCheck)
        | (
            UpdateState::Failed {
                retryable: true, ..
            },
            UpdateEvent::Retry,
        )
        | (
            UpdateState::Failed {
                retryable: true, ..
            },
            UpdateEvent::StartCheck,
        ) => UpdateState::Checking,
        (UpdateState::Checking, UpdateEvent::CheckFailed) => UpdateState::Failed {
            message: "Update check failed.".to_string(),
            retryable: true,
        },
        (UpdateState::Checking, UpdateEvent::StartDownload) => UpdateState::Downloading {
            version: version.to_string(),
        },
        (UpdateState::Downloading { .. }, UpdateEvent::PartialDownload) => UpdateState::Failed {
            message: "Partial download removed.".to_string(),
            retryable: true,
        },
        (UpdateState::Downloading { .. }, UpdateEvent::IntegrityFailed)
        | (UpdateState::Verified { .. }, UpdateEvent::IntegrityFailed) => UpdateState::Failed {
            message: "Update signature or hash failed.".to_string(),
            retryable: false,
        },
        (UpdateState::Downloading { .. }, UpdateEvent::Verified) => UpdateState::Verified {
            version: version.to_string(),
        },
        (UpdateState::Verified { version }, UpdateEvent::BeginInstall) => {
            UpdateState::RestartReady { version }
        }
        (UpdateState::RestartReady { version }, UpdateEvent::BeginInstall) => {
            UpdateState::Installing { version }
        }
        (UpdateState::Installing { version }, UpdateEvent::Installed) => {
            UpdateState::Relaunching { version }
        }
        (UpdateState::Installing { version }, UpdateEvent::InstallFailedBeforeReplacement) => {
            UpdateState::RestartReady { version }
        }
        (UpdateState::Installing { version }, UpdateEvent::InterruptedInstall)
        | (UpdateState::Relaunching { version }, UpdateEvent::InterruptedInstall) => {
            UpdateState::Verified { version }
        }
        (UpdateState::Relaunching { version }, UpdateEvent::CurrentVerified) => {
            UpdateState::VerifiedCurrent { version }
        }
        (state, _) => state,
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum InstallAttemptResult {
    Succeeded,
    FailedBeforeReplacement,
    Interrupted,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct HandoffDisposition {
    pub remove_handoff: bool,
    pub restart_app: bool,
    pub next_state: UpdateState,
}

pub fn disposition_after_install(
    state: UpdateState,
    result: InstallAttemptResult,
    version: &str,
) -> HandoffDisposition {
    match result {
        InstallAttemptResult::Succeeded => HandoffDisposition {
            remove_handoff: true,
            restart_app: true,
            next_state: transition(state, UpdateEvent::Installed, version),
        },
        InstallAttemptResult::FailedBeforeReplacement => HandoffDisposition {
            remove_handoff: false,
            restart_app: false,
            next_state: transition(state, UpdateEvent::InstallFailedBeforeReplacement, version),
        },
        InstallAttemptResult::Interrupted => HandoffDisposition {
            remove_handoff: false,
            restart_app: false,
            next_state: transition(state, UpdateEvent::InterruptedInstall, version),
        },
    }
}

pub fn ready_state_for_handoff(update: &PendingDesktopUpdate) -> UpdateState {
    if UpdateState::consumes_handoff_automatically(update) {
        UpdateState::Installing {
            version: update.version.clone(),
        }
    } else {
        UpdateState::RestartReady {
            version: update.version.clone(),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::pending_update::{ArtifactIdentity, DesktopIdentity};

    fn handoff(install_mode: &str) -> PendingDesktopUpdate {
        PendingDesktopUpdate {
            schema_version: 1,
            operation_id: "op-1".to_string(),
            version: "1.2.3".to_string(),
            build_id: "build-1".to_string(),
            install_mode: install_mode.to_string(),
            desktop: DesktopIdentity {
                sha256: "c".repeat(64),
                signature: "sig".to_string(),
                staged_path: "/Users/nckrtl/.local/share/orbit/updates/desktop.tar.gz".to_string(),
                version: "1.2.3".to_string(),
                platform: "darwin".to_string(),
                architecture: "arm64".to_string(),
            },
            agent: ArtifactIdentity {
                sha256: "e".repeat(64),
                bin_path: None,
            },
            cli: ArtifactIdentity {
                sha256: "b".repeat(64),
                bin_path: None,
            },
        }
    }

    #[test]
    fn walks_the_specified_success_and_failure_transitions() {
        let mut state = UpdateState::Idle;
        state = transition(state, UpdateEvent::StartCheck, "1.2.3");
        assert_eq!(state, UpdateState::Checking);
        state = transition(state, UpdateEvent::StartDownload, "1.2.3");
        state = transition(state, UpdateEvent::Verified, "1.2.3");
        assert_eq!(state.label(), "Restart to Update Orbit 1.2.3");
        assert!(state.shows_tray_dot());
        state = transition(state, UpdateEvent::BeginInstall, "1.2.3");
        assert!(matches!(state, UpdateState::RestartReady { .. }));

        let failed = transition(
            UpdateState::Downloading {
                version: "1.2.3".to_string(),
            },
            UpdateEvent::PartialDownload,
            "1.2.3",
        );
        assert_eq!(failed.label(), "Update Failed - Retry");
        assert!(matches!(
            failed,
            UpdateState::Failed {
                retryable: true,
                ..
            }
        ));

        let kept = transition(
            UpdateState::Installing {
                version: "1.2.3".to_string(),
            },
            UpdateEvent::InstallFailedBeforeReplacement,
            "1.2.3",
        );
        assert!(matches!(kept, UpdateState::RestartReady { .. }));
    }

    #[test]
    fn automatic_handoff_installs_after_the_agent_response() {
        assert!(matches!(
            ready_state_for_handoff(&handoff("automatic")),
            UpdateState::Installing { .. }
        ));
        assert!(matches!(
            ready_state_for_handoff(&handoff("restart-ready")),
            UpdateState::RestartReady { .. }
        ));
    }

    #[test]
    fn keeps_handoff_and_returns_restart_ready_when_apply_fails_before_replacement() {
        let installing = UpdateState::Installing {
            version: "1.2.3".to_string(),
        };
        let disposition = disposition_after_install(
            installing,
            InstallAttemptResult::FailedBeforeReplacement,
            "1.2.3",
        );

        assert!(!disposition.remove_handoff);
        assert!(!disposition.restart_app);
        assert_eq!(
            disposition.next_state,
            UpdateState::RestartReady {
                version: "1.2.3".to_string()
            }
        );
        assert_eq!(
            disposition.next_state.label(),
            "Restart to Update Orbit 1.2.3"
        );
    }

    #[test]
    fn removes_handoff_only_after_complete_success() {
        let installing = UpdateState::Installing {
            version: "1.2.3".to_string(),
        };
        let success =
            disposition_after_install(installing.clone(), InstallAttemptResult::Succeeded, "1.2.3");
        assert!(success.remove_handoff);
        assert!(success.restart_app);
        assert!(matches!(
            success.next_state,
            UpdateState::Relaunching { .. }
        ));

        let interrupted =
            disposition_after_install(installing, InstallAttemptResult::Interrupted, "1.2.3");
        assert!(!interrupted.remove_handoff);
        assert!(!interrupted.restart_app);
        assert!(matches!(
            interrupted.next_state,
            UpdateState::Verified { .. }
        ));
    }
}

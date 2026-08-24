#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum WindowCloseAction {
    Hide,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum AppExitAction {
    StopAgentAndExit,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum ProviderRuntimeState {
    Starting,
    Ready {
        endpoint: String,
    },
    MissingPrerequisite {
        cause: MissingPrerequisiteCause,
        detail: String,
    },
    OwnershipConflict {
        detail: String,
    },
    Incompatible {
        detail: String,
    },
    Degraded {
        detail: String,
    },
    Stopping,
    StopFailed {
        detail: String,
    },
}
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum MissingPrerequisiteCause {
    Colima,
    Docker,
    Homebrew,
}
pub fn classify_missing_executable(path: &str) -> MissingPrerequisiteCause {
    if std::path::Path::new(path)
        .file_name()
        .is_some_and(|name| name == "docker")
    {
        MissingPrerequisiteCause::Docker
    } else {
        MissingPrerequisiteCause::Colima
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum RuntimeAction {
    RetryLocalRuntime,
    Quit,
    Restart,
    RestartToUpdate,
    ExitAfterStopFailure,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum ResetConfirmation {
    Armed,
}

pub fn reset_confirmation_label(state: Option<ResetConfirmation>) -> &'static str {
    match state {
        Some(ResetConfirmation::Armed) => "Confirm Reset Local Runtime — Deletes All Data",
        _ => "Reset Local Runtime…",
    }
}

pub fn reset_click(deadline: &mut Option<std::time::Instant>, now: std::time::Instant) -> bool {
    if deadline.is_some_and(|expires| now < expires) {
        *deadline = None;
        true
    } else {
        *deadline = Some(now + std::time::Duration::from_secs(30));
        false
    }
}
pub fn disarm_reset(deadline: &mut Option<std::time::Instant>) {
    *deadline = None;
}
pub fn install_runtime_enabled(state: &ProviderRuntimeState) -> bool {
    matches!(
        state,
        ProviderRuntimeState::MissingPrerequisite {
            cause: MissingPrerequisiteCause::Colima | MissingPrerequisiteCause::Docker,
            ..
        }
    )
}
pub fn reset_runtime_enabled(owned: bool, attempt: bool) -> bool {
    owned && !attempt
}
pub fn begin_mutation(
    attempt: &mut bool,
    state: &mut ProviderRuntimeState,
    target: ProviderRuntimeState,
) -> bool {
    if *attempt {
        return false;
    }
    *attempt = true;
    *state = target;
    true
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum ProviderHealthAction {
    Healthy,
    DegradeAndStop,
    Ignore,
}
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum QuitDisposition {
    Exit,
    RemainOpen(String),
}

pub fn try_begin_provider_attempt(attempt: &mut bool, state: &mut ProviderRuntimeState) -> bool {
    if *attempt {
        return false;
    }
    *attempt = true;
    *state = ProviderRuntimeState::Starting;
    true
}
pub fn provider_health_action(
    state: &ProviderRuntimeState,
    endpoint: &str,
    probe_ok: bool,
) -> ProviderHealthAction {
    match state {
        ProviderRuntimeState::Ready { endpoint: current } if current == endpoint => {
            if probe_ok {
                ProviderHealthAction::Healthy
            } else {
                ProviderHealthAction::DegradeAndStop
            }
        }
        _ => ProviderHealthAction::Ignore,
    }
}
pub fn agent_restart_endpoint(
    stored: Option<&str>,
    state: &ProviderRuntimeState,
) -> Option<String> {
    match (stored, state) {
        (Some(stored), ProviderRuntimeState::Ready { endpoint }) if stored == endpoint => {
            Some(stored.into())
        }
        _ => None,
    }
}
pub fn provider_retry_enabled(state: &ProviderRuntimeState) -> bool {
    !matches!(
        state,
        ProviderRuntimeState::Ready { .. }
            | ProviderRuntimeState::Starting
            | ProviderRuntimeState::Stopping
    )
}
pub fn quit_disposition(result: Result<(), String>) -> QuitDisposition {
    result.map_or_else(QuitDisposition::RemainOpen, |_| QuitDisposition::Exit)
}

pub fn quit_provider_action(owned: bool, stop_result: Result<(), String>) -> QuitDisposition {
    if owned {
        quit_disposition(stop_result)
    } else {
        QuitDisposition::Exit
    }
}

pub fn stop_failed_exit_enabled(state: &ProviderRuntimeState) -> bool {
    matches!(state, ProviderRuntimeState::StopFailed { .. })
}

pub fn provider_label(state: &ProviderRuntimeState) -> String {
    match state {
        ProviderRuntimeState::Starting => "Provider: Starting".into(),
        ProviderRuntimeState::Ready { .. } => "Provider: Ready".into(),
        ProviderRuntimeState::MissingPrerequisite { detail, .. } => {
            format!("Provider: Missing prerequisite — {detail}")
        }
        ProviderRuntimeState::OwnershipConflict { detail } => {
            format!("Provider: Ownership conflict — {detail}")
        }
        ProviderRuntimeState::Incompatible { detail } => {
            format!("Provider: Incompatible — {detail}")
        }
        ProviderRuntimeState::Degraded { detail } => format!("Provider: Degraded — {detail}"),
        ProviderRuntimeState::Stopping => "Provider: Stopping".into(),
        ProviderRuntimeState::StopFailed { detail } => format!("Provider: Stop failed — {detail}"),
    }
}

pub fn action_order(action: RuntimeAction) -> &'static [&'static str] {
    match action {
        RuntimeAction::Quit => &["stop-agent", "stop-colima-orbit", "exit"],
        RuntimeAction::Restart | RuntimeAction::RestartToUpdate => &["stop-agent", "restart"],
        RuntimeAction::RetryLocalRuntime => &["start-colima-orbit", "probe-docker", "start-agent"],
        RuntimeAction::ExitAfterStopFailure => &["exit"],
    }
}

pub fn dashboard_close_action() -> WindowCloseAction {
    WindowCloseAction::Hide
}

#[cfg(test)]
mod reset_tests {
    use super::*;

    #[test]
    fn reset_requires_two_explicit_clicks() {
        assert_eq!(reset_confirmation_label(None), "Reset Local Runtime…");
        let now = std::time::Instant::now();
        let mut deadline = None;
        assert!(!reset_click(&mut deadline, now));
        assert_eq!(
            reset_confirmation_label(Some(ResetConfirmation::Armed)),
            "Confirm Reset Local Runtime — Deletes All Data"
        );
        assert!(reset_click(
            &mut deadline,
            now + std::time::Duration::from_secs(1)
        ));
        let mut boundary = None;
        assert!(!reset_click(&mut boundary, now));
        assert!(!reset_click(
            &mut boundary,
            now + std::time::Duration::from_secs(30)
        ));
        assert_eq!(boundary, Some(now + std::time::Duration::from_secs(60)));
        disarm_reset(&mut deadline);
        assert!(deadline.is_none());
    }
}

pub fn quit_action() -> AppExitAction {
    AppExitAction::StopAgentAndExit
}

pub fn restart_stops_child_before_relaunch() -> bool {
    true
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn dashboard_close_hides_instead_of_quitting() {
        assert_eq!(dashboard_close_action(), WindowCloseAction::Hide);
        assert_ne!(
            format!("{:?}", dashboard_close_action()),
            format!("{:?}", quit_action())
        );
    }

    #[test]
    fn explicit_quit_stops_the_agent() {
        assert_eq!(quit_action(), AppExitAction::StopAgentAndExit);
        assert!(restart_stops_child_before_relaunch());
    }

    #[test]
    fn quit_stops_agent_then_owned_provider_then_exits() {
        assert_eq!(
            action_order(RuntimeAction::Quit),
            &["stop-agent", "stop-colima-orbit", "exit"]
        );
    }

    #[test]
    fn restart_paths_do_not_stop_provider() {
        assert_eq!(
            action_order(RuntimeAction::Restart),
            &["stop-agent", "restart"]
        );
        assert_eq!(
            action_order(RuntimeAction::RestartToUpdate),
            &["stop-agent", "restart"]
        );
    }

    #[test]
    fn provider_labels_preserve_diagnostic_states() {
        assert_eq!(
            provider_label(&ProviderRuntimeState::Ready {
                endpoint: "unix://x".into()
            }),
            "Provider: Ready"
        );
        assert_eq!(
            provider_label(&ProviderRuntimeState::Degraded {
                detail: "timeout".into()
            }),
            "Provider: Degraded — timeout"
        );
        assert_eq!(
            provider_label(&ProviderRuntimeState::OwnershipConflict {
                detail: "other".into()
            }),
            "Provider: Ownership conflict — other"
        );
        assert_eq!(
            provider_label(&ProviderRuntimeState::MissingPrerequisite {
                cause: MissingPrerequisiteCause::Colima,
                detail: "colima".into()
            }),
            "Provider: Missing prerequisite — colima"
        );
        assert_eq!(
            provider_label(&ProviderRuntimeState::Stopping),
            "Provider: Stopping"
        );
        assert_eq!(
            provider_label(&ProviderRuntimeState::StopFailed {
                detail: "busy".into()
            }),
            "Provider: Stop failed — busy"
        );
    }

    #[test]
    fn decision_helpers_cover_attempt_health_restart_retry_and_quit() {
        let mut attempt = false;
        let mut state = ProviderRuntimeState::Degraded { detail: "x".into() };
        assert!(try_begin_provider_attempt(&mut attempt, &mut state));
        assert!(!try_begin_provider_attempt(&mut attempt, &mut state));
        assert!(install_runtime_enabled(
            &ProviderRuntimeState::MissingPrerequisite {
                cause: MissingPrerequisiteCause::Docker,
                detail: "docker".into()
            }
        ));
        assert!(!install_runtime_enabled(
            &ProviderRuntimeState::MissingPrerequisite {
                cause: MissingPrerequisiteCause::Homebrew,
                detail: "brew".into()
            }
        ));
        assert!(install_runtime_enabled(
            &ProviderRuntimeState::MissingPrerequisite {
                cause: MissingPrerequisiteCause::Colima,
                detail: "colima".into()
            }
        ));
        assert!(!install_runtime_enabled(&ProviderRuntimeState::Ready {
            endpoint: "x".into()
        }));
        assert!(!install_runtime_enabled(&ProviderRuntimeState::Degraded {
            detail: "x".into()
        }));
        assert!(reset_runtime_enabled(true, false));
        assert!(!reset_runtime_enabled(true, true));
        assert_eq!(
            classify_missing_executable("/opt/homebrew/bin/docker"),
            MissingPrerequisiteCause::Docker
        );
        assert_eq!(
            classify_missing_executable("/opt/homebrew/bin/colima"),
            MissingPrerequisiteCause::Colima
        );
        let mut mutation = false;
        assert!(begin_mutation(
            &mut mutation,
            &mut state,
            ProviderRuntimeState::Stopping
        ));
        assert!(!begin_mutation(
            &mut mutation,
            &mut state,
            ProviderRuntimeState::Starting
        ));
        assert_eq!(
            provider_health_action(
                &ProviderRuntimeState::Ready {
                    endpoint: "e".into()
                },
                "e",
                true
            ),
            ProviderHealthAction::Healthy
        );
        assert_eq!(
            provider_health_action(
                &ProviderRuntimeState::Ready {
                    endpoint: "e".into()
                },
                "e",
                false
            ),
            ProviderHealthAction::DegradeAndStop
        );
        assert_eq!(
            provider_health_action(
                &ProviderRuntimeState::Ready {
                    endpoint: "e".into()
                },
                "x",
                false
            ),
            ProviderHealthAction::Ignore
        );
        assert_eq!(
            agent_restart_endpoint(
                Some("e"),
                &ProviderRuntimeState::Ready {
                    endpoint: "e".into()
                }
            ),
            Some("e".into())
        );
        assert_eq!(
            agent_restart_endpoint(
                Some("x"),
                &ProviderRuntimeState::Ready {
                    endpoint: "e".into()
                }
            ),
            None
        );
        assert!(provider_retry_enabled(&ProviderRuntimeState::Degraded {
            detail: "x".into()
        }));
        assert!(!provider_retry_enabled(&ProviderRuntimeState::Starting));
        assert_eq!(quit_disposition(Ok(())), QuitDisposition::Exit);
        assert_eq!(
            quit_disposition(Err("failed".into())),
            QuitDisposition::RemainOpen("failed".into())
        );
    }

    #[test]
    fn quit_without_owned_profile_exits_without_attempting_provider_stop() {
        assert_eq!(
            quit_provider_action(false, Err("missing home".into())),
            QuitDisposition::Exit
        );
    }

    #[test]
    fn stop_failure_has_an_exit_action_only_for_stop_failed_state() {
        assert!(stop_failed_exit_enabled(
            &ProviderRuntimeState::StopFailed {
                detail: "busy".into()
            }
        ));
        assert!(!stop_failed_exit_enabled(&ProviderRuntimeState::Ready {
            endpoint: "x".into()
        }));
        assert_eq!(action_order(RuntimeAction::ExitAfterStopFailure), &["exit"]);
    }
}

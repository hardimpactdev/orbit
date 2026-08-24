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
    Ready { endpoint: String },
    MissingPrerequisite { detail: String },
    OwnershipConflict { detail: String },
    Incompatible { detail: String },
    Degraded { detail: String },
    Stopping,
    StopFailed { detail: String },
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum RuntimeAction {
    RetryLocalRuntime,
    Quit,
    Restart,
    RestartToUpdate,
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

pub fn provider_label(state: &ProviderRuntimeState) -> String {
    match state {
        ProviderRuntimeState::Starting => "Provider: Starting".into(),
        ProviderRuntimeState::Ready { .. } => "Provider: Ready".into(),
        ProviderRuntimeState::MissingPrerequisite { detail } => {
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
    }
}

pub fn dashboard_close_action() -> WindowCloseAction {
    WindowCloseAction::Hide
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
}

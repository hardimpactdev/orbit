use crate::supervisor::AgentRunState;
use crate::update_machine::UpdateState;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum LaunchAtLoginState {
    Enabled,
    Disabled,
    Error,
}

pub fn launch_at_login_label(state: LaunchAtLoginState) -> &'static str {
    match state {
        LaunchAtLoginState::Enabled | LaunchAtLoginState::Disabled => "Launch at Login",
        LaunchAtLoginState::Error => "Launch at Login (unavailable)",
    }
}

pub fn launch_at_login_checked(state: LaunchAtLoginState) -> bool {
    matches!(state, LaunchAtLoginState::Enabled)
}

pub fn restart_orbit_label() -> &'static str {
    "Restart Orbit"
}

pub fn quit_orbit_label() -> &'static str {
    "Quit Orbit"
}

pub fn compact_menu_rows(
    agent: AgentRunState,
    launch_at_login: LaunchAtLoginState,
    update: &UpdateState,
) -> Vec<String> {
    let mut rows = vec![crate::supervisor::agent_status_label(agent).to_string()];
    rows.push(launch_at_login_label(launch_at_login).to_string());

    let update_label = update.label();

    if !update_label.is_empty() {
        rows.push(update_label);
    }

    rows.push(restart_orbit_label().to_string());
    rows.push(quit_orbit_label().to_string());
    rows
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn compact_menu_includes_required_states() {
        let rows = compact_menu_rows(
            AgentRunState::Running,
            LaunchAtLoginState::Enabled,
            &UpdateState::RestartReady {
                version: "1.2.3".to_string(),
            },
        );

        assert!(rows.contains(&"Agent: Running".to_string()));
        assert!(rows.contains(&"Launch at Login".to_string()));
        assert!(rows.contains(&"Restart to Update Orbit 1.2.3".to_string()));
        assert!(rows.contains(&"Restart Orbit".to_string()));
        assert!(rows.contains(&"Quit Orbit".to_string()));
        assert!(launch_at_login_checked(LaunchAtLoginState::Enabled));
        assert!(!launch_at_login_checked(LaunchAtLoginState::Disabled));
        assert_eq!(
            launch_at_login_label(LaunchAtLoginState::Error),
            "Launch at Login (unavailable)"
        );
    }
}

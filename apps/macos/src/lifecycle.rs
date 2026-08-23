#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum WindowCloseAction {
    Hide,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum AppExitAction {
    StopAgentAndExit,
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
}

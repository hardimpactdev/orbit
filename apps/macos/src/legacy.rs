use crate::paths::{legacy_launch_agent_plist, owner_agent_bin};
use std::path::{Path, PathBuf};

pub const LEGACY_LAUNCHD_LABEL: &str = "dev.orbit.agent";

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct LegacyLaunchdState {
    pub label: String,
    pub plist_path: PathBuf,
    pub program: Option<PathBuf>,
    pub loaded: bool,
    pub pid: Option<u32>,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ListenerOwner {
    pub pid: u32,
    pub executable: PathBuf,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum LegacyDecision {
    Absent,
    Migrate(LegacyLaunchdState),
    Conflict { reason: String },
}

pub fn inspect_legacy_agent(
    home: &Path,
    launchd: Option<LegacyLaunchdState>,
    listener: Option<ListenerOwner>,
) -> LegacyDecision {
    let canonical_plist = legacy_launch_agent_plist(home);
    let canonical_binary = owner_agent_bin(home);

    match (launchd, listener) {
        (None, None) => LegacyDecision::Absent,
        (Some(state), listener) => {
            if state.label != LEGACY_LAUNCHD_LABEL || state.plist_path != canonical_plist {
                return LegacyDecision::Conflict {
                    reason: "A non-canonical LaunchAgent owns an Agent-like service.".to_string(),
                };
            }

            if state.program.as_ref() != Some(&canonical_binary) {
                return LegacyDecision::Conflict {
                    reason: "LaunchAgent program is not the owner-local orbit-agent binary."
                        .to_string(),
                };
            }

            if let Some(owner) = listener {
                if state.pid != Some(owner.pid) || owner.executable != canonical_binary {
                    return LegacyDecision::Conflict {
                        reason: "The Agent listener is not the canonical LaunchAgent process."
                            .to_string(),
                    };
                }
            }

            LegacyDecision::Migrate(state)
        }
        (None, Some(_)) => LegacyDecision::Conflict {
            reason: "An Agent listener is present without canonical LaunchAgent ownership."
                .to_string(),
        },
    }
}

pub fn parse_launchctl_pid(output: &str) -> Option<u32> {
    for line in output.lines() {
        let trimmed = line.trim();
        if let Some(value) = trimmed.strip_prefix("pid = ") {
            return value.trim().parse().ok();
        }
        if let Some(value) = trimmed.strip_prefix("PID = ") {
            return value.trim().parse().ok();
        }
    }

    None
}

pub fn parse_plist_program(plist: &str) -> Option<PathBuf> {
    let mut saw_program_arguments = false;

    for line in plist.lines() {
        let trimmed = line.trim();

        if trimmed.contains("ProgramArguments") {
            saw_program_arguments = true;
            continue;
        }

        if saw_program_arguments {
            if let Some(value) = trimmed.strip_prefix("<string>") {
                return value.strip_suffix("</string>").map(PathBuf::from);
            }
        }

        if trimmed.contains("<key>Program</key>") {
            saw_program_arguments = true;
        }
    }

    None
}

pub fn parse_lsof_listener_pid(output: &str) -> Option<u32> {
    output.lines().find_map(|line| {
        if line.contains("LISTEN") {
            line.split_whitespace().nth(1)?.parse().ok()
        } else {
            None
        }
    })
}

pub fn conflict_remediation(reason: &str) -> String {
    format!(
        "{reason} Stop the unknown listener, then reopen Orbit Desktop. Orbit does not kill unrelated processes."
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    fn home() -> PathBuf {
        PathBuf::from("/Users/nckrtl")
    }

    fn canonical_state(pid: u32) -> LegacyLaunchdState {
        LegacyLaunchdState {
            label: LEGACY_LAUNCHD_LABEL.to_string(),
            plist_path: legacy_launch_agent_plist(&home()),
            program: Some(owner_agent_bin(&home())),
            loaded: true,
            pid: Some(pid),
        }
    }

    #[test]
    fn migrates_only_canonical_launchd_state() {
        let decision = inspect_legacy_agent(
            &home(),
            Some(canonical_state(42)),
            Some(ListenerOwner {
                pid: 42,
                executable: owner_agent_bin(&home()),
            }),
        );

        assert!(matches!(decision, LegacyDecision::Migrate(_)));
    }

    #[test]
    fn fails_closed_when_the_listener_is_not_canonical() {
        let decision = inspect_legacy_agent(
            &home(),
            Some(canonical_state(42)),
            Some(ListenerOwner {
                pid: 99,
                executable: PathBuf::from("/opt/other/orbit-agent"),
            }),
        );

        match decision {
            LegacyDecision::Conflict { reason } => {
                assert!(conflict_remediation(&reason).contains("does not kill unrelated processes"));
            }
            other => panic!("expected conflict, got {other:?}"),
        }
    }

    #[test]
    fn absent_legacy_state_is_not_a_conflict() {
        assert_eq!(
            inspect_legacy_agent(&home(), None, None),
            LegacyDecision::Absent
        );
    }

    #[test]
    fn parses_canonical_launchctl_and_plist_identities() {
        assert_eq!(
            parse_launchctl_pid("state = running\n    pid = 4242\n"),
            Some(4242)
        );
        assert_eq!(
            parse_plist_program(
                r#"
                <key>Label</key>
                <string>dev.orbit.agent</string>
                <key>ProgramArguments</key>
                <array>
                    <string>/Users/nckrtl/.local/bin/orbit-agent</string>
                </array>
                "#,
            ),
            Some(PathBuf::from("/Users/nckrtl/.local/bin/orbit-agent"))
        );
        assert_eq!(
            parse_lsof_listener_pid("COMMAND   PID USER   FD   TYPE  DEVICE SIZE/OFF NODE NAME\norbit-agent 4242 nckrtl    4u  IPv4 0x1      0t0  TCP 127.0.0.1:9477 (LISTEN)\n"),
            Some(4242)
        );
    }
}

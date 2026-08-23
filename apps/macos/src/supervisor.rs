use crate::paths::owner_agent_bin;
use orbit_agent::DESKTOP_LIFETIME_ENV;
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::time::{Duration, Instant};

pub const DEFAULT_MAX_CHILD_CRASH_RESTARTS: u32 = 3;
pub const DEFAULT_CHILD_CRASH_WINDOW: Duration = Duration::from_secs(60);

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum AgentRunState {
    Starting,
    Running,
    Stopped,
    Conflict,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum CrashAction {
    Restart,
    StayStopped,
}

#[derive(Debug, Clone)]
pub struct AgentLaunchPlan {
    pub binary: PathBuf,
    pub lifetime_env: &'static str,
    pub lifetime_value: &'static str,
}

pub fn agent_binary_candidates(home: &Path, override_bin: Option<&Path>) -> Vec<PathBuf> {
    let mut candidates = Vec::new();

    if let Some(path) = override_bin {
        candidates.push(path.to_path_buf());
    }

    candidates.push(owner_agent_bin(home));

    candidates
}

pub fn resolve_agent_binary(home: &Path, override_bin: Option<&Path>) -> Option<PathBuf> {
    agent_binary_candidates(home, override_bin)
        .into_iter()
        .find(|path| path.is_file())
}

pub fn agent_launch_plan(binary: PathBuf) -> AgentLaunchPlan {
    AgentLaunchPlan {
        binary,
        lifetime_env: DESKTOP_LIFETIME_ENV,
        lifetime_value: "1",
    }
}

pub fn spawn_supervised_agent(plan: &AgentLaunchPlan) -> std::io::Result<std::process::Child> {
    Command::new(&plan.binary)
        .env(plan.lifetime_env, plan.lifetime_value)
        .stdin(Stdio::piped())
        .stdout(Stdio::null())
        .stderr(Stdio::inherit())
        .spawn()
}

pub fn stop_supervised_agent(
    child: &mut std::process::Child,
    wait: Duration,
) -> std::io::Result<()> {
    drop(child.stdin.take());

    let started = Instant::now();

    loop {
        match child.try_wait()? {
            Some(_) => return Ok(()),
            None if started.elapsed() >= wait => {
                child.kill()?;
                child.wait()?;
                return Ok(());
            }
            None => std::thread::sleep(Duration::from_millis(20)),
        }
    }
}

pub fn crash_action(
    history: &[Instant],
    now: Instant,
    max_restarts: u32,
    window: Duration,
) -> CrashAction {
    let recent = history
        .iter()
        .filter(|instant| now.saturating_duration_since(**instant) <= window)
        .count();

    if recent >= max_restarts as usize {
        CrashAction::StayStopped
    } else {
        CrashAction::Restart
    }
}

pub fn agent_status_label(state: AgentRunState) -> &'static str {
    match state {
        AgentRunState::Starting => "Agent: Starting",
        AgentRunState::Running => "Agent: Running",
        AgentRunState::Stopped => "Agent: Stopped",
        AgentRunState::Conflict => "Agent: Conflict",
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::fs;
    use std::os::unix::fs::PermissionsExt;
    use std::sync::atomic::{AtomicU64, Ordering};
    use std::time::{SystemTime, UNIX_EPOCH};

    static NEXT_ID: AtomicU64 = AtomicU64::new(0);

    fn temp_bin() -> PathBuf {
        let suffix = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .expect("clock")
            .as_nanos();
        let sequence = NEXT_ID.fetch_add(1, Ordering::Relaxed);
        std::env::temp_dir().join(format!(
            "orbit-desktop-agent-{}-{suffix}-{sequence}",
            std::process::id()
        ))
    }

    #[test]
    fn launch_plan_sets_the_desktop_lifetime_marker_and_piped_stdin() {
        let plan = agent_launch_plan(PathBuf::from("/Users/nckrtl/.local/bin/orbit-agent"));

        assert_eq!(plan.lifetime_env, DESKTOP_LIFETIME_ENV);
        assert_eq!(plan.lifetime_value, "1");
        assert!(!plan.binary.as_os_str().is_empty());
    }

    #[test]
    fn prefers_an_explicit_owner_local_binary_over_searching_process_names() {
        let home = PathBuf::from("/Users/nckrtl");
        let candidates = agent_binary_candidates(&home, None);

        assert_eq!(
            candidates,
            vec![PathBuf::from("/Users/nckrtl/.local/bin/orbit-agent")]
        );
        assert!(!candidates
            .iter()
            .any(|path| path.to_string_lossy().contains("pgrep")));
    }

    #[test]
    fn bounded_restarts_stop_after_repeated_crashes() {
        let now = Instant::now();
        let history = vec![
            now - Duration::from_secs(3),
            now - Duration::from_secs(2),
            now - Duration::from_secs(1),
        ];

        assert_eq!(
            crash_action(&history, now, 3, Duration::from_secs(60)),
            CrashAction::StayStopped
        );
        assert_eq!(
            crash_action(&history[..1], now, 3, Duration::from_secs(60)),
            CrashAction::Restart
        );
        assert_eq!(
            agent_status_label(AgentRunState::Conflict),
            "Agent: Conflict"
        );
    }

    #[test]
    fn closing_stdin_stops_a_lifetime_aware_child() {
        let script = temp_bin();
        fs::write(&script, "#!/bin/sh\nexec cat >/dev/null\n").expect("script");
        let mut permissions = fs::metadata(&script).expect("meta").permissions();
        permissions.set_mode(0o755);
        fs::set_permissions(&script, permissions).expect("chmod");

        let plan = agent_launch_plan(script.clone());
        let mut child = spawn_supervised_agent(&plan).expect("spawn");
        assert!(child.stdin.is_some());

        stop_supervised_agent(&mut child, Duration::from_secs(2)).expect("stop");
        let _ = fs::remove_file(script);
    }
}

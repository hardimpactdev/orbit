//! On Linux each mutation runs in its own transient systemd service. The service's control group holds
//! every child, `RuntimeMaxSec` bounds it, and `KillMode=control-group` stops every child, daemons and
//! `setsid` children included, when the command ends, fails, or loses its output pipe. The supervised
//! process checks its own executable again and takes the command's locks.

use super::{run::SUPERVISED_ENV, Outcome};
use serde_json::{json, Value};
use std::{
    io::{Read, Write},
    os::unix::fs::FileTypeExt,
    path::Path,
    process::{Command, Stdio},
    time::Duration,
};

pub fn unit_name(command: &str) -> String {
    let safe: String = command
        .chars()
        .map(|character| {
            if character.is_ascii_alphanumeric() {
                character
            } else {
                '-'
            }
        })
        .collect();
    format!("orbit-command-{safe}-{:016x}", rand::random::<u64>())
}

pub fn arguments(
    executable: &Path,
    command: &str,
    budget: Duration,
    unit: &str,
    user: Option<&str>,
    locks: &Path,
) -> Vec<String> {
    let mut arguments = vec![
        "--quiet".into(),
        "--wait".into(),
        "--pipe".into(),
        "--collect".into(),
        "--service-type=exec".into(),
        format!("--unit={unit}"),
        "--property=KillMode=control-group".into(),
        format!("--property=RuntimeMaxSec={}", budget.as_secs() + 1),
        format!("--setenv={SUPERVISED_ENV}=1"),
        format!("--setenv={}={}", super::lock::LOCK_DIR_ENV, locks.display()),
    ];
    // systemd sets HOME, USER, and the user's groups for a service that runs as a named user.
    if let Some(user) = user {
        arguments.push(format!("--uid={user}"));
    }
    arguments.extend([
        "--".into(),
        executable.display().to_string(),
        "command".into(),
        command.into(),
    ]);
    arguments
}

/// Runs the same envelope under a transient service and returns its one result.
pub fn launch(
    executable: &Path,
    command: &str,
    envelope: &[u8],
    budget: Duration,
    user: Option<&str>,
    locks: &Path,
) -> Outcome {
    if let Err(outcome) = system_bus_ready(Path::new("/run/dbus/system_bus_socket")) {
        return outcome;
    }
    let unit = unit_name(command);
    let child = Command::new("/usr/bin/systemd-run")
        .args(arguments(executable, command, budget, &unit, user, locks))
        .env_clear()
        .env("PATH", "/usr/sbin:/usr/bin:/sbin:/bin")
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::inherit())
        .spawn();
    let mut child = match child {
        Ok(child) => child,
        Err(error) => {
            return Outcome::refused(
                "agent.supervisor_failed",
                "The agent could not start the command's service.",
                json!({"unit": unit, "reason": error.to_string()}),
            )
        }
    };
    let written = child
        .stdin
        .take()
        .map(|mut stdin| stdin.write_all(envelope))
        .unwrap_or(Ok(()));
    let mut output = String::new();
    let read = child
        .stdout
        .take()
        .map(|mut stdout| stdout.read_to_string(&mut output));
    let status = child.wait();
    if let Some(outcome) = parse_result(&output) {
        return outcome;
    }
    Outcome::refused(
        "agent.supervisor_failed",
        "The command's service ended without a result.",
        json!({
            "unit": unit,
            "status": status.ok().and_then(|status| status.code()),
            "stdin": written.err().map(|error| error.to_string()),
            "stdout": read.and_then(Result::err).map(|error| error.to_string()),
        }),
    )
}

// Incus can execute in a booting guest before its system bus is available. This is a precondition,
// not a failed installation: no transient unit or installer has started yet.
fn system_bus_ready(path: &Path) -> Result<(), Outcome> {
    match std::fs::metadata(path) {
        Ok(metadata) if metadata.file_type().is_socket() => Ok(()),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Err(Outcome::refused(
            "agent.supervisor_not_ready",
            "The guest's system bus is not available yet.",
            json!({"path": path.display().to_string()}),
        )),
        _ => Err(Outcome::refused(
            "agent.supervisor_failed",
            "The system bus path is not an accessible socket.",
            json!({"path": path.display().to_string()}),
        )),
    }
}

/// The last line of the supervised output, read as a result.
pub fn parse_result(output: &str) -> Option<Outcome> {
    let line = output.lines().rev().find(|line| !line.trim().is_empty())?;
    let value: Value = serde_json::from_str(line).ok()?;
    match value.get("ok")? {
        Value::Bool(true) => Some(Outcome::Ok(value.get("data")?.clone())),
        Value::Bool(false) => {
            let error = value.get("error")?;
            Some(Outcome::Refused {
                code: error.get("code")?.as_str()?.to_string(),
                message: error.get("message")?.as_str()?.to_string(),
                details: error.get("details").cloned().unwrap_or_else(|| json!({})),
            })
        }
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_missing_boot_bus_is_pending_but_an_invalid_bus_is_not() {
        let path = std::env::temp_dir().join(format!("orbit-bus-{:016x}", rand::random::<u64>()));
        assert!(
            matches!(system_bus_ready(&path), Err(Outcome::Refused { code, .. }) if code == "agent.supervisor_not_ready")
        );
        std::fs::write(&path, "invalid").unwrap();
        assert!(
            matches!(system_bus_ready(&path), Err(Outcome::Refused { code, .. }) if code == "agent.supervisor_failed")
        );
        std::fs::remove_file(&path).unwrap();
        let _socket = std::os::unix::net::UnixListener::bind(&path).unwrap();
        assert_eq!(system_bus_ready(&path), Ok(()));
        assert_eq!(system_bus_ready(&path), Ok(()));
        std::fs::remove_file(path).unwrap();
    }

    #[test]
    fn the_service_is_bounded_kills_its_whole_group_and_marks_the_supervised_half() {
        let arguments = arguments(
            Path::new("/usr/local/bin/orbit-agent"),
            "instances.remove-source",
            Duration::from_secs(90),
            "orbit-command-x",
            None,
            Path::new("/run/orbit-agent/locks/0"),
        );
        assert!(arguments.contains(&"--property=KillMode=control-group".to_string()));
        assert!(arguments.contains(&"--property=RuntimeMaxSec=91".to_string()));
        assert!(arguments.contains(&format!("--setenv={SUPERVISED_ENV}=1")));
        assert!(arguments
            .contains(&"--setenv=ORBIT_AGENT_LOCK_DIR=/run/orbit-agent/locks/0".to_string()));
        assert_eq!(
            arguments[arguments.len() - 3..],
            [
                "/usr/local/bin/orbit-agent",
                "command",
                "instances.remove-source"
            ]
        );
    }

    #[test]
    fn an_account_mutation_runs_its_service_as_that_account() {
        let arguments = arguments(
            Path::new("/usr/local/bin/orbit-agent"),
            "instances.remove-source",
            Duration::from_secs(9),
            "u",
            Some("orbit"),
            Path::new("/run/orbit-agent/locks/1000"),
        );
        assert!(arguments.contains(&"--uid=orbit".to_string()));
        assert!(
            arguments.iter().position(|a| a == "--uid=orbit")
                < arguments.iter().position(|a| a == "--")
        );
    }

    #[test]
    fn unit_names_are_unique_and_safe() {
        let first = unit_name("agent.install");
        assert!(first.starts_with("orbit-command-agent-install-"));
        assert_ne!(first, unit_name("agent.install"));
    }

    #[test]
    fn reads_the_last_result_line_and_rejects_anything_else() {
        assert_eq!(
            parse_result("noise\n{\"ok\":true,\"data\":{\"a\":1}}\n"),
            Some(Outcome::Ok(json!({"a": 1})))
        );
        assert_eq!(
            parse_result(
                "{\"ok\":false,\"error\":{\"code\":\"x.y\",\"message\":\"m\",\"details\":{}}}"
            ),
            Some(Outcome::refused("x.y", "m", json!({})))
        );
        assert_eq!(parse_result(""), None);
        assert_eq!(parse_result("{\"ok\":\"maybe\"}"), None);
    }
}

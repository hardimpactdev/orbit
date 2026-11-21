//! `instance.names`: a reserved copy takes its final name and branch when its user submits (docs/reference/conn.md#reserve-a-clone).
//!
//! The copy runs already: its container, T3 and fleet are keyed by the Instance id, so only what names the Instance changes. The
//! branch moves first, then the names. Then `SIGHUP` tells a Conn Server that supports live reload to re-read its label and
//! `/etc/orbit/instance` without dropping connections; an older server that dies on `SIGHUP` or keeps its old label falls back
//! to a restart.

use std::time::{Duration, Instant};

use serde::Deserialize;
use serde_json::Value;

use crate::checkout::{branch, git, Target, Workspace};
use crate::prepare::{Files, Folders};
use crate::{Failure, Incus, System};

const FAILED: &str = "instance.names_failed";
const INVALID: &str = "instance.names_input_invalid";
/// Which Instance the copy's workspace was checked out for, and at which commit (instance.checkout).
const MARK: &str = "/var/lib/orbit/workspace-checkout";
const CURL: &str = "/usr/bin/curl";
const SYSTEMCTL: &str = "/usr/bin/systemctl";
/// How often the server's label is read after reload or restart.
const POLL: Duration = Duration::from_millis(50);
/// Maximum wait for a live reload after `SIGHUP` before falling back to a restart.
const RELOAD_WAIT: Duration = Duration::from_secs(2);

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct NamesInput {
    project: String,
    name: String,
    instance_id: u64,
    workspace: String,
    /// The workspace's group in the copy.
    group: u32,
    /// The machine name: the hostname, and the pretty name that T3 shows as its label.
    hostname: String,
    pretty: String,
    /// `/etc/orbit/instance`, which names the Instance to its agents.
    instance_file: String,
    branch: Move,
    /// T3's unit and port in the copy, and how long its label may take after reload or restart.
    t3_unit: String,
    t3_port: u16,
    label_seconds: u32,
}

/// The branch: the reservation's own branch renamed, or another branch from the Node's mirror.
#[derive(Debug, Deserialize)]
#[serde(tag = "mode", rename_all = "snake_case", deny_unknown_fields)]
enum Move {
    Rename { from: String, to: String },
    Switch { branch: String, fetched: String, expected_commit: String, mirror: String },
}

/// Moves the branch, writes the names and reloads (or falls back to restarting) T3 until it answers with the new label. A
/// repeat finds what it did and does it again without harm. Answers `{"branch", "commit", "restart_ms", "reloaded"}`.
///
/// # Errors
///
/// `instance.names_input_invalid`, `instance.container_checkout_failed` for a branch that cannot move, or `instance.names_failed`.
pub fn names(system: &impl System, input: &Value) -> Result<String, Failure> {
    let input: NamesInput = serde_json::from_value(input.clone()).map_err(|error| Failure::new(INVALID, error.to_string()))?;
    check(&input).map_err(|detail| Failure::new(INVALID, detail))?;
    let incus = Incus { system, code: FAILED };
    let files = Files { incus: &incus, project: &input.project, name: &input.name };
    let copy = Workspace { project: &input.project, name: &input.name, instance_id: input.instance_id, root: &input.workspace, group: input.group };
    match &input.branch {
        Move::Rename { from, to } => {
            let current = git(&incus, &copy, &["symbolic-ref", "--quiet", "--short", "HEAD"])?;
            if current.trim() == from {
                git(&incus, &copy, &["branch", "-m", from, to])?;
            } else if current.trim() != to {
                return Err(Failure::new("instance.container_checkout_failed", format!("the workspace holds {} instead of {from}", current.trim())));
            }
        }
        Move::Switch { branch: name, fetched, expected_commit, mirror } => {
            let target = Target { branch: name, fetched, expected_commit, mirror: Some(mirror) };
            branch(system, &incus, &files, &copy, &target)?;
            let mut mark = serde_json::json!({"instance_id": input.instance_id, "commit": expected_commit}).to_string();
            mark.push('\n');
            files.push(MARK, 0, 0, "0600", mark.as_bytes(), Folders::Root)?;
        }
    }
    let now = git(&incus, &copy, &["symbolic-ref", "--short", "HEAD"])?;
    let commit = git(&incus, &copy, &["rev-parse", "HEAD"])?;
    machine(&incus, &files, &input)?;
    files.push("/etc/orbit/instance", 0, 0, "0644", input.instance_file.as_bytes(), Folders::Root)?;
    let (restart_ms, reloaded) = reload_or_restart(&incus, &input)?;
    Ok(serde_json::json!({"branch": now.trim(), "commit": commit.trim(), "restart_ms": restart_ms, "reloaded": reloaded}).to_string())
}

fn check(input: &NamesInput) -> Result<(), String> {
    for (field, value) in [("project", &input.project), ("name", &input.name)] {
        if !crate::name(value) {
            return Err(format!("{field} is not a name"));
        }
    }
    let label = |value: &str| {
        !value.is_empty() && value.len() <= 63 && value.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'-') && !value.starts_with('-') && !value.ends_with('-')
    };
    for (field, value) in [("hostname", &input.hostname), ("pretty", &input.pretty)] {
        if value.len() > 253 || !value.split('.').all(label) {
            return Err(format!("{field} is not a host name"));
        }
    }
    if !input.workspace.starts_with('/') || input.workspace.split('/').any(|part| part == ".." || part == ".") {
        return Err("workspace is not a path".to_owned());
    }
    if input.instance_file.len() > 4096 || input.instance_file.bytes().any(|byte| byte.is_ascii_control() && byte != b'\n') {
        return Err("instance_file is not a short text".to_owned());
    }
    if !crate::name(input.t3_unit.trim_end_matches(".service")) || !input.t3_unit.ends_with(".service") || input.label_seconds == 0 || input.label_seconds > 120 {
        return Err("t3_unit or label_seconds is not valid".to_owned());
    }
    let branches: Vec<&str> = match &input.branch {
        Move::Rename { from, to } => vec![from, to],
        Move::Switch { branch, fetched, expected_commit, mirror } => {
            if expected_commit.len() != 40 || !expected_commit.bytes().all(|byte| byte.is_ascii_hexdigit()) {
                return Err("expected_commit is not a commit".to_owned());
            }
            crate::fetch::mirror_path(mirror).then_some(()).ok_or("mirror is not an Orbit mirror")?;
            vec![branch, fetched]
        }
    };
    branches.into_iter().all(crate::fetch::branch_name).then_some(()).ok_or_else(|| "branch is not a branch name".to_owned())
}

/// The hostname, its `127.0.1.1` line and the pretty name, as the copy's start writes them (instance.start).
fn machine(incus: &Incus<'_, impl System>, files: &Files<'_, impl System>, input: &NamesInput) -> Result<(), Failure> {
    let hostname = format!("{}\n", input.hostname);
    if files.pull("/etc/hostname")?.as_deref() != Some(hostname.as_bytes()) {
        files.push("/etc/hostname", 0, 0, "0644", hostname.as_bytes(), Folders::Root)?;
    }
    incus.change(&["--project", &input.project, "exec", &input.name, "--", "/usr/bin/hostname", &input.hostname])?;
    let current = files.pull("/etc/hosts")?.map(|bytes| String::from_utf8_lossy(&bytes).into_owned()).unwrap_or_default();
    let wanted = crate::start::hosts(&current, &input.hostname, &input.name);
    if wanted.lines().ne(current.lines()) {
        files.push("/etc/hosts", 0, 0, "0644", wanted.as_bytes(), Folders::Root)?;
    }
    files.push("/etc/machine-info", 0, 0, "0644", format!("PRETTY_HOSTNAME={}\n", input.pretty).as_bytes(), Folders::Root)
}

/// Sends `SIGHUP` to reload a live-reloading Conn Server and waits briefly for the new label. Falls back to `systemctl restart`
/// only when the unit is not active, exits on `SIGHUP` or keeps its old label past the reload window. Answers `(elapsed_ms, reloaded)`.
fn reload_or_restart(incus: &Incus<'_, impl System>, input: &NamesInput) -> Result<(u128, bool), Failure> {
    let started = Instant::now();
    let total = Duration::from_secs(u64::from(input.label_seconds));
    let deadline = started + total;
    let url = format!("http://127.0.0.1:{}/.well-known/t3/environment", input.t3_port);
    let mut last = String::new();

    if incus.change(&["--project", &input.project, "exec", &input.name, "--", SYSTEMCTL, "kill", "-s", "HUP", "--kill-who=main", &input.t3_unit]).is_ok() {
        let reload_deadline = deadline.min(started + RELOAD_WAIT.min(total / 2).max(Duration::from_millis(200)));
        while Instant::now() < reload_deadline {
            match read_label(incus, input, &url) {
                Some(label) if label == input.pretty => return Ok((started.elapsed().as_millis(), true)),
                Some(label) => last = label,
                None => {
                    // Older servers terminate on SIGHUP; fall back to an immediate restart instead of waiting for RestartSec.
                    if !unit_active(incus, input) {
                        break;
                    }
                }
            }
            std::thread::sleep(POLL);
        }
    }

    incus.change(&["--project", &input.project, "exec", &input.name, "--", SYSTEMCTL, "restart", &input.t3_unit])?;
    while Instant::now() < deadline {
        if let Some(label) = read_label(incus, input, &url) {
            if label == input.pretty {
                return Ok((started.elapsed().as_millis(), false));
            }
            last = label;
        }
        std::thread::sleep(POLL);
    }
    Err(Failure::new(FAILED, format!("T3 did not answer with the label {} within {} s; it said {last}", input.pretty, input.label_seconds)))
}

/// Reads the server's `label` from `/.well-known/t3/environment`, or `None` when the server did not answer.
fn read_label(incus: &Incus<'_, impl System>, input: &NamesInput, url: &str) -> Option<String> {
    let argv: Vec<String> = ["--project", &input.project, "exec", &input.name, "--", CURL, "-s", "-m", "2", url].iter().map(|part| (*part).to_owned()).collect();
    let body = incus.run(&argv, None).ok()?;
    let value = serde_json::from_str::<Value>(&body).ok()?;
    Some(value.get("label").and_then(Value::as_str).map_or_else(|| "no label".to_owned(), str::to_owned))
}

fn unit_active(incus: &Incus<'_, impl System>, input: &NamesInput) -> bool {
    incus.change(&["--project", &input.project, "exec", &input.name, "--", SYSTEMCTL, "is-active", "--quiet", &input.t3_unit]).is_ok()
}

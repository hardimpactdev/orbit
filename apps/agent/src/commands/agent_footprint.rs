//! `agent.configure` and `agent.remove`: the agent's own files around its binary. `agent.configure` keeps the secret
//! while it matches the Gateway's record and otherwise makes a new one, writes the configuration, the Gateway CA, and
//! the observer unit, closes Instance environments to other users, and restarts the observer when anything changed.
//! The secret never leaves the Node: the Gateway learns only its hash. Both hold the update lock that
//! `agent.install` and `orbit self-update` hold.

use super::agent::{LINUX_BINARY, OBSERVER_UNIT};
use super::recipe::Files;
use crate::command::{lock, Command, Context, Platform, Privilege};
use crate::command_errors;
use crate::{CA_PATH, CONFIG_PATH, LOCK_PATH, SECRET_PATH};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    fs,
    io::Write,
    os::unix::fs::{DirBuilderExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::{Duration, Instant},
};

const UNIT_PATH: &str = "/etc/systemd/system/orbit-agent.service";
/// How long a converge waits for a self-update to release the update lock.
const UPDATE_WAIT: Duration = Duration::from_secs(300);

command_errors! {
    pub enum FootprintError {
        UpdateBusy {} => "agent.update_busy", "A self-update held the update lock for too long.";
        Failed { step: String } => "agent.configure_failed", "An agent file or unit step failed.";
    }
}

fn failed(step: &str) -> FootprintError {
    FootprintError::Failed { step: step.into() }
}

/// The host boundary: systemd. Paths stay in [`Files`].
trait Host {
    fn systemctl(&mut self, arguments: &[&str]) -> bool;
}

struct Native;

impl Host for Native {
    fn systemctl(&mut self, arguments: &[&str]) -> bool {
        Process::new("systemctl")
            .args(arguments)
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .is_ok_and(|status| status.success())
    }
}

fn hold_update_lock(files: &Files, deadline: Instant) -> Result<lock::Held, FootprintError> {
    lock::acquire(&files.path(lock::UPDATE_LOCK_LINUX), deadline).map_err(|error| match error {
        lock::LockError::Busy(_) => FootprintError::UpdateBusy {},
        lock::LockError::Io(..) => failed("update lock"),
    })
}

/// Writes a root-owned file with one rename when its content differs. Whether it changed.
fn publish(path: &Path, content: &str, mode: u32) -> Result<bool, FootprintError> {
    if fs::read(path).ok().as_deref() == Some(content.as_bytes()) {
        return Ok(false);
    }
    let candidate = PathBuf::from(format!("{}.orbit-candidate", path.display()));
    let _ = fs::remove_file(&candidate);
    let written = fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(mode)
        .open(&candidate)
        .and_then(|mut file| {
            file.write_all(content.as_bytes())
                .and_then(|()| file.sync_all())
        })
        .and_then(|()| fs::set_permissions(&candidate, fs::Permissions::from_mode(mode)))
        .and_then(|()| fs::rename(&candidate, path));
    if written.is_err() {
        let _ = fs::remove_file(&candidate);
        return Err(failed("agent-files"));
    }
    Ok(true)
}

/// Removes the world bits from each checkout's `.env`. Returns the checkouts it could not close.
fn close_environments(files: &Files, checkouts: &[PathBuf]) -> Vec<String> {
    let mut failed = Vec::new();
    for named in checkouts {
        let checkout = &files.at(named);
        let real = fs::symlink_metadata(checkout).is_ok_and(|metadata| metadata.is_dir())
            && fs::canonicalize(checkout).ok().as_deref() == Some(checkout.as_path());
        if !real {
            continue;
        }
        let environment = checkout.join(".env");
        let Ok(metadata) = fs::symlink_metadata(&environment) else {
            continue;
        };
        if !metadata.is_file() {
            continue;
        }
        let mode = metadata.permissions().mode() & !0o007;
        if fs::set_permissions(&environment, fs::Permissions::from_mode(mode)).is_err() {
            failed.push(named.display().to_string());
        }
    }
    failed
}

/// Whether the installed secret matches the hash the Gateway recorded, so the agent keeps it.
fn kept(installed: Option<&[u8]>, recorded: Option<&str>) -> bool {
    match (installed, recorded) {
        (Some(secret), Some(recorded)) => format!("{:x}", Sha256::digest(secret)) == recorded,
        _ => false,
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ConfigureInput {
    /// The hash of the secret the Gateway recorded, if any; a file that matches it is kept.
    pub secret_hash: Option<String>,
    pub configuration: String,
    pub certificate: String,
    pub unit: String,
    /// Application directories whose `.env` other local users must not read.
    pub checkouts: Vec<PathBuf>,
    /// The binary changed just before, so the observer restarts.
    pub binary_changed: bool,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Configured {
    pub secret_hash: String,
    pub restarted: bool,
    /// Checkouts whose environment could not be closed; the converge only warns about them.
    pub unclosed: Vec<String>,
}

fn configure(
    files: &Files,
    host: &mut impl Host,
    input: &ConfigureInput,
    deadline: Instant,
) -> Result<Configured, FootprintError> {
    let _lock = hold_update_lock(files, deadline)?;
    let directory = &files.path(LOCK_PATH);
    if !directory.is_dir() {
        fs::DirBuilder::new()
            .mode(0o700)
            .recursive(true)
            .create(directory)
            .map_err(|_| failed("agent-directory"))?;
    }
    fs::set_permissions(directory, fs::Permissions::from_mode(0o700))
        .map_err(|_| failed("agent-directory"))?;
    let installed = fs::read(files.path(SECRET_PATH)).ok();
    let keep = kept(installed.as_deref(), input.secret_hash.as_deref());
    let secret_hash = if let (true, Some(recorded)) = (keep, &input.secret_hash) {
        recorded.clone()
    } else {
        let bytes: [u8; 32] = rand::random();
        let secret: String = bytes.iter().map(|byte| format!("{byte:02x}")).collect();
        publish(&files.path(SECRET_PATH), &secret, 0o600)?;
        format!("{:x}", Sha256::digest(secret.as_bytes()))
    };
    let mut changed = !keep || input.binary_changed;
    changed |= publish(&files.path(CONFIG_PATH), &input.configuration, 0o644)?;
    changed |= publish(&files.path(CA_PATH), &input.certificate, 0o644)?;
    changed |= publish(&files.path(UNIT_PATH), &input.unit, 0o644)?;
    let unclosed = close_environments(files, &input.checkouts);
    if !host.systemctl(&["daemon-reload"]) || !host.systemctl(&["enable", "--now", OBSERVER_UNIT]) {
        return Err(failed("agent-unit"));
    }
    if changed && !host.systemctl(&["restart", OBSERVER_UNIT]) {
        return Err(failed("agent-restart"));
    }
    Ok(Configured {
        secret_hash,
        restarted: changed,
        unclosed,
    })
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RemoveInput {}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Removed {}

/// Stops the observer and removes the agent's unit, binary, and files. The running command keeps its open binary.
fn remove(
    files: &Files,
    host: &mut impl Host,
    deadline: Instant,
) -> Result<Removed, FootprintError> {
    let _lock = hold_update_lock(files, deadline)?;
    let mut complete = true;
    if files.path(UNIT_PATH).is_file() {
        complete &= host.systemctl(&["stop", OBSERVER_UNIT]);
        complete &= host.systemctl(&["disable", OBSERVER_UNIT]);
    }
    for file in [UNIT_PATH, LINUX_BINARY].map(|path| files.path(path)) {
        if fs::remove_file(&file).is_err() && file.exists() {
            complete = false;
        }
    }
    let directory = files.path(LOCK_PATH);
    if fs::remove_dir_all(&directory).is_err() && directory.exists() {
        complete = false;
    }
    complete &= host.systemctl(&["daemon-reload"]);
    host.systemctl(&["reset-failed", OBSERVER_UNIT]);
    if complete {
        Ok(Removed {})
    } else {
        Err(failed("agent-remove"))
    }
}

pub struct ConfigureAgent;

impl Command for ConfigureAgent {
    const NAME: &'static str = "agent.configure";
    const SUMMARY: &'static str =
        "Write the agent's secret, configuration, CA, and unit, and restart it when they change.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(420);
    type Input = ConfigureInput;
    type Output = Configured;
    type Error = FootprintError;

    /// The agent's files and unit belong to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: ConfigureInput, context: &Context) -> Result<Configured, FootprintError> {
        configure(
            &Files::system(),
            &mut Native,
            &input,
            lock::answer_by(context.deadline()).min(Instant::now() + UPDATE_WAIT),
        )
    }
}

pub struct RemoveAgent;

impl Command for RemoveAgent {
    const NAME: &'static str = "agent.remove";
    const SUMMARY: &'static str =
        "Stop the observer and remove the agent's binary, unit, and files.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(420);
    type Input = RemoveInput;
    type Output = Removed;
    type Error = FootprintError;

    /// The agent's files and unit belong to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(_: RemoveInput, context: &Context) -> Result<Removed, FootprintError> {
        remove(
            &Files::system(),
            &mut Native,
            lock::answer_by(context.deadline()).min(Instant::now() + UPDATE_WAIT),
        )
    }
}

#[cfg(test)]
#[path = "agent_footprint_tests.rs"]
mod tests;

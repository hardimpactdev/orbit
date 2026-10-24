//! `processes.*`: a Process's systemd unit or Docker container on its Node. Each command acts only on an artifact that
//! carries the Process's own marker: the unit's `X-Orbit-Process-ID=<id>` line, or the container's `orbit.managed`,
//! `orbit.container.kind`, and `orbit.process.id` labels.
//!
//! A unit change is two calls, so the Gateway can record a pending activation between them: `processes.stage-unit`
//! writes a candidate that `systemd-analyze verify` accepts, and `processes.activate-unit` publishes it with a backup
//! of the unit it replaces, reloads systemd, sets the desired state, and restores the previous unit when that fails.
//! `processes.converge-container` changes a container in one call: it creates a candidate beside the current
//! container, renames that one to a rollback name that records whether it ran, renames the candidate in, and undoes
//! the change when a step fails. A later call finishes or undoes a change that a lost connection interrupted.

#[path = "process_doctor.rs"]
pub(super) mod doctor;

use super::caddy::chown;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use crate::logs::{journalctl, valid_docker_container, valid_journal_unit};
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    fs,
    io::{self, Read, Write},
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

/// What a failed program printed, so the Gateway can keep it with the failed step.
#[derive(Debug, Clone, Default, PartialEq, Serialize)]
pub struct Output {
    pub exit_code: i32,
    pub stdout: String,
    pub stderr: String,
}

impl Output {
    fn ok(&self) -> bool {
        self.exit_code == 0
    }

    fn io(error: &io::Error) -> Self {
        Self {
            exit_code: 1,
            stdout: String::new(),
            stderr: error.to_string(),
        }
    }
}

command_errors! {
    pub enum ProcessError {
        Invalid { check: String } => "processes.invalid", "The Process request does not pass its checks.";
        NameCollision { step: String, artifact: String, output: Output } => "processes.name_collision", "An artifact with the Process's name is not the Process's own.";
        NotFound { step: String } => "processes.not_found", "The Process has no runtime artifact.";
        StartFailed { step: String, restored: bool, output: Output } => "processes.start_failed", "The Process did not start.";
        StopFailed { step: String, restored: bool, output: Output } => "processes.stop_failed", "The Process did not stop.";
        RestartFailed { step: String, output: Output } => "processes.restart_failed", "The Process did not restart.";
        RemoveFailed { step: String, output: Output } => "processes.remove_failed", "The Process runtime could not be removed.";
        StatusFailed { step: String, output: Output } => "processes.status_failed", "The Process status could not be read.";
        LogsFailed { step: String, output: Output } => "processes.logs_failed", "The Process logs could not be read.";
        ConvergeFailed { step: String, output: Output } => "processes.converge_failed", "A Process runtime step failed.";
        CandidateInvalid { step: String, artifact: String, output: Output } => "processes.candidate_invalid", "The new runtime artifact does not pass its checks.";
        ActivationFailed { step: String, output: Output } => "processes.activation_failed", "The new runtime artifact did not take over, and the previous one is back.";
        RollbackFailed { step: String, output: Output } => "processes.rollback_failed", "Restoring the previous unit failed.";
        RecoveryRequired { step: String, reason: String, output: Output } => "processes.recovery_required", "The runtime needs an operator to recover it.";
        ViteCleanupFailed { step: String, output: Output } => "processes.vite_cleanup_failed", "The Vite environment file could not be removed.";
    }
}

fn invalid(check: &str) -> ProcessError {
    ProcessError::Invalid {
        check: check.into(),
    }
}

/// The error of a failed step, by the call it fails.
type Fail = fn(String, Output) -> ProcessError;

fn start_failed(step: String, output: Output) -> ProcessError {
    ProcessError::StartFailed {
        step,
        restored: false,
        output,
    }
}
fn stop_failed(step: String, output: Output) -> ProcessError {
    ProcessError::StopFailed {
        step,
        restored: false,
        output,
    }
}
fn restart_failed(step: String, output: Output) -> ProcessError {
    ProcessError::RestartFailed { step, output }
}
fn remove_failed(step: String, output: Output) -> ProcessError {
    ProcessError::RemoveFailed { step, output }
}
fn status_failed(step: String, output: Output) -> ProcessError {
    ProcessError::StatusFailed { step, output }
}
fn logs_failed(step: String, output: Output) -> ProcessError {
    ProcessError::LogsFailed { step, output }
}
fn converge_failed(step: String, output: Output) -> ProcessError {
    ProcessError::ConvergeFailed { step, output }
}
fn activation_failed(step: String, output: Output) -> ProcessError {
    ProcessError::ActivationFailed { step, output }
}
fn rollback_failed(step: String, output: Output) -> ProcessError {
    ProcessError::RollbackFailed { step, output }
}
fn recovery_required(step: String, output: Output) -> ProcessError {
    ProcessError::RecoveryRequired {
        step,
        reason: "step_failed".into(),
        output,
    }
}

/// The programs a command runs: `systemctl`, `systemd-analyze`, and `docker`. Tests answer for them.
pub trait Programs {
    fn run(&mut self, program: &str, arguments: &[&str]) -> Output;
}

/// The most of each stream a failed step keeps.
const MAX_STREAM_BYTES: usize = 64 * 1024;

fn tail(bytes: &[u8]) -> String {
    String::from_utf8_lossy(&bytes[bytes.len().saturating_sub(MAX_STREAM_BYTES)..]).into_owned()
}

pub struct System;

impl Programs for System {
    fn run(&mut self, program: &str, arguments: &[&str]) -> Output {
        match Process::new(program)
            .args(arguments)
            .stdin(Stdio::null())
            .output()
        {
            Ok(output) => Output {
                exit_code: output.status.code().unwrap_or(-1),
                stdout: tail(&output.stdout),
                stderr: tail(&output.stderr),
            },
            Err(error) => Output {
                exit_code: 127,
                stdout: String::new(),
                stderr: format!("{program}: {error}"),
            },
        }
    }
}

/// Where the commands find units, candidates, and their scratch space, and how they run programs. Tests point the
/// directories at a sandbox.
pub struct Node<P> {
    pub units: PathBuf,
    pub candidates: PathBuf,
    pub scratch: PathBuf,
    pub programs: P,
}

impl Node<System> {
    pub fn system() -> Self {
        Self {
            units: PathBuf::from("/etc/systemd/system"),
            candidates: PathBuf::from("/etc/orbit/systemd-candidates"),
            scratch: PathBuf::from("/run/orbit-agent"),
            programs: System,
        }
    }
}

#[derive(Deserialize, Debug, Clone, Copy, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum Runtime {
    Systemd,
    Docker,
}

#[derive(Deserialize, Debug, Clone, Copy, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum Desired {
    Running,
    Stopped,
}

/// A container's ownership labels, spec label, and running state on one line each.
const INSPECT_FORMAT: &str = r#"{{ index .Config.Labels "orbit.managed" }}{{ printf "\n" }}{{ index .Config.Labels "orbit.container.kind" }}{{ printf "\n" }}{{ index .Config.Labels "orbit.process.id" }}{{ printf "\n" }}{{ index .Config.Labels "orbit.process.spec" }}{{ printf "\n" }}{{ .State.Running }}"#;

/// An owned container: its spec label, whether it runs, and the inspection that showed it.
#[derive(Debug, Clone)]
struct Container {
    spec: String,
    running: bool,
    output: Output,
}

fn docker_not_found(output: &Output) -> bool {
    !output.ok()
        && (output.stderr.contains("No such object") || output.stderr.contains("No such container"))
}

fn systemd_not_found(output: &Output) -> bool {
    !output.ok()
        && (output.stderr.contains("No such file or directory")
            || output.stderr.contains("could not be found")
            || output.stderr.contains("not found"))
}

/// Whether the unit text has the Process's marker as a whole line.
pub(super) fn marked(text: &str, process_id: u64) -> bool {
    let marker = format!("X-Orbit-Process-ID={process_id}");
    text.split('\n')
        .any(|line| line.strip_suffix('\r').unwrap_or(line) == marker)
}

/// The unit or container name must be the Process's own: `orbit-process-<id>-<name>`, with `.service` for a unit.
pub(super) fn check_name(
    runtime: Runtime,
    process_id: u64,
    name: &str,
) -> Result<(), ProcessError> {
    let valid = match runtime {
        Runtime::Systemd => {
            valid_journal_unit(name)
                && name
                    .strip_suffix(".service")
                    .is_some_and(|base| valid_docker_container(base, process_id))
        }
        Runtime::Docker => valid_docker_container(name, process_id),
    };
    if valid {
        Ok(())
    } else {
        Err(invalid("the artifact name belongs to the Process"))
    }
}

impl<P: Programs> Node<P> {
    fn run(&mut self, program: &str, arguments: &[&str]) -> Output {
        self.programs.run(program, arguments)
    }

    fn must(
        &mut self,
        program: &str,
        arguments: &[&str],
        step: &str,
        fail: Fail,
    ) -> Result<Output, ProcessError> {
        let output = self.run(program, arguments);
        if output.ok() {
            Ok(output)
        } else {
            Err(fail(step.into(), output))
        }
    }

    fn docker(&mut self, arguments: &[&str]) -> Output {
        self.run("docker", arguments)
    }

    /// The unit's text when it is the Process's own, `None` when it is absent.
    fn owned_unit(
        &self,
        process_id: u64,
        unit: &str,
        step: &str,
        fail: Fail,
    ) -> Result<Option<String>, ProcessError> {
        let path = self.units.join(unit);
        match fs::read_to_string(&path) {
            Err(error) if error.kind() == io::ErrorKind::NotFound => Ok(None),
            Err(error) => Err(fail(step.into(), Output::io(&error))),
            Ok(text) if marked(&text, process_id) => Ok(Some(text)),
            Ok(text) => Err(ProcessError::NameCollision {
                step: step.into(),
                artifact: path.display().to_string(),
                output: Output {
                    exit_code: 0,
                    stdout: text,
                    stderr: String::new(),
                },
            }),
        }
    }

    /// The container when it is the Process's own, `None` when it is absent.
    fn owned_container(
        &mut self,
        process_id: u64,
        name: &str,
        step: &str,
        fail: Fail,
    ) -> Result<Option<Container>, ProcessError> {
        let output = self.docker(&["container", "inspect", "--format", INSPECT_FORMAT, name]);
        if docker_not_found(&output) {
            return Ok(None);
        }
        if !output.ok() {
            return Err(fail(step.into(), output));
        }
        let text = output.stdout.trim().to_string();
        let mut fields = text.splitn(5, '\n');
        let mut field = || fields.next().unwrap_or("").to_string();
        let (managed, kind, owner, spec, running) = (field(), field(), field(), field(), field());
        if managed != "true" || kind != "process" || owner != process_id.to_string() {
            return Err(ProcessError::NameCollision {
                step: step.into(),
                artifact: name.into(),
                output,
            });
        }
        let running = match running.as_str() {
            "true" => true,
            "false" => false,
            _ => return Err(fail(step.into(), output)),
        };
        Ok(Some(Container {
            spec,
            running,
            output,
        }))
    }

    fn owned(
        &mut self,
        runtime: Runtime,
        process_id: u64,
        name: &str,
        step: &str,
        fail: Fail,
    ) -> Result<bool, ProcessError> {
        Ok(match runtime {
            Runtime::Systemd => self.owned_unit(process_id, name, step, fail)?.is_some(),
            Runtime::Docker => self
                .owned_container(process_id, name, step, fail)?
                .is_some(),
        })
    }

    /// `active`, `inactive`, a container state, `unknown` for an empty answer, or `absent`.
    fn status(
        &mut self,
        runtime: Runtime,
        process_id: u64,
        name: &str,
    ) -> Result<String, ProcessError> {
        if !self.owned(runtime, process_id, name, "status", status_failed)? {
            return Ok("absent".into());
        }
        let output = match runtime {
            Runtime::Systemd => self.run("systemctl", &["is-active", name]),
            Runtime::Docker => self.docker(&[
                "container",
                "inspect",
                "--format",
                "{{.State.Status}}",
                name,
            ]),
        };
        let state = output.stdout.trim().to_string();
        let not_found = match runtime {
            Runtime::Systemd => systemd_not_found(&output),
            Runtime::Docker => docker_not_found(&output),
        };
        if not_found {
            return Ok("absent".into());
        }
        if output.ok() {
            return Ok(if state.is_empty() {
                "unknown".into()
            } else {
                state
            });
        }
        let known = [
            "active",
            "reloading",
            "inactive",
            "failed",
            "activating",
            "deactivating",
            "maintenance",
        ];
        if runtime == Runtime::Systemd && known.contains(&state.as_str()) {
            return Ok(state);
        }
        Err(status_failed("status".into(), output))
    }

    fn daemon_reload(&mut self) -> Output {
        self.run("systemctl", &["daemon-reload"])
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReleaseInput {
    /// The selected production release, through `current`.
    pub directory: PathBuf,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Release {
    pub available: bool,
}

pub fn check_release(input: &ReleaseInput) -> Release {
    Release {
        available: input.directory.is_dir(),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ArtifactInput {
    pub process_id: u64,
    pub runtime: Runtime,
    /// The unit, such as `orbit-process-7-web.service`, or the container, such as `orbit-process-7-web`.
    pub name: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Status {
    pub state: String,
}

pub fn status<P: Programs>(
    node: &mut Node<P>,
    input: &ArtifactInput,
) -> Result<Status, ProcessError> {
    check_name(input.runtime, input.process_id, &input.name)?;
    Ok(Status {
        state: node.status(input.runtime, input.process_id, &input.name)?,
    })
}

#[derive(Deserialize, Debug, Clone, Copy, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum Operation {
    Start,
    Stop,
    Restart,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ControlInput {
    pub operation: Operation,
    pub process_id: u64,
    pub runtime: Runtime,
    pub name: String,
    /// An on-demand host starts the unit without enabling it, so it does not start at boot.
    pub on_demand: bool,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Done {}

pub fn control<P: Programs>(
    node: &mut Node<P>,
    input: &ControlInput,
) -> Result<Done, ProcessError> {
    check_name(input.runtime, input.process_id, &input.name)?;
    let (step, fail): (&str, Fail) = match input.operation {
        Operation::Start => ("start", start_failed),
        Operation::Stop => ("stop", stop_failed),
        Operation::Restart => ("restart", restart_failed),
    };
    if !node.owned(input.runtime, input.process_id, &input.name, step, fail)? {
        return Err(ProcessError::NotFound { step: step.into() });
    }
    let name = input.name.as_str();
    match (input.operation, input.runtime) {
        (Operation::Start, Runtime::Systemd) if input.on_demand => {
            node.must("systemctl", &["start", name], step, fail)?
        }
        (Operation::Start, Runtime::Systemd) => {
            node.must("systemctl", &["enable", "--now", name], step, fail)?
        }
        (Operation::Stop, Runtime::Systemd) => {
            node.must("systemctl", &["disable", "--now", name], step, fail)?
        }
        (Operation::Restart, Runtime::Systemd) => {
            if !input.on_demand {
                node.must("systemctl", &["enable", name], "restart-enable", fail)?;
            }
            node.must("systemctl", &["restart", name], step, fail)?
        }
        (Operation::Start, Runtime::Docker) => {
            node.must("docker", &["container", "start", name], step, fail)?
        }
        (Operation::Stop, Runtime::Docker) => {
            node.must("docker", &["container", "stop", name], step, fail)?
        }
        (Operation::Restart, Runtime::Docker) => {
            node.must("docker", &["container", "restart", name], step, fail)?
        }
    };
    Ok(Done {})
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ViteEnvironment {
    /// `/etc/orbit/vite/app-instance-<instance_id>.env`.
    pub path: PathBuf,
    pub instance_id: u64,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RemoveInput {
    pub process_id: u64,
    pub runtime: Runtime,
    pub name: String,
    /// A Vite+ dev Process also leaves the environment file of its Instance.
    pub vite: Option<ViteEnvironment>,
}

/// Removes the Instance's Vite environment file and its pending copy, when the file carries the Instance's marker.
fn remove_vite_environment(vite: &ViteEnvironment) -> Result<(), ProcessError> {
    let step = "remove-vite-environment";
    let fail = |error: &io::Error| ProcessError::ViteCleanupFailed {
        step: step.into(),
        output: Output::io(error),
    };
    if vite.path.file_name()
        != Some(std::ffi::OsStr::new(&format!(
            "app-instance-{}.env",
            vite.instance_id
        )))
        || !vite.path.is_absolute()
    {
        return Err(invalid("the Vite environment path belongs to the Instance"));
    }
    if !vite.path.exists() {
        return Ok(());
    }
    let marker = format!("# Orbit Instance {}", vite.instance_id);
    let metadata = fs::symlink_metadata(&vite.path).map_err(|error| fail(&error))?;
    let text = fs::read_to_string(&vite.path).map_err(|error| fail(&error))?;
    if metadata.file_type().is_symlink() || !text.split('\n').any(|line| line == marker) {
        return Err(fail(&io::Error::other(
            "the file is a link or lacks the Instance marker",
        )));
    }
    let mut pending = vite.path.as_os_str().to_owned();
    pending.push(".pending");
    for path in [vite.path.clone(), PathBuf::from(pending)] {
        match fs::remove_file(&path) {
            Err(error) if error.kind() != io::ErrorKind::NotFound => return Err(fail(&error)),
            _ => {}
        }
    }
    Ok(())
}

pub fn remove<P: Programs>(node: &mut Node<P>, input: &RemoveInput) -> Result<Done, ProcessError> {
    check_name(input.runtime, input.process_id, &input.name)?;
    let name = input.name.as_str();
    if input.runtime == Runtime::Docker {
        if input.vite.is_some() {
            return Err(invalid("only a systemd Process has a Vite environment"));
        }
        let candidate = format!("{name}-candidate");
        let running = format!("{name}-rollback-running");
        let stopped = format!("{name}-rollback-stopped");
        // Every artifact is checked before any is removed.
        let mut present = Vec::new();
        for artifact in [&candidate, &input.name, &running, &stopped] {
            if node
                .owned_container(
                    input.process_id,
                    artifact,
                    "inspect-runtime",
                    converge_failed,
                )?
                .is_some()
            {
                present.push(artifact.clone());
            }
        }
        for artifact in present {
            node.must(
                "docker",
                &["container", "rm", "--force", &artifact],
                "remove",
                remove_failed,
            )?;
        }
        return Ok(Done {});
    }
    if node
        .owned_unit(input.process_id, name, "inspect-runtime", remove_failed)?
        .is_some()
    {
        node.must(
            "systemctl",
            &["disable", "--now", name],
            "stop",
            remove_failed,
        )?;
        match fs::remove_file(node.units.join(name)) {
            Err(error) if error.kind() != io::ErrorKind::NotFound => {
                return Err(remove_failed("remove-unit".into(), Output::io(&error)))
            }
            _ => {}
        }
        node.must(
            "systemctl",
            &["daemon-reload"],
            "daemon-reload",
            remove_failed,
        )?;
        // A unit that is not failed or no longer loaded has no record to clear.
        node.run("systemctl", &["reset-failed", name]);
    }
    if let Some(vite) = &input.vite {
        remove_vite_environment(vite)?;
    }
    Ok(Done {})
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct StageInput {
    pub process_id: u64,
    pub unit: String,
    /// The rendered unit.
    pub content: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Staged {
    /// A candidate waits for `processes.activate-unit`; without one the unit already has this content.
    pub staged: bool,
    /// Whether the Process had a unit, which a publication replaces.
    pub previous: bool,
    /// The unit's state when nothing was staged.
    pub state: Option<String>,
}

fn candidate_path<P>(node: &Node<P>, unit: &str) -> PathBuf {
    node.candidates.join(unit)
}

fn backup_path<P>(node: &Node<P>, unit: &str) -> PathBuf {
    node.candidates.join(format!(".{unit}.backup"))
}

fn write_root_file(path: &Path, content: &str, mode: u32) -> io::Result<()> {
    let mut file = fs::OpenOptions::new()
        .write(true)
        .create(true)
        .truncate(true)
        .mode(mode)
        .open(path)?;
    file.write_all(content.as_bytes())?;
    file.sync_all()?;
    fs::set_permissions(path, fs::Permissions::from_mode(mode))
}

pub fn stage<P: Programs>(node: &mut Node<P>, input: &StageInput) -> Result<Staged, ProcessError> {
    check_name(Runtime::Systemd, input.process_id, &input.unit)?;
    let current = node.owned_unit(
        input.process_id,
        &input.unit,
        "inspect-unit",
        converge_failed,
    )?;
    if current.as_deref() == Some(input.content.as_str()) {
        let state = node.status(Runtime::Systemd, input.process_id, &input.unit)?;
        return Ok(Staged {
            staged: false,
            previous: true,
            state: Some(state),
        });
    }
    let io_fail = |step: &str, error: io::Error| converge_failed(step.into(), Output::io(&error));
    fs::DirBuilder::new()
        .recursive(true)
        .mode(0o755)
        .create(&node.candidates)
        .and_then(|()| fs::set_permissions(&node.candidates, fs::Permissions::from_mode(0o755)))
        .map_err(|error| io_fail("prepare-unit-candidate", error))?;
    let candidate = candidate_path(node, &input.unit);
    write_root_file(&candidate, &input.content, 0o644)
        .map_err(|error| io_fail("stage-unit", error))?;
    let candidate_text = candidate.display().to_string();
    let verified = node.run("systemd-analyze", &["verify", &candidate_text]);
    if !verified.ok() {
        let _ = fs::remove_file(&candidate);
        return Err(ProcessError::CandidateInvalid {
            step: "verify-unit".into(),
            artifact: input.unit.clone(),
            output: verified,
        });
    }
    Ok(Staged {
        staged: true,
        previous: current.is_some(),
        state: None,
    })
}

#[derive(Deserialize, Debug, Clone, Copy, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum Change {
    /// Publish the candidate over the Process's unit, keeping a backup.
    Replace,
    /// Publish the candidate where the Process had no unit.
    Create,
    /// The unit is published; reload systemd and set the desired state again.
    Reload,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ActivateInput {
    pub process_id: u64,
    pub unit: String,
    pub change: Change,
    pub desired: Desired,
    pub on_demand: bool,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Activated {
    /// The backup of a replaced unit could not be deleted.
    pub backup_left: bool,
}

/// `cp --preserve=mode,ownership,timestamps`.
fn copy_preserving(from: &Path, to: &Path) -> io::Result<()> {
    let metadata = fs::metadata(from)?;
    fs::copy(from, to)?;
    chown(to, metadata.uid(), metadata.gid())?;
    fs::set_permissions(to, metadata.permissions())?;
    fs::File::options().write(true).open(to)?.set_times(
        fs::FileTimes::new()
            .set_accessed(metadata.accessed()?)
            .set_modified(metadata.modified()?),
    )
}

impl<P: Programs> Node<P> {
    /// Puts back what a publication replaced: the backup over the unit, or no unit.
    fn restore_unit(&mut self, unit: &str, change: Change) -> Result<(), ProcessError> {
        let path = self.units.join(unit);
        let restored = match change {
            Change::Replace => fs::rename(backup_path(self, unit), &path),
            _ => match fs::remove_file(&path) {
                Err(error) if error.kind() == io::ErrorKind::NotFound => Ok(()),
                other => other,
            },
        };
        restored.map_err(|error| rollback_failed("restore-unit".into(), Output::io(&error)))?;
        let reload = self.daemon_reload();
        if !reload.ok() {
            return Err(rollback_failed("rollback-daemon-reload".into(), reload));
        }
        Ok(())
    }

    fn desired_state(&mut self, unit: &str, desired: Desired, on_demand: bool) -> Output {
        if desired == Desired::Stopped {
            return self.run("systemctl", &["disable", "--now", unit]);
        }
        if !on_demand {
            let enable = self.run("systemctl", &["enable", unit]);
            if !enable.ok() {
                return enable;
            }
        }
        self.run("systemctl", &["restart", unit])
    }
}

pub fn activate<P: Programs>(
    node: &mut Node<P>,
    input: &ActivateInput,
) -> Result<Activated, ProcessError> {
    check_name(Runtime::Systemd, input.process_id, &input.unit)?;
    let unit = input.unit.as_str();
    let path = node.units.join(unit);
    match input.change {
        Change::Reload => {
            node.must(
                "systemctl",
                &["daemon-reload"],
                "daemon-reload",
                activation_failed,
            )?;
        }
        Change::Replace | Change::Create => {
            if input.change == Change::Replace {
                copy_preserving(&path, &backup_path(node, unit))
                    .map_err(|error| converge_failed("backup-unit".into(), Output::io(&error)))?;
            }
            fs::rename(candidate_path(node, unit), &path)
                .map_err(|error| converge_failed("activate-unit".into(), Output::io(&error)))?;
            let reload = node.daemon_reload();
            if !reload.ok() {
                node.restore_unit(unit, input.change)?;
                return Err(activation_failed("daemon-reload".into(), reload));
            }
        }
    }
    let activation = node.desired_state(unit, input.desired, input.on_demand);
    if !activation.ok() {
        if input.change != Change::Reload {
            let quiesce = node.run("systemctl", &["disable", "--now", unit]);
            if !quiesce.ok() {
                return Err(recovery_required("stop-replacement-unit".into(), quiesce));
            }
            node.restore_unit(unit, input.change)?;
        }
        if input.change == Change::Replace {
            let arguments: &[&str] = match (input.desired, input.on_demand) {
                (Desired::Stopped, _) => &["disable", "--now", unit],
                (Desired::Running, true) => &["start", unit],
                (Desired::Running, false) => &["enable", "--now", unit],
            };
            node.must(
                "systemctl",
                arguments,
                "restore-service-state",
                rollback_failed,
            )?;
        }
        return Err(match input.desired {
            Desired::Running => ProcessError::StartFailed {
                step: "start".into(),
                restored: true,
                output: activation,
            },
            Desired::Stopped => ProcessError::StopFailed {
                step: "stop".into(),
                restored: true,
                output: activation,
            },
        });
    }
    let backup_left = input.change == Change::Replace
        && match fs::remove_file(backup_path(node, unit)) {
            Err(error) => error.kind() != io::ErrorKind::NotFound,
            Ok(()) => false,
        };
    Ok(Activated { backup_left })
}

#[derive(Deserialize, Debug, Clone)]
#[serde(deny_unknown_fields)]
pub struct Mount {
    /// An absolute host path binds it; a name mounts that volume.
    pub source: String,
    pub target: String,
    pub read_only: bool,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ContainerInput {
    pub process_id: u64,
    pub container: String,
    pub desired: Desired,
    /// The hash of the Process's container definition, kept as the `orbit.process.spec` label.
    pub spec: String,
    pub image: String,
    pub command: Vec<String>,
    /// `no`, `on-failure`, `always`, or `unless-stopped`.
    pub restart: String,
    pub working_directory: Option<String>,
    pub ports: Vec<String>,
    pub mounts: Vec<Mount>,
    /// Goes to `docker container create` through a root-only file, never an argument.
    pub environment: BTreeMap<String, String>,
}

fn check_container(input: &ContainerInput) -> Result<(), ProcessError> {
    check_name(Runtime::Docker, input.process_id, &input.container)?;
    let image_ok = input
        .image
        .bytes()
        .next()
        .is_some_and(|b| b.is_ascii_alphanumeric())
        && input
            .image
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"._/:@-".contains(&b));
    let checks = [
        (image_ok, "the image is a reference"),
        (!input.command.is_empty(), "the command has an argument"),
        (
            ["no", "on-failure", "always", "unless-stopped"].contains(&input.restart.as_str()),
            "the restart policy is known",
        ),
        (
            input.spec.len() == 64 && input.spec.bytes().all(|b| b.is_ascii_hexdigit()),
            "the spec is a SHA-256",
        ),
        (
            input
                .working_directory
                .as_deref()
                .is_none_or(|directory| directory.starts_with('/')),
            "the working directory is absolute",
        ),
        (
            input.environment.iter().all(|(name, value)| {
                name.bytes()
                    .next()
                    .is_some_and(|b| b.is_ascii_alphabetic() || b == b'_')
                    && name.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_')
                    && !value.contains(['\0', '\r', '\n'])
            }),
            "the environment has names and single-line values",
        ),
        (
            input.mounts.iter().all(|mount| {
                [&mount.source, &mount.target]
                    .iter()
                    .all(|part| !part.is_empty() && !part.contains([',', '=', '\0']))
                    && mount.target.starts_with('/')
            }),
            "each mount has a source and an absolute target",
        ),
    ];
    match checks.iter().find(|(ok, _)| !ok) {
        Some((_, check)) => Err(invalid(check)),
        None => Ok(()),
    }
}

/// The state that recovery leaves: whether a change is complete, and the owned canonical and candidate containers.
struct Recovered {
    complete: bool,
    canonical: Option<Container>,
    candidate: Option<Container>,
}

fn rollback_runs(rollback: &str) -> bool {
    rollback.ends_with("-rollback-running")
}

struct Converge<'a, P> {
    node: &'a mut Node<P>,
    input: &'a ContainerInput,
    name: String,
    candidate: String,
}

impl<P: Programs> Converge<'_, P> {
    fn inspect(&mut self, name: &str, step: &str) -> Result<Option<Container>, ProcessError> {
        self.node
            .owned_container(self.input.process_id, name, step, converge_failed)
    }

    fn running_desired(&self) -> bool {
        self.input.desired == Desired::Running
    }

    fn run(&mut self, arguments: &[&str]) -> Output {
        self.node.docker(arguments)
    }

    fn must(&mut self, arguments: &[&str], step: &str, fail: Fail) -> Result<(), ProcessError> {
        self.node.must("docker", arguments, step, fail).map(|_| ())
    }

    fn converge(&mut self) -> Result<(), ProcessError> {
        let state = self.recover()?;
        if state.complete {
            let Some(canonical) = state.canonical else {
                return Err(ProcessError::RecoveryRequired {
                    step: "inspect-container".into(),
                    reason: "no_canonical".into(),
                    output: Output::default(),
                });
            };
            return self.desired_state(&canonical);
        }
        let name = self.name.clone();
        let candidate = self.candidate.clone();
        let create = if state.canonical.is_none() {
            name.clone()
        } else {
            candidate.clone()
        };
        self.remove_candidate(state.candidate.as_ref())?;
        self.create(&create)?;
        let Some(canonical) = state.canonical else {
            let created = self.inspect(&name, "verify-container")?;
            return match created {
                Some(created) if created.spec == self.input.spec => self.desired_state(&created),
                created => Err(ProcessError::CandidateInvalid {
                    step: "verify-container".into(),
                    artifact: name,
                    output: created.map(|c| c.output).unwrap_or_default(),
                }),
            };
        };
        let created = self.inspect(&candidate, "verify-candidate-container")?;
        match created {
            Some(created) if created.spec == self.input.spec => self.publish(&canonical),
            created => {
                self.remove_candidate(created.as_ref())?;
                Err(ProcessError::CandidateInvalid {
                    step: "verify-candidate-container".into(),
                    artifact: candidate,
                    output: created.map(|c| c.output).unwrap_or_default(),
                })
            }
        }
    }

    fn recover(&mut self) -> Result<Recovered, ProcessError> {
        let name = self.name.clone();
        let candidate = self.candidate.clone();
        let mut canonical = self.inspect(&name, "inspect-container")?;
        let running_rollback = format!("{name}-rollback-running");
        let stopped_rollback = format!("{name}-rollback-stopped");
        let running_state = self.inspect(&running_rollback, "inspect-rollback-container")?;
        let stopped_state = self.inspect(&stopped_rollback, "inspect-rollback-container")?;
        let mut candidate_state = self.inspect(&candidate, "inspect-candidate-container")?;
        if let (Some(running), Some(_)) = (&running_state, &stopped_state) {
            return Err(ProcessError::RecoveryRequired {
                step: "inspect-rollback-container".into(),
                reason: "ambiguous_rollback".into(),
                output: running.output.clone(),
            });
        }
        let rollback = if running_state.is_some() {
            running_rollback
        } else {
            stopped_rollback
        };
        if let Some(rollback_state) = running_state.or(stopped_state) {
            canonical = Some(self.recover_rollback(
                &rollback,
                canonical,
                candidate_state,
                rollback_state,
            )?);
            candidate_state = self.inspect(&candidate, "inspect-candidate-container")?;
        }
        if let Some(current) = &canonical {
            if current.spec == self.input.spec {
                self.remove_candidate(candidate_state.as_ref())?;
                return Ok(Recovered {
                    complete: true,
                    canonical,
                    candidate: None,
                });
            }
        }
        if canonical.is_none() {
            if let Some(waiting) = &candidate_state {
                if waiting.spec == self.input.spec {
                    self.must(
                        &["container", "rename", &candidate, &name],
                        "recover-candidate-container",
                        converge_failed,
                    )?;
                    return Ok(Recovered {
                        complete: true,
                        canonical: candidate_state,
                        candidate: None,
                    });
                }
            }
        }
        Ok(Recovered {
            complete: false,
            canonical,
            candidate: candidate_state,
        })
    }

    fn recover_rollback(
        &mut self,
        rollback: &str,
        canonical: Option<Container>,
        candidate_state: Option<Container>,
        rollback_state: Container,
    ) -> Result<Container, ProcessError> {
        let candidate = self.candidate.clone();
        let Some(canonical) = canonical else {
            if candidate_state.is_some_and(|state| state.running) {
                let stop = self.run(&["container", "stop", &candidate]);
                if !stop.ok() {
                    return Err(recovery_required("stop-recovery-candidate".into(), stop));
                }
            }
            self.restore_rollback_runtime(rollback)?;
            return Ok(Container {
                running: rollback_runs(rollback),
                ..rollback_state
            });
        };
        if canonical.spec != self.input.spec {
            return Err(ProcessError::RecoveryRequired {
                step: "inspect-rollback-container".into(),
                reason: "ambiguous_rollback".into(),
                output: rollback_state.output,
            });
        }
        if let Some(waiting) = candidate_state {
            return Err(ProcessError::RecoveryRequired {
                step: "inspect-rollback-container".into(),
                reason: "ambiguous_candidate".into(),
                output: waiting.output,
            });
        }
        if self.running_desired() && !canonical.running {
            self.rollback_published(rollback)?;
            return Ok(Container {
                running: rollback_runs(rollback),
                ..rollback_state
            });
        }
        let name = self.name.clone();
        if !self.running_desired() && canonical.running {
            self.must(&["container", "stop", &name], "stop", stop_failed)?;
        }
        self.must(
            &["container", "rm", "--force", rollback],
            "finalize-rollback-container",
            converge_failed,
        )?;
        Ok(Container {
            running: self.running_desired(),
            ..canonical
        })
    }

    fn remove_candidate(&mut self, state: Option<&Container>) -> Result<(), ProcessError> {
        if state.is_none() {
            return Ok(());
        }
        let candidate = self.candidate.clone();
        self.must(
            &["container", "rm", "--force", &candidate],
            "remove-candidate-container",
            converge_failed,
        )
    }

    /// Writes the environment to a root-only file for `docker container create`, and deletes it afterwards.
    fn create(&mut self, name: &str) -> Result<(), ProcessError> {
        let step = "create-container";
        let scratch = &self.node.scratch;
        let file = scratch.join(format!(
            "process-environment-{:016x}",
            rand::random::<u64>()
        ));
        let written = fs::DirBuilder::new()
            .recursive(true)
            .mode(0o700)
            .create(scratch)
            .and_then(|()| {
                let mut out = fs::OpenOptions::new()
                    .write(true)
                    .create_new(true)
                    .mode(0o600)
                    .open(&file)?;
                for (variable, value) in &self.input.environment {
                    writeln!(out, "{variable}={value}")?;
                }
                out.sync_all()
            });
        if let Err(error) = written {
            let _ = fs::remove_file(&file);
            return Err(converge_failed(step.into(), Output::io(&error)));
        }
        let input = self.input;
        let environment = file.display().to_string();
        let owner = format!("orbit.process.id={}", input.process_id);
        let spec = format!("orbit.process.spec={}", input.spec);
        let mounts: Vec<String> = input
            .mounts
            .iter()
            .map(|mount| {
                let kind = if mount.source.starts_with('/') {
                    "bind"
                } else {
                    "volume"
                };
                let rendered = format!(
                    "type={kind},source={},target={}",
                    mount.source, mount.target
                );
                if mount.read_only {
                    format!("{rendered},readonly")
                } else {
                    rendered
                }
            })
            .collect();
        let mut arguments: Vec<&str> = vec![
            "container",
            "create",
            "--env-file",
            &environment,
            "--name",
            name,
            "--label",
            "orbit.managed=true",
            "--label",
            "orbit.container.kind=process",
            "--label",
            &owner,
            "--label",
            &spec,
            "--restart",
            &input.restart,
        ];
        if let Some(directory) = &input.working_directory {
            arguments.extend(["--workdir", directory]);
        }
        for port in &input.ports {
            arguments.extend(["--publish", port]);
        }
        for mount in &mounts {
            arguments.extend(["--mount", mount]);
        }
        arguments.push(&input.image);
        arguments.extend(input.command.iter().map(String::as_str));
        let created = self.node.docker(&arguments);
        let _ = fs::remove_file(&file);
        if created.ok() {
            Ok(())
        } else {
            Err(converge_failed(step.into(), created))
        }
    }

    fn desired_state(&mut self, state: &Container) -> Result<(), ProcessError> {
        let name = self.name.clone();
        match (self.running_desired(), state.running) {
            (true, false) => self.must(&["container", "start", &name], "start", start_failed),
            (false, true) => self.must(&["container", "stop", &name], "stop", stop_failed),
            _ => Ok(()),
        }
    }

    fn publish(&mut self, previous: &Container) -> Result<(), ProcessError> {
        let name = self.name.clone();
        let candidate = self.candidate.clone();
        let rollback = if previous.running {
            format!("{name}-rollback-running")
        } else {
            format!("{name}-rollback-stopped")
        };
        let stage = self.run(&["container", "rename", &name, &rollback]);
        if !stage.ok() {
            self.remove_candidate_after_failure()?;
            return Err(activation_failed("stage-rollback-container".into(), stage));
        }
        if previous.running {
            let stop = self.run(&["container", "stop", &rollback]);
            if !stop.ok() {
                self.restore_rollback(&rollback)?;
                return Err(activation_failed("stop-previous-container".into(), stop));
            }
        }
        let activation = self.run(&["container", "rename", &candidate, &name]);
        if !activation.ok() {
            self.restore_rollback(&rollback)?;
            return Err(activation_failed("activate-container".into(), activation));
        }
        if self.running_desired() {
            let start = self.run(&["container", "start", &name]);
            if !start.ok() {
                self.rollback_published(&rollback)?;
                return Err(ProcessError::StartFailed {
                    step: "start".into(),
                    restored: true,
                    output: start,
                });
            }
        }
        let finalize = self.run(&["container", "rm", "--force", &rollback]);
        if finalize.ok() {
            return Ok(());
        }
        self.rollback_published(&rollback)?;
        Err(activation_failed("finalize-container".into(), finalize))
    }

    fn restore_rollback(&mut self, rollback: &str) -> Result<(), ProcessError> {
        self.restore_rollback_runtime(rollback)?;
        self.remove_candidate_after_failure()
    }

    fn restore_rollback_runtime(&mut self, rollback: &str) -> Result<(), ProcessError> {
        let name = self.name.clone();
        let state = if rollback_runs(rollback) {
            self.run(&["container", "start", rollback])
        } else {
            self.run(&["container", "stop", rollback])
        };
        if !state.ok() {
            return Err(recovery_required("restore-container-state".into(), state));
        }
        let restore = self.run(&["container", "rename", rollback, &name]);
        if !restore.ok() {
            return Err(recovery_required("restore-container".into(), restore));
        }
        Ok(())
    }

    fn rollback_published(&mut self, rollback: &str) -> Result<(), ProcessError> {
        let name = self.name.clone();
        let candidate = self.candidate.clone();
        let unpublish = self.run(&["container", "rename", &name, &candidate]);
        if !unpublish.ok() {
            return Err(recovery_required("unpublish-container".into(), unpublish));
        }
        let stop = self.run(&["container", "stop", &candidate]);
        if !stop.ok() {
            return Err(recovery_required("stop-candidate-container".into(), stop));
        }
        self.restore_rollback(rollback)
    }

    fn remove_candidate_after_failure(&mut self) -> Result<(), ProcessError> {
        let candidate = self.candidate.clone();
        let cleanup = self.run(&["container", "rm", "--force", &candidate]);
        if !cleanup.ok() && !docker_not_found(&cleanup) {
            return Err(recovery_required(
                "remove-candidate-container".into(),
                cleanup,
            ));
        }
        Ok(())
    }
}

pub fn converge_container<P: Programs>(
    node: &mut Node<P>,
    input: &ContainerInput,
) -> Result<Done, ProcessError> {
    check_container(input)?;
    Converge {
        node,
        input,
        name: input.container.clone(),
        candidate: format!("{}-candidate", input.container),
    }
    .converge()
    .map(|()| Done {})
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LogsInput {
    pub process_id: u64,
    pub runtime: Runtime,
    pub name: String,
    pub lines: u32,
    pub max_bytes: usize,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Logs {
    /// A unit's entries newest first, cut after `max_bytes`; a container's last lines, of which the last
    /// `max_bytes` stay.
    pub output: String,
}

/// `docker container logs` with standard error in the same pipe, in the order the container wrote them; the
/// last `max_bytes` bytes stay.
fn merged_tail(command: &mut Process, max_bytes: usize) -> Result<Output, io::Error> {
    let (mut reader, writer) = io::pipe()?;
    command
        .stdin(Stdio::null())
        .stdout(writer.try_clone()?)
        .stderr(writer);
    let mut child = command.spawn()?;
    // The parent's copies of the writer go with the command's, so the read ends with the child.
    command.stdout(Stdio::null()).stderr(Stdio::null());
    let mut kept = Vec::new();
    let mut buffer = [0u8; 64 * 1024];
    loop {
        let read = reader.read(&mut buffer)?;
        if read == 0 {
            break;
        }
        kept.extend_from_slice(&buffer[..read]);
        if kept.len() > 2 * max_bytes.max(1) {
            kept.drain(..kept.len() - max_bytes);
        }
    }
    if kept.len() > max_bytes {
        kept.drain(..kept.len() - max_bytes);
    }
    let status = child.wait()?;
    Ok(Output {
        exit_code: status.code().unwrap_or(-1),
        stdout: String::from_utf8_lossy(&kept).into_owned(),
        stderr: String::new(),
    })
}

pub fn logs<P: Programs>(node: &mut Node<P>, input: &LogsInput) -> Result<Logs, ProcessError> {
    check_name(input.runtime, input.process_id, &input.name)?;
    if !(1..=1000).contains(&input.lines) {
        return Err(invalid("the line count is 1 to 1,000"));
    }
    if !node.owned(
        input.runtime,
        input.process_id,
        &input.name,
        "logs",
        logs_failed,
    )? {
        return Err(ProcessError::NotFound {
            step: "logs".into(),
        });
    }
    let output = match input.runtime {
        Runtime::Systemd => journalctl::read_newest(
            Path::new(journalctl::JOURNALCTL),
            &input.name,
            input.lines as usize,
            input.max_bytes,
        )
        .map(|bytes| String::from_utf8_lossy(&bytes).into_owned())
        .map_err(|unavailable| {
            logs_failed(
                "logs".into(),
                Output {
                    exit_code: 1,
                    stdout: String::new(),
                    stderr: unavailable.0.to_string(),
                },
            )
        })?,
        Runtime::Docker => {
            let mut command = Process::new("docker");
            command.args([
                "container",
                "logs",
                "--tail",
                &input.lines.to_string(),
                &input.name,
            ]);
            let output = merged_tail(&mut command, input.max_bytes)
                .map_err(|error| logs_failed("logs".into(), Output::io(&error)))?;
            if !output.ok() {
                return Err(logs_failed("logs".into(), output));
            }
            output.stdout
        }
    };
    Ok(Logs { output })
}

macro_rules! process_command {
    ($name:ident, $command:literal, $summary:literal, $mutates:literal, $timeout:literal, $input:ty, $output:ty, $run:expr, $target:expr) => {
        pub struct $name;

        impl Command for $name {
            const NAME: &'static str = $command;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = $mutates;
            const TIMEOUT: Duration = Duration::from_secs($timeout);
            type Input = $input;
            type Output = $output;
            type Error = ProcessError;

            /// Units and containers belong to root.
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Root)
            }

            fn target(input: &$input) -> Option<String> {
                $target(input)
            }

            fn run(input: $input, _: &Context) -> Result<$output, ProcessError> {
                $run(&input)
            }
        }
    };
}

process_command!(
    CheckRelease,
    "processes.check-release",
    "Tell whether a production Process has a selected release.",
    false,
    10,
    ReleaseInput,
    Release,
    |input: &ReleaseInput| Ok(check_release(input)),
    |_: &ReleaseInput| None
);
process_command!(
    ReadStatus,
    "processes.status",
    "Read the state of a Process's unit or container.",
    false,
    30,
    ArtifactInput,
    Status,
    |input: &ArtifactInput| status(&mut Node::system(), input),
    |_: &ArtifactInput| None
);
process_command!(
    ControlProcess,
    "processes.control",
    "Start, stop, or restart a Process's unit or container.",
    true,
    300,
    ControlInput,
    Done,
    |input: &ControlInput| control(&mut Node::system(), input),
    |input: &ControlInput| Some(format!("process:{}", input.process_id))
);
process_command!(
    RemoveProcess,
    "processes.remove",
    "Delete a Process's unit or containers.",
    true,
    300,
    RemoveInput,
    Done,
    |input: &RemoveInput| remove(&mut Node::system(), input),
    |input: &RemoveInput| Some(format!("process:{}", input.process_id))
);
process_command!(
    StageUnit,
    "processes.stage-unit",
    "Write a Process unit candidate that systemd accepts.",
    true,
    60,
    StageInput,
    Staged,
    |input: &StageInput| stage(&mut Node::system(), input),
    |input: &StageInput| Some(format!("process:{}", input.process_id))
);
process_command!(
    ActivateUnit,
    "processes.activate-unit",
    "Publish a Process unit candidate and set its desired state.",
    true,
    600,
    ActivateInput,
    Activated,
    |input: &ActivateInput| activate(&mut Node::system(), input),
    |input: &ActivateInput| Some(format!("process:{}", input.process_id))
);
process_command!(
    ConvergeContainer,
    "processes.converge-container",
    "Create or replace a Process's container and set its desired state.",
    true,
    900,
    ContainerInput,
    Done,
    |input: &ContainerInput| converge_container(&mut Node::system(), input),
    |input: &ContainerInput| Some(format!("process:{}", input.process_id))
);
process_command!(
    ReadLogs,
    "processes.logs",
    "Read the newest lines of a Process's unit or container.",
    false,
    60,
    LogsInput,
    Logs,
    |input: &LogsInput| logs(&mut Node::system(), input),
    |_: &LogsInput| None
);

#[cfg(test)]
#[path = "processes_tests.rs"]
mod tests;

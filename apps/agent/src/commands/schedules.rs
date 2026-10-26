//! A Schedule's systemd units on its Node: `schedules.install` writes its script, service, and timer and sets the
//! timer to the desired state, restoring what it replaced on failure; `schedules.activate` enables the timer;
//! `schedules.run` starts one run; `schedules.logs` reads its journal; `schedules.remove` deletes its units. Each
//! acts only on files that carry the Schedule's own marker.

mod runtime;
mod spec;

#[cfg(test)]
mod tests;
#[cfg(test)]
mod tls_tests;
pub use doctor::{InspectSchedule, ScheduleOrphans};
pub use runtime::service;

use super::caddy::chown;
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum ScheduleError {
        TargetUnavailable {} => "schedules.target_unavailable", "The Schedule's user cannot enter its working directory.";
        ArtifactConflict {} => "schedules.artifact_conflict", "A Schedule file exists that Orbit did not write.";
        CalendarInvalid {} => "schedules.calendar_invalid", "systemd does not accept the Schedule calendar.";
        RollbackFailed {} => "schedules.rollback_failed", "The step failed and restoring the previous state failed too.";
        Failed { step: String } => "schedules.failed", "A Schedule step failed on the Node.";
        Busy {} => "schedules.run_active", "A run of the Schedule is still active.";
    }
}

fn failed(step: &str) -> ScheduleError {
    ScheduleError::Failed { step: step.into() }
}

/// Production paths are fixed. Tests exercise the same operations in an isolated filesystem.
struct Host {
    root: PathBuf,
    uid: u32,
    gid: u32,
    systemctl: PathBuf,
    analyze: PathBuf,
}

impl Host {
    fn system() -> Self {
        Self {
            root: "/".into(),
            uid: 0,
            gid: 0,
            systemctl: "/usr/bin/systemctl".into(),
            analyze: "/usr/bin/systemd-analyze".into(),
        }
    }

    fn path(&self, path: &str) -> PathBuf {
        self.root.join(path.trim_start_matches('/'))
    }

    fn paths(&self, id: &str) -> Result<Paths, ScheduleError> {
        let mut paths = paths(id)?;
        paths.script = self.path(paths.script.to_str().unwrap());
        paths.service = self.path(paths.service.to_str().unwrap());
        paths.timer = self.path(paths.timer.to_str().unwrap());
        Ok(paths)
    }
}

struct Paths {
    script: PathBuf,
    service: PathBuf,
    timer: PathBuf,
    service_unit: String,
    timer_unit: String,
}

fn paths(id: &str) -> Result<Paths, ScheduleError> {
    if !spec::valid_id(id) {
        return Err(failed("schedule-id"));
    }
    Ok(Paths {
        script: PathBuf::from(format!("/etc/orbit/schedules/{id}.sh")),
        service: PathBuf::from(format!("/etc/systemd/system/orbit-schedule-{id}.service")),
        timer: PathBuf::from(format!("/etc/systemd/system/orbit-schedule-{id}.timer")),
        service_unit: format!("orbit-schedule-{id}.service"),
        timer_unit: format!("orbit-schedule-{id}.timer"),
    })
}

fn systemctl(host: &Host, arguments: &[&str]) -> bool {
    Process::new(&host.systemctl)
        .args(arguments)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok_and(|status| status.success())
}

/// `systemctl is-enabled` or `is-active` of a unit, as it prints it.
fn unit_state(host: &Host, query: &str, unit: &str) -> String {
    Process::new(&host.systemctl)
        .args([query, unit])
        .stdin(Stdio::null())
        .stderr(Stdio::null())
        .output()
        .map(|output| String::from_utf8_lossy(&output.stdout).trim().to_string())
        .unwrap_or_default()
}

fn group_id(name: &str) -> Option<u32> {
    super::caddy::group_id(name)
}

/// Whether the file is Orbit's: absent is fine; present must be a regular file with the mode, owner, and marker.
fn owned(
    host: &Host,
    path: &Path,
    group: u32,
    mode: u32,
    marker: &str,
) -> Result<bool, ScheduleError> {
    let metadata = match fs::symlink_metadata(path) {
        Ok(metadata) => metadata,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(false),
        Err(_) => return Err(failed("inspect-artifacts")),
    };
    let ours = metadata.is_file()
        && metadata.uid() == host.uid
        && metadata.gid() == group
        && metadata.mode() & 0o7777 == mode
        && fs::read_to_string(path).is_ok_and(|text| text.lines().any(|line| line == marker));
    if ours {
        Ok(true)
    } else {
        Err(ScheduleError::ArtifactConflict {})
    }
}

/// `/etc/orbit` is root's, passable by every account, and nothing else.
fn shared_orbit_directory(host: &Host) -> Result<(), ScheduleError> {
    let directory = host.path("/etc/orbit");
    match fs::symlink_metadata(&directory) {
        Ok(metadata)
            if !metadata.is_dir() || metadata.uid() != host.uid || metadata.gid() != host.gid =>
        {
            return Err(ScheduleError::ArtifactConflict {})
        }
        Ok(_) => {}
        Err(_) => fs::create_dir(&directory).map_err(|_| ScheduleError::ArtifactConflict {})?,
    }
    fs::set_permissions(directory, fs::Permissions::from_mode(0o711))
        .map_err(|_| ScheduleError::ArtifactConflict {})
}

fn write(host: &Host, path: &Path, content: &str, group: u32, mode: u32) -> std::io::Result<()> {
    use std::io::Write;
    let staged = path.with_extension(format!("orbit-new-{:016x}", rand::random::<u64>()));
    let result = (|| {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&staged)?;
        file.write_all(content.as_bytes())?;
        chown(&staged, host.uid, group)?;
        file.set_permissions(fs::Permissions::from_mode(mode))?;
        fs::rename(&staged, path)
    })();
    if result.is_err() {
        let _ = fs::remove_file(staged);
    }
    result
}

fn runnable(user: &str, directory: &Path) -> Result<(), ScheduleError> {
    let mut command = Process::new("/usr/bin/test");
    command
        .arg("-d")
        .arg(directory)
        .current_dir("/")
        .stdin(Stdio::null());
    if identity::drop_to(&mut command, user).is_err()
        || !command.status().is_ok_and(|status| status.success())
    {
        return Err(ScheduleError::TargetUnavailable {});
    }
    Ok(())
}

#[derive(schemars::JsonSchema, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InstallInput {
    pub id: String,
    pub user: String,
    pub working_directory: PathBuf,
    pub group: String,
    /// `enabled` or `disabled`.
    pub desired: String,
    pub calendar: String,
    pub home: String,
    pub shell: String,
    pub login_shell: bool,
    pub command: String,
    pub timeout_seconds: u32,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Done {}

/// What an install replaces, so a failure can put it back.
struct Previous {
    script: Option<String>,
    service: Option<String>,
    timer: Option<String>,
    enabled: bool,
    active: bool,
}

fn restore(host: &Host, paths: &Paths, previous: &Previous, group: u32) -> bool {
    systemctl(host, &["disable", "--now", &paths.timer_unit]);
    let mut restored = true;
    for (path, content, file_group, mode) in [
        (&paths.script, &previous.script, group, 0o750),
        (&paths.service, &previous.service, host.gid, 0o644),
        (&paths.timer, &previous.timer, host.gid, 0o644),
    ] {
        restored &= match content {
            Some(content) => write(host, path, content, file_group, mode).is_ok(),
            None => fs::remove_file(path).is_ok() || !path.exists(),
        };
    }
    restored &= systemctl(host, &["daemon-reload"]);
    if previous.timer.is_some() {
        restored &= systemctl(
            host,
            &[
                if previous.enabled {
                    "enable"
                } else {
                    "disable"
                },
                &paths.timer_unit,
            ],
        );
        restored &= systemctl(
            host,
            &[
                if previous.active { "start" } else { "stop" },
                &paths.timer_unit,
            ],
        );
    } else {
        systemctl(host, &["reset-failed", &paths.timer_unit]);
    }
    restored
}

fn settle(host: &Host, timer: &str, enabled: bool) -> bool {
    if enabled {
        systemctl(host, &["enable", "--now", timer])
            && unit_state(host, "is-enabled", timer) == "enabled"
            && unit_state(host, "is-active", timer) == "active"
    } else {
        systemctl(host, &["disable", "--now", timer])
            && unit_state(host, "is-enabled", timer) == "disabled"
            && matches!(
                unit_state(host, "is-active", timer).as_str(),
                "inactive" | "failed"
            )
    }
}

pub fn install(input: &InstallInput) -> Result<Done, ScheduleError> {
    install_at(&Host::system(), input)
}

fn install_at(host: &Host, input: &InstallInput) -> Result<Done, ScheduleError> {
    let paths = host.paths(&input.id)?;
    runnable(&input.user, &input.working_directory)?;
    let group = group_id(&input.group).ok_or_else(|| failed("schedule-group"))?;
    let artifacts = spec::render(input)?;
    let read = |path: &Path,
                file_group: u32,
                mode: u32,
                marker: String|
     -> Result<Option<String>, ScheduleError> {
        Ok(if owned(host, path, file_group, mode, &marker)? {
            Some(fs::read_to_string(path).map_err(|_| failed("inspect-artifacts"))?)
        } else {
            None
        })
    };
    let previous = Previous {
        script: read(
            &paths.script,
            group,
            0o750,
            format!("# X-Orbit-Schedule-ID={}", input.id),
        )?,
        service: read(
            &paths.service,
            host.gid,
            0o644,
            format!("X-Orbit-Schedule-ID={}", input.id),
        )?,
        timer: read(
            &paths.timer,
            host.gid,
            0o644,
            format!("X-Orbit-Schedule-ID={}", input.id),
        )?,
        enabled: unit_state(host, "is-enabled", &paths.timer_unit) == "enabled",
        active: unit_state(host, "is-active", &paths.timer_unit) == "active",
    };
    let calendar_valid = Process::new(&host.analyze)
        .args(["calendar", &input.calendar])
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok_and(|status| status.success());
    if !calendar_valid {
        return Err(ScheduleError::CalendarInvalid {});
    }
    shared_orbit_directory(host)?;
    let candidates = host.path("/etc/orbit/schedule-candidates");
    let result = (|| -> Result<(), ScheduleError> {
        for directory in [host.path("/etc/orbit/schedules"), candidates.clone()] {
            fs::create_dir_all(&directory).map_err(|_| failed("install"))?;
            fs::set_permissions(directory, fs::Permissions::from_mode(0o755))
                .map_err(|_| failed("install"))?;
        }
        let service = candidates.join(&paths.service_unit);
        let timer = candidates.join(&paths.timer_unit);
        write(host, &service, &artifacts.service, host.gid, 0o644)
            .map_err(|_| failed("install"))?;
        write(host, &timer, &artifacts.timer, host.gid, 0o644).map_err(|_| failed("install"))?;
        systemctl(host, &["disable", "--now", &paths.timer_unit]);
        write(host, &paths.script, &artifacts.script, group, 0o750)
            .map_err(|_| failed("install"))?;
        let verified = Process::new(&host.analyze)
            .arg("verify")
            .args([&service, &timer])
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .is_ok_and(|status| status.success());
        if !verified {
            return Err(failed("verify"));
        }
        fs::rename(&service, &paths.service).map_err(|_| failed("install"))?;
        fs::rename(&timer, &paths.timer).map_err(|_| failed("install"))?;
        if !systemctl(host, &["daemon-reload"])
            || !settle(host, &paths.timer_unit, input.desired == "enabled")
        {
            return Err(failed("install"));
        }
        Ok(())
    })();
    if result.is_err() {
        for candidate in [&paths.service_unit, &paths.timer_unit] {
            let _ = fs::remove_file(candidates.join(candidate));
        }
        if !restore(host, &paths, &previous, group) {
            return Err(ScheduleError::RollbackFailed {});
        }
    }
    result.map(|()| Done {})
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TargetInput {
    pub id: String,
    pub user: String,
    pub working_directory: PathBuf,
}

pub fn activate(input: &TargetInput) -> Result<Done, ScheduleError> {
    activate_at(&Host::system(), input)
}

fn activate_at(host: &Host, input: &TargetInput) -> Result<Done, ScheduleError> {
    let paths = host.paths(&input.id)?;
    runnable(&input.user, &input.working_directory)?;
    let enabled = unit_state(host, "is-enabled", &paths.timer_unit) == "enabled";
    let active = unit_state(host, "is-active", &paths.timer_unit) == "active";
    if settle(host, &paths.timer_unit, true) {
        return Ok(Done {});
    }
    let restored = systemctl(
        host,
        &[
            if enabled { "enable" } else { "disable" },
            &paths.timer_unit,
        ],
    ) && systemctl(
        host,
        &[if active { "start" } else { "stop" }, &paths.timer_unit],
    );
    Err(if restored {
        failed("activate")
    } else {
        ScheduleError::RollbackFailed {}
    })
}

pub fn run(input: &TargetInput) -> Result<Done, ScheduleError> {
    run_at(&Host::system(), input)
}

fn run_at(host: &Host, input: &TargetInput) -> Result<Done, ScheduleError> {
    let paths = host.paths(&input.id)?;
    runnable(&input.user, &input.working_directory)?;
    if systemctl(host, &["start", "--no-block", &paths.service_unit]) {
        Ok(Done {})
    } else {
        Err(failed("run"))
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LogsInput {
    pub id: String,
    pub lines: u32,
    pub max_bytes: usize,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Logs {
    /// The newest entries, oldest first.
    pub output: String,
    pub truncated: bool,
}

pub fn logs(input: &LogsInput) -> Result<Logs, ScheduleError> {
    let paths = paths(&input.id)?;
    if !(1..=1000).contains(&input.lines) {
        return Err(failed("logs-validation"));
    }
    let output = Process::new("journalctl")
        .args([
            "--unit",
            &paths.service_unit,
            "--lines",
            &input.lines.to_string(),
            "--no-pager",
            "--output",
            "cat",
            "--reverse",
        ])
        .stdin(Stdio::null())
        .stderr(Stdio::null())
        .output()
        .map_err(|_| failed("logs"))?;
    if !output.status.success() {
        return Err(failed("logs"));
    }
    let mut text = String::from_utf8_lossy(&output.stdout).into_owned();
    let truncated = text.len() > input.max_bytes;
    if truncated {
        let mut end = input.max_bytes;
        while !text.is_char_boundary(end) {
            end -= 1;
        }
        text.truncate(end);
        text.truncate(text.rfind('\n').map_or(0, |index| index + 1));
    }
    let entries: Vec<&str> = text
        .trim_end_matches('\n')
        .split('\n')
        .filter(|_| !text.is_empty())
        .collect();
    let output = if entries.is_empty() {
        String::new()
    } else {
        entries.into_iter().rev().collect::<Vec<_>>().join("\n") + "\n"
    };
    Ok(Logs { output, truncated })
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RemoveInput {
    pub id: String,
    pub group: String,
    /// A cascade removes the Schedule while a run is active; a standalone removal waits for it.
    pub cascade: bool,
}

pub fn remove(input: &RemoveInput) -> Result<Done, ScheduleError> {
    remove_at(&Host::system(), input)
}

fn remove_at(host: &Host, input: &RemoveInput) -> Result<Done, ScheduleError> {
    let paths = host.paths(&input.id)?;
    let group = group_id(&input.group).unwrap_or(u32::MAX);
    owned(
        host,
        &paths.script,
        group,
        0o750,
        &format!("# X-Orbit-Schedule-ID={}", input.id),
    )?;
    owned(
        host,
        &paths.service,
        host.gid,
        0o644,
        &format!("X-Orbit-Schedule-ID={}", input.id),
    )?;
    let timer_present = owned(
        host,
        &paths.timer,
        host.gid,
        0o644,
        &format!("X-Orbit-Schedule-ID={}", input.id),
    )?;
    if !systemctl(host, &["disable", "--now", &paths.timer_unit]) && timer_present {
        return Err(failed("remove"));
    }
    if !input.cascade {
        match unit_state(host, "is-active", &paths.service_unit).as_str() {
            "" => return Err(failed("inspect-service")),
            "inactive" | "failed" | "unknown" => {}
            _ => return Err(ScheduleError::Busy {}),
        }
    }
    for path in [&paths.timer, &paths.service, &paths.script] {
        if fs::remove_file(path).is_err() && path.exists() {
            return Err(failed("remove"));
        }
    }
    if !systemctl(host, &["daemon-reload"]) {
        return Err(failed("remove"));
    }
    systemctl(host, &["reset-failed", &paths.service_unit]);
    systemctl(host, &["reset-failed", &paths.timer_unit]);
    Ok(Done {})
}

macro_rules! schedule_command {
    ($name:ident, $command:literal, $summary:literal, $mutates:literal, $timeout:literal, $input:ty, $output:ty, $run:path, $target:expr) => {
        pub struct $name;

        impl Command for $name {
            const NAME: &'static str = $command;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = $mutates;
            const TIMEOUT: Duration = Duration::from_secs($timeout);
            type Input = $input;
            type Output = $output;
            type Error = ScheduleError;

            /// Schedule units belong to root and run as the Schedule's user.
            fn privilege(platform: Platform) -> Option<Privilege> {
                crate::commands::linux_only(platform, Privilege::Root)
            }

            fn target(input: &$input) -> Option<String> {
                $target(input)
            }

            fn run(input: $input, _: &Context) -> Result<$output, ScheduleError> {
                $run(&input)
            }
        }
    };
}

schedule_command!(
    InstallSchedule,
    "schedules.install",
    "Write a Schedule's units and set its timer.",
    true,
    90,
    InstallInput,
    Done,
    install,
    |input: &InstallInput| Some(format!("schedule:{}", input.id))
);
schedule_command!(
    ActivateSchedule,
    "schedules.activate",
    "Enable and start a Schedule's timer.",
    true,
    45,
    TargetInput,
    Done,
    activate,
    |input: &TargetInput| Some(format!("schedule:{}", input.id))
);
schedule_command!(
    RunSchedule,
    "schedules.run",
    "Start one run of a Schedule.",
    true,
    15,
    TargetInput,
    Done,
    run,
    |_: &TargetInput| None
);
schedule_command!(
    ReadScheduleLogs,
    "schedules.logs",
    "Read a Schedule's newest journal entries.",
    false,
    10,
    LogsInput,
    Logs,
    logs,
    |_: &LogsInput| None
);
schedule_command!(
    RemoveSchedule,
    "schedules.remove",
    "Delete a Schedule's units.",
    true,
    60,
    RemoveInput,
    Done,
    remove,
    |input: &RemoveInput| Some(format!("schedule:{}", input.id))
);

mod doctor;

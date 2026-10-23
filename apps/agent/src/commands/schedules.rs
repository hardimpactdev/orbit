//! A Schedule's systemd units on its Node: `schedules.install` writes its script, service, and timer and sets the
//! timer to the desired state, restoring what it replaced on failure; `schedules.activate` enables the timer;
//! `schedules.run` starts one run; `schedules.logs` reads its journal; `schedules.remove` deletes its units. Each
//! acts only on files that carry the Schedule's own marker.

use super::caddy::chown;
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    os::unix::fs::{MetadataExt, PermissionsExt},
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

struct Paths {
    script: PathBuf,
    service: PathBuf,
    timer: PathBuf,
    service_unit: String,
    timer_unit: String,
}

fn paths(id: &str) -> Result<Paths, ScheduleError> {
    if id.is_empty()
        || !id
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() || byte == b'-')
    {
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

fn systemctl(arguments: &[&str]) -> bool {
    Process::new("systemctl")
        .args(arguments)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok_and(|status| status.success())
}

/// `systemctl is-enabled` or `is-active` of a unit, as it prints it.
fn unit_state(query: &str, unit: &str) -> String {
    Process::new("systemctl")
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
fn owned(path: &Path, group: u32, mode: u32, marker: &str) -> Result<bool, ScheduleError> {
    let Ok(metadata) = fs::symlink_metadata(path) else {
        return Ok(false);
    };
    let ours = metadata.is_file()
        && metadata.uid() == 0
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
fn shared_orbit_directory() -> Result<(), ScheduleError> {
    let directory = Path::new("/etc/orbit");
    match fs::symlink_metadata(directory) {
        Ok(metadata) if !metadata.is_dir() || metadata.uid() != 0 || metadata.gid() != 0 => {
            return Err(ScheduleError::ArtifactConflict {})
        }
        Ok(_) => {}
        Err(_) => fs::create_dir(directory).map_err(|_| ScheduleError::ArtifactConflict {})?,
    }
    fs::set_permissions(directory, fs::Permissions::from_mode(0o711))
        .map_err(|_| ScheduleError::ArtifactConflict {})
}

fn write(path: &Path, content: &str, group: u32, mode: u32) -> std::io::Result<()> {
    let staged = path.with_extension("orbit-new");
    fs::write(&staged, content)?;
    chown(&staged, 0, group)?;
    fs::set_permissions(&staged, fs::Permissions::from_mode(mode))?;
    fs::rename(&staged, path)
}

fn runnable(user: &str, directory: &Path) -> Result<(), ScheduleError> {
    let mut command = Process::new("test");
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

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InstallInput {
    pub id: String,
    pub user: String,
    pub working_directory: PathBuf,
    pub group: String,
    /// `enabled` or `disabled`.
    pub desired: String,
    pub calendar: String,
    pub script: String,
    pub service: String,
    pub timer: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Done {}

/// What an install replaces, so a failure can put it back.
struct Previous {
    script: Option<String>,
    service: Option<String>,
    timer: Option<String>,
    enabled: bool,
    active: bool,
}

fn restore(paths: &Paths, previous: &Previous, group: u32) -> bool {
    systemctl(&["disable", "--now", &paths.timer_unit]);
    let mut restored = true;
    for (path, content, file_group, mode) in [
        (&paths.script, &previous.script, group, 0o750),
        (&paths.service, &previous.service, 0, 0o644),
        (&paths.timer, &previous.timer, 0, 0o644),
    ] {
        restored &= match content {
            Some(content) => write(path, content, file_group, mode).is_ok(),
            None => fs::remove_file(path).is_ok() || !path.exists(),
        };
    }
    restored &= systemctl(&["daemon-reload"]);
    if previous.timer.is_some() {
        restored &= systemctl(&[
            if previous.enabled {
                "enable"
            } else {
                "disable"
            },
            &paths.timer_unit,
        ]);
        restored &= systemctl(&[
            if previous.active { "start" } else { "stop" },
            &paths.timer_unit,
        ]);
    } else {
        systemctl(&["reset-failed", &paths.timer_unit]);
    }
    restored
}

fn settle(timer: &str, enabled: bool) -> bool {
    if enabled {
        systemctl(&["enable", "--now", timer])
            && unit_state("is-enabled", timer) == "enabled"
            && unit_state("is-active", timer) == "active"
    } else {
        systemctl(&["disable", "--now", timer])
            && unit_state("is-enabled", timer) != "enabled"
            && unit_state("is-active", timer) != "active"
    }
}

pub fn install(input: &InstallInput) -> Result<Done, ScheduleError> {
    let paths = paths(&input.id)?;
    runnable(&input.user, &input.working_directory)?;
    let group = group_id(&input.group).ok_or_else(|| failed("schedule-group"))?;
    shared_orbit_directory()?;
    let read = |path: &Path,
                file_group: u32,
                mode: u32,
                marker: String|
     -> Result<Option<String>, ScheduleError> {
        Ok(if owned(path, file_group, mode, &marker)? {
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
            0,
            0o644,
            format!("X-Orbit-Schedule-ID={}", input.id),
        )?,
        timer: read(
            &paths.timer,
            0,
            0o644,
            format!("X-Orbit-Schedule-ID={}", input.id),
        )?,
        enabled: unit_state("is-enabled", &paths.timer_unit) == "enabled",
        active: unit_state("is-active", &paths.timer_unit) == "active",
    };
    let calendar_valid = Process::new("systemd-analyze")
        .args(["calendar", &input.calendar])
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok_and(|status| status.success());
    if !calendar_valid {
        return Err(ScheduleError::CalendarInvalid {});
    }
    let candidates = Path::new("/etc/orbit/schedule-candidates");
    let result = (|| -> Result<(), ScheduleError> {
        for directory in [Path::new("/etc/orbit/schedules"), candidates] {
            fs::create_dir_all(directory).map_err(|_| failed("install"))?;
            fs::set_permissions(directory, fs::Permissions::from_mode(0o755))
                .map_err(|_| failed("install"))?;
        }
        let service = candidates.join(&paths.service_unit);
        let timer = candidates.join(&paths.timer_unit);
        write(&service, &input.service, 0, 0o644).map_err(|_| failed("install"))?;
        write(&timer, &input.timer, 0, 0o644).map_err(|_| failed("install"))?;
        systemctl(&["disable", "--now", &paths.timer_unit]);
        write(&paths.script, &input.script, group, 0o750).map_err(|_| failed("install"))?;
        let verified = Process::new("systemd-analyze")
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
        if !systemctl(&["daemon-reload"]) || !settle(&paths.timer_unit, input.desired == "enabled")
        {
            return Err(failed("install"));
        }
        Ok(())
    })();
    if result.is_err() {
        for candidate in [&paths.service_unit, &paths.timer_unit] {
            let _ = fs::remove_file(candidates.join(candidate));
        }
        if !restore(&paths, &previous, group) {
            return Err(ScheduleError::RollbackFailed {});
        }
    }
    result.map(|()| Done {})
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TargetInput {
    pub id: String,
    pub user: String,
    pub working_directory: PathBuf,
}

pub fn activate(input: &TargetInput) -> Result<Done, ScheduleError> {
    let paths = paths(&input.id)?;
    runnable(&input.user, &input.working_directory)?;
    let enabled = unit_state("is-enabled", &paths.timer_unit) == "enabled";
    let active = unit_state("is-active", &paths.timer_unit) == "active";
    if settle(&paths.timer_unit, true) {
        return Ok(Done {});
    }
    let restored = systemctl(&[
        if enabled { "enable" } else { "disable" },
        &paths.timer_unit,
    ]) && systemctl(&[if active { "start" } else { "stop" }, &paths.timer_unit]);
    Err(if restored {
        failed("activate")
    } else {
        ScheduleError::RollbackFailed {}
    })
}

pub fn run(input: &TargetInput) -> Result<Done, ScheduleError> {
    let paths = paths(&input.id)?;
    runnable(&input.user, &input.working_directory)?;
    if systemctl(&["start", "--no-block", &paths.service_unit]) {
        Ok(Done {})
    } else {
        Err(failed("run"))
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LogsInput {
    pub id: String,
    pub lines: u32,
    pub max_bytes: usize,
}

#[derive(Debug, Serialize, PartialEq)]
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

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RemoveInput {
    pub id: String,
    pub group: String,
    /// A cascade removes the Schedule while a run is active; a standalone removal waits for it.
    pub cascade: bool,
}

pub fn remove(input: &RemoveInput) -> Result<Done, ScheduleError> {
    let paths = paths(&input.id)?;
    let group = group_id(&input.group).unwrap_or(u32::MAX);
    owned(
        &paths.script,
        group,
        0o750,
        &format!("# X-Orbit-Schedule-ID={}", input.id),
    )?;
    owned(
        &paths.service,
        0,
        0o644,
        &format!("X-Orbit-Schedule-ID={}", input.id),
    )?;
    owned(
        &paths.timer,
        0,
        0o644,
        &format!("X-Orbit-Schedule-ID={}", input.id),
    )?;
    systemctl(&["disable", "--now", &paths.timer_unit]);
    if !input.cascade
        && !matches!(
            unit_state("is-active", &paths.service_unit).as_str(),
            "" | "inactive" | "failed" | "unknown"
        )
    {
        return Err(ScheduleError::Busy {});
    }
    for path in [&paths.timer, &paths.service, &paths.script] {
        if fs::remove_file(path).is_err() && path.exists() {
            return Err(failed("remove"));
        }
    }
    systemctl(&["daemon-reload"]);
    systemctl(&["reset-failed", &paths.service_unit]);
    systemctl(&["reset-failed", &paths.timer_unit]);
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
                super::linux_only(platform, Privilege::Root)
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_schedule_id_names_only_its_own_units() {
        let paths = paths("0b0e-42").unwrap();
        assert_eq!(paths.timer_unit, "orbit-schedule-0b0e-42.timer");
        assert_eq!(
            paths.script,
            PathBuf::from("/etc/orbit/schedules/0b0e-42.sh")
        );
        assert!(super::paths("../x").is_err() && super::paths("").is_err());
    }

    #[test]
    fn a_foreign_file_at_a_schedule_path_is_a_conflict() {
        let file =
            std::env::temp_dir().join(format!("orbit-schedule-{:016x}", rand::random::<u64>()));
        assert_eq!(owned(&file, 0, 0o644, "X-Orbit-Schedule-ID=1"), Ok(false));
        fs::write(&file, "X-Orbit-Schedule-ID=1\n").unwrap();
        assert_eq!(
            owned(&file, 0, 0o644, "X-Orbit-Schedule-ID=1"),
            Err(ScheduleError::ArtifactConflict {})
        );
        let _ = fs::remove_file(&file);
    }
}

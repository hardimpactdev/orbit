use super::*;
use std::collections::BTreeSet;

#[derive(Debug, Serialize)]
pub struct Inspection {
    artifacts_present: bool,
    permissions_match: bool,
    specification_match: bool,
    timer_state_match: bool,
    calendar_match: bool,
    execution_context_match: bool,
    completion_callback_match: bool,
}

fn inspect(input: &InstallInput) -> Result<Inspection, ScheduleError> {
    inspect_at(&Host::system(), input)
}

pub(super) fn inspect_at(host: &Host, input: &InstallInput) -> Result<Inspection, ScheduleError> {
    let paths = host.paths(&input.id)?;
    let expected = spec::render(input)?;
    let mut present = true;
    for path in [&paths.script, &paths.service, &paths.timer] {
        match fs::symlink_metadata(path) {
            Ok(metadata) => present &= metadata.is_file(),
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => present = false,
            Err(_) => return Err(failed("inspect-artifacts")),
        }
    }
    let mut result = Inspection {
        artifacts_present: present,
        permissions_match: false,
        specification_match: false,
        timer_state_match: false,
        calendar_match: false,
        execution_context_match: false,
        completion_callback_match: false,
    };
    if !present {
        return Ok(result);
    }
    let contents = |path: &Path| fs::read_to_string(path).map_err(|_| failed("inspect-artifacts"));
    let script = contents(&paths.script)?;
    let service = contents(&paths.service)?;
    let timer = contents(&paths.timer)?;
    let ownership = |path: &Path, group, mode, marker: String| -> Result<bool, ScheduleError> {
        match owned(host, path, group, mode, &marker) {
            Err(ScheduleError::ArtifactConflict {}) => Ok(false),
            other => other,
        }
    };
    let group = group_id(&input.group);
    result.permissions_match = group.is_some()
        && ownership(
            &paths.script,
            group.unwrap(),
            0o750,
            format!("# X-Orbit-Schedule-ID={}", input.id),
        )?
        && ownership(
            &paths.service,
            host.gid,
            0o644,
            format!("X-Orbit-Schedule-ID={}", input.id),
        )?
        && ownership(
            &paths.timer,
            host.gid,
            0o644,
            format!("X-Orbit-Schedule-ID={}", input.id),
        )?
        && ["-r", "-x"].into_iter().all(|flag| {
            let mut command = Process::new("/usr/bin/test");
            command
                .arg(flag)
                .arg(&paths.script)
                .current_dir("/")
                .stdin(Stdio::null());
            identity::drop_to(&mut command, &input.user).is_ok()
                && command.status().is_ok_and(|s| s.success())
        });
    result.specification_match =
        script == expected.script && service == expected.service && timer == expected.timer;
    let enabled = unit_state(host, "is-enabled", &paths.timer_unit);
    let active = unit_state(host, "is-active", &paths.timer_unit);
    if enabled.is_empty() || active.is_empty() {
        return Err(failed("inspect-timer"));
    }
    result.timer_state_match = if input.desired == "enabled" {
        enabled == "enabled" && active == "active"
    } else {
        enabled != "enabled" && active != "active"
    };
    let lines = |text: &str, prefixes: &[&str]| -> Vec<String> {
        text.lines()
            .filter(|line| prefixes.iter().any(|prefix| line.starts_with(prefix)))
            .map(str::to_owned)
            .collect()
    };
    result.calendar_match =
        lines(&timer, &["OnCalendar="]) == lines(&expected.timer, &["OnCalendar="]);
    let context = &[
        "User=",
        "Group=",
        "WorkingDirectory=",
        "Environment=",
        "ExecStart=",
    ];
    result.execution_context_match = lines(&service, context) == lines(&expected.service, context)
        && lines(&script, &["# X-Orbit-Execution-Context="])
            == lines(&expected.script, &["# X-Orbit-Execution-Context="]);
    result.completion_callback_match =
        lines(&service, &["ExecStopPost="]) == lines(&expected.service, &["ExecStopPost="]);
    Ok(result)
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct OrphanInput {
    known_ids: Vec<String>,
}
#[derive(Debug, Serialize)]
pub struct Orphans {
    pub ids: Vec<String>,
}

fn orphans(input: &OrphanInput) -> Result<Orphans, ScheduleError> {
    orphans_at(&Host::system(), input)
}

pub(super) fn orphans_at(host: &Host, input: &OrphanInput) -> Result<Orphans, ScheduleError> {
    let mut ids = BTreeSet::new();
    for (directory, command_files) in [
        ("/etc/orbit/schedules", true),
        ("/etc/systemd/system", false),
    ] {
        let entries = match fs::read_dir(host.path(directory)) {
            Ok(entries) => entries,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => continue,
            Err(_) => return Err(failed("inspect-artifacts")),
        };
        for entry in entries {
            let entry = entry.map_err(|_| failed("inspect-artifacts"))?;
            if !entry
                .file_type()
                .map_err(|_| failed("inspect-artifacts"))?
                .is_file()
            {
                continue;
            }
            let name = entry.file_name();
            let Some(name) = name.to_str() else {
                continue;
            };
            let id = if command_files {
                name.strip_suffix(".sh")
            } else {
                name.strip_prefix("orbit-schedule-").and_then(|name| {
                    name.strip_suffix(".service")
                        .or_else(|| name.strip_suffix(".timer"))
                })
            };
            if let Some(id) = id
                .filter(|id| spec::valid_id(id) && !input.known_ids.iter().any(|known| known == id))
            {
                ids.insert(id.to_owned());
            }
        }
    }
    Ok(Orphans {
        ids: ids.into_iter().collect(),
    })
}

schedule_command!(
    InspectSchedule,
    "doctor.inspect-schedule",
    "Inspect a Schedule's artifacts and timer.",
    false,
    15,
    InstallInput,
    Inspection,
    inspect,
    |_: &InstallInput| None
);
schedule_command!(
    ScheduleOrphans,
    "doctor.schedule-orphans",
    "Find Schedule artifacts without recorded intent.",
    false,
    15,
    OrphanInput,
    Orphans,
    orphans,
    |_: &OrphanInput| None
);

//! The process family of the executor (ADR 0271): a Process's run state and a Schedule's Node timer.
//!
//! - [`ensure_state`] (`process.ensure_state`): converges one host Process, a systemd unit or a Docker container, to running
//!   or stopped and, for a unit, to its boot policy. One action for start and stop (and later restart); the Gateway names the
//!   desired state. It reads the state back and fails with `process.state_mismatch` when it differs.
//! - [`ensure_timer`] (`schedule.ensure_timer`): enables and starts a Schedule's own Node timer, then reads back its
//!   enablement, its state and its next elapse.
//!
//! A unit inside an Instance container is not covered: Swamp moves the container's app account with a script first, which no
//! fixed argv reproduces, so the Gateway keeps that shape on Swamp.
//!
//! Every name comes from the admitted intent and is checked against the Process or Schedule it belongs to; every command is
//! fixed here. Nothing reaches the Node as a script.

#[cfg(test)]
mod tests;

use std::process::Command;

const TIMEOUT: &str = "/usr/bin/timeout";
const SYSTEMCTL: &str = "/usr/bin/systemctl";
const DOCKER: &str = "/usr/bin/docker";

/// Why the primitive failed: a stable code, and a detail for the executor's log only.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Failure {
    /// The code the receipt carries.
    pub code: &'static str,
    /// What the executor logs. Never sent to the Gateway.
    pub detail: String,
}

impl Failure {
    fn new(code: &'static str, detail: impl Into<String>) -> Self {
        Self {
            code,
            detail: detail.into(),
        }
    }
}

/// What the primitives need from the Node. The Node itself in production; a double in the tests.
pub trait System {
    /// Runs a command with a time limit and answers its standard output.
    ///
    /// # Errors
    ///
    /// The command's standard error when it fails or cannot start.
    fn run(&self, seconds: u32, argv: &[&str]) -> Result<String, String>;
}

/// The Node's own system: `timeout`-bounded commands.
#[derive(Debug, Clone, Copy, Default)]
pub struct NodeSystem;

impl System for NodeSystem {
    fn run(&self, seconds: u32, argv: &[&str]) -> Result<String, String> {
        let output = Command::new(TIMEOUT)
            .arg(seconds.to_string())
            .args(argv)
            .output()
            .map_err(|error| error.to_string())?;
        if output.status.success() {
            Ok(String::from_utf8_lossy(&output.stdout).into_owned())
        } else {
            Err(String::from_utf8_lossy(&output.stderr).trim().to_owned())
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, serde::Deserialize)]
#[serde(rename_all = "lowercase")]
enum Runtime {
    Systemd,
    Docker,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, serde::Deserialize)]
#[serde(rename_all = "lowercase")]
enum State {
    Running,
    Stopped,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, serde::Deserialize)]
#[serde(rename_all = "lowercase")]
enum Boot {
    Enabled,
    Disabled,
}

#[derive(Debug, serde::Deserialize)]
#[serde(deny_unknown_fields)]
struct ProcessInput {
    process_id: u64,
    runtime: Runtime,
    name: String,
    state: State,
    /// A Docker container's admitted image, which its read-back requires, as Swamp's `docker_container` check does.
    #[serde(default)]
    image: Option<String>,
    /// A unit's boot policy; a container has none.
    #[serde(default)]
    boot: Option<Boot>,
}

/// Converges one host Process to its admitted state and reads it back. A unit: `systemctl enable` or `disable` only when
/// its file state differs, then `start` or `stop` only when its active state differs; the read-back requires
/// `LoadState=loaded`, `ActiveState` `active` or `inactive` exactly, and the admitted `UnitFileState`. A container: `docker container
/// start` or `stop` only when its status differs; the read-back requires State.Status `running` or `exited` exactly and the
/// admitted image. The
/// container is read by its exact name, and the answer must carry that name, so no other container is ever acted on.
/// Answers how many changes it made: 0 when the Process already had its state.
///
/// # Errors
///
/// Each code names the phase that failed, so the Gateway can name the Swamp step it stands for: before or during the change
/// `process.input_invalid`, `process.unit_missing`, `process.container_missing` or `process.apply_failed`; a stop's final
/// plain stop `process.unit_stop_failed`; the read-back itself `process.read_failed`; a read-back that answered a different
/// state `process.state_mismatch`.
pub fn ensure_state(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: ProcessInput = serde_json::from_value(input.clone())
        .map_err(|error| Failure::new("process.input_invalid", error.to_string()))?;
    if !owned_name(&input.name, input.process_id, input.runtime) {
        return Err(Failure::new(
            "process.input_invalid",
            format!("{} is not Process {}'s", input.name, input.process_id),
        ));
    }
    match (input.runtime, input.boot) {
        (Runtime::Systemd, Some(boot)) => unit(system, &input.name, input.state, boot),
        (Runtime::Docker, None) => match &input.image {
            Some(image) => container(system, &input.name, image, input.state),
            None => Err(Failure::new(
                "process.input_invalid",
                "a container needs its image",
            )),
        },
        _ => Err(Failure::new(
            "process.input_invalid",
            "a unit needs its boot policy, a container has none",
        )),
    }
}

/// `orbit-process-<id>-<slug>`, with `.service` for a unit: the names the Gateway gives its Processes.
fn owned_name(name: &str, process_id: u64, runtime: Runtime) -> bool {
    let rest = match runtime {
        Runtime::Systemd => name.strip_suffix(".service"),
        Runtime::Docker => Some(name),
    };
    rest.and_then(|rest| rest.strip_prefix(&format!("orbit-process-{process_id}-")))
        .is_some_and(|slug| {
            !slug.is_empty()
                && slug
                    .bytes()
                    .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'-')
        })
}

/// A unit's properties from `systemctl show`. A property systemd did not answer is absent, which no read-back accepts; an
/// answered but empty value is `Some("")`.
#[derive(Debug, Default, PartialEq, Eq)]
struct Unit {
    load: Option<String>,
    active: Option<String>,
    sub: Option<String>,
    file: Option<String>,
    next_elapse: Option<String>,
}

impl Unit {
    fn is(field: Option<&String>, value: &str) -> bool {
        field.is_some_and(|field| field == value)
    }

    fn describe(&self) -> String {
        let show = |field: &Option<String>| field.clone().unwrap_or_else(|| "<absent>".to_owned());
        format!(
            "LoadState={} ActiveState={} SubState={} UnitFileState={} NextElapseUSecRealtime={}",
            show(&self.load),
            show(&self.active),
            show(&self.sub),
            show(&self.file),
            show(&self.next_elapse)
        )
    }
}

/// Reads a unit's properties. A read that fails, or that lacks one of the properties the caller relies on, is `code`.
fn show(system: &impl System, name: &str, code: &'static str) -> Result<Unit, Failure> {
    let stdout = system
        .run(
            20,
            &[
                SYSTEMCTL,
                "show",
                "--property=LoadState",
                "--property=ActiveState",
                "--property=SubState",
                "--property=UnitFileState",
                "--property=NextElapseUSecRealtime",
                "--",
                name,
            ],
        )
        .map_err(|detail| Failure::new(code, detail))?;
    let unit = parse_unit(&stdout);
    let complete =
        unit.load.is_some() && unit.active.is_some() && unit.sub.is_some() && unit.file.is_some();
    if complete {
        Ok(unit)
    } else {
        Err(Failure::new(
            code,
            format!("{name}: systemd left a property out: {}", unit.describe()),
        ))
    }
}

fn parse_unit(stdout: &str) -> Unit {
    let mut unit = Unit::default();
    for line in stdout.lines() {
        let Some((key, value)) = line.split_once('=') else {
            continue;
        };
        let value = Some(value.trim().to_owned());
        match key {
            "LoadState" => unit.load = value,
            "ActiveState" => unit.active = value,
            "SubState" => unit.sub = value,
            "UnitFileState" => unit.file = value,
            "NextElapseUSecRealtime" => unit.next_elapse = value,
            _ => {}
        }
    }
    unit
}

fn systemctl(system: &impl System, seconds: u32, verb: &str, name: &str) -> Result<(), Failure> {
    system
        .run(seconds, &[SYSTEMCTL, verb, "--", name])
        .map(|_| ())
        .map_err(|detail| {
            Failure::new(
                "process.apply_failed",
                format!("systemctl {verb}: {detail}"),
            )
        })
}

fn unit(system: &impl System, name: &str, state: State, boot: Boot) -> Result<u32, Failure> {
    let before = show(system, name, "process.apply_failed")?;
    if !Unit::is(before.load.as_ref(), "loaded") {
        return Err(Failure::new(
            "process.unit_missing",
            format!("{name}: {}", before.describe()),
        ));
    }
    let mut changes = 0;
    let file = if boot == Boot::Enabled {
        "enabled"
    } else {
        "disabled"
    };
    if !Unit::is(before.file.as_ref(), file) {
        systemctl(
            system,
            20,
            if boot == Boot::Enabled {
                "enable"
            } else {
                "disable"
            },
            name,
        )?;
        changes += 1;
    }
    let active = if state == State::Running {
        "active"
    } else {
        "inactive"
    };
    if !Unit::is(before.active.as_ref(), active) {
        systemctl(
            system,
            20,
            if state == State::Running {
                "start"
            } else {
                "stop"
            },
            name,
        )?;
        changes += 1;
    }
    if state == State::Stopped {
        // Swamp's `Stop the unit`: a plain stop after the converge, every time, so a unit that came back stops again.
        system
            .run(20, &[SYSTEMCTL, "stop", "--", name])
            .map_err(|detail| {
                Failure::new(
                    "process.unit_stop_failed",
                    format!("systemctl stop: {detail}"),
                )
            })?;
    }
    let after = show(system, name, "process.read_failed")?;
    if !Unit::is(after.load.as_ref(), "loaded")
        || !Unit::is(after.active.as_ref(), active)
        || !Unit::is(after.file.as_ref(), file)
    {
        return Err(Failure::new(
            "process.state_mismatch",
            format!("{name}: {}", after.describe()),
        ));
    }
    Ok(changes)
}

/// The container's own name, status and image, read by its exact name.
/// A failed read is `code`: `process.container_missing` before the change, `process.read_failed` for the read-back.
fn inspect(
    system: &impl System,
    name: &str,
    code: &'static str,
) -> Result<(String, String), Failure> {
    let stdout = system
        .run(
            20,
            &[
                DOCKER,
                "container",
                "inspect",
                "--format",
                "{{.Name}} {{.State.Status}} {{.Config.Image}}",
                name,
            ],
        )
        .map_err(|detail| Failure::new(code, detail))?;
    let mut fields = stdout.split_whitespace();
    let owner = fields.next().unwrap_or_default();
    if owner.strip_prefix('/') != Some(name) {
        return Err(Failure::new(
            code,
            format!("{name}: inspect answered {owner}"),
        ));
    }
    Ok((
        fields.next().unwrap_or_default().to_owned(),
        fields.next().unwrap_or_default().to_owned(),
    ))
}

fn container(system: &impl System, name: &str, image: &str, state: State) -> Result<u32, Failure> {
    let wanted = if state == State::Running {
        "running"
    } else {
        "exited"
    };
    let mut changes = 0;
    if inspect(system, name, "process.container_missing")?.0 != wanted {
        let verb = if state == State::Running {
            "start"
        } else {
            "stop"
        };
        system
            .run(20, &[DOCKER, "container", verb, name])
            .map_err(|detail| {
                Failure::new(
                    "process.apply_failed",
                    format!("docker container {verb}: {detail}"),
                )
            })?;
        changes += 1;
    }
    let (status, running_image) = inspect(system, name, "process.read_failed")?;
    if status != wanted || running_image != image {
        return Err(Failure::new(
            "process.state_mismatch",
            format!("{name}: State.Status={status} Config.Image={running_image}"),
        ));
    }
    Ok(changes)
}

#[derive(Debug, serde::Deserialize)]
#[serde(deny_unknown_fields)]
struct TimerInput {
    schedule_id: String,
    unit: String,
}

/// What a Schedule timer's read-back found.
#[derive(Debug, Clone, PartialEq, Eq, serde::Serialize)]
#[non_exhaustive]
pub struct Timer {
    /// How many changes it made: 0 when the timer was already enabled and active.
    pub changes: u32,
    /// The timer's `UnitFileState` after the change: always `enabled`.
    pub unit_file_state: String,
    /// The timer's `ActiveState` after the change: always `active`.
    pub active_state: String,
    /// The next elapse systemd reports; the read-back requires systemd to answer it, empty when the calendar has none left.
    pub next_elapse: String,
}

/// Enables and starts a Schedule's own Node timer, each only when it differs, as Swamp's `enabled: true, ensure: running`
/// does, then reads it back: LoadState=loaded, UnitFileState=enabled and ActiveState=active, with its next elapse.
///
/// # Errors
///
/// `schedule.input_invalid` for a unit that is not the Schedule's own timer, `schedule.timer_missing`,
/// `schedule.apply_failed`, `schedule.read_failed` when the read-back cannot be read, or `schedule.state_mismatch`.
pub fn ensure_timer(system: &impl System, input: &serde_json::Value) -> Result<Timer, Failure> {
    let input: TimerInput = serde_json::from_value(input.clone())
        .map_err(|error| Failure::new("schedule.input_invalid", error.to_string()))?;
    if !uuid(&input.schedule_id)
        || input.unit != format!("orbit-schedule-{}.timer", input.schedule_id)
    {
        return Err(Failure::new(
            "schedule.input_invalid",
            format!(
                "{} is not Schedule {}'s timer",
                input.unit, input.schedule_id
            ),
        ));
    }
    let retag = |failure: Failure| Failure::new("schedule.apply_failed", failure.detail);
    let before = timer_show(system, &input.unit, "schedule.apply_failed")?;
    if !Unit::is(before.load.as_ref(), "loaded") {
        return Err(Failure::new(
            "schedule.timer_missing",
            format!("{}: {}", input.unit, before.describe()),
        ));
    }
    let mut changes = 0;
    if !Unit::is(before.file.as_ref(), "enabled") {
        systemctl(system, 20, "enable", &input.unit).map_err(retag)?;
        changes += 1;
    }
    if !Unit::is(before.active.as_ref(), "active") {
        systemctl(system, 20, "start", &input.unit).map_err(retag)?;
        changes += 1;
    }
    let after = timer_show(system, &input.unit, "schedule.read_failed")?;
    if !Unit::is(after.load.as_ref(), "loaded")
        || !Unit::is(after.file.as_ref(), "enabled")
        || !Unit::is(after.active.as_ref(), "active")
    {
        return Err(Failure::new(
            "schedule.state_mismatch",
            format!("{}: {}", input.unit, after.describe()),
        ));
    }
    Ok(Timer {
        changes,
        unit_file_state: after.file.unwrap_or_default(),
        active_state: after.active.unwrap_or_default(),
        next_elapse: after.next_elapse.unwrap_or_default(),
    })
}

/// A timer's properties, which must also answer its next elapse.
fn timer_show(system: &impl System, name: &str, code: &'static str) -> Result<Unit, Failure> {
    let unit = show(system, name, code)?;
    if unit.next_elapse.is_none() {
        return Err(Failure::new(
            code,
            format!("{name}: systemd left a property out: {}", unit.describe()),
        ));
    }
    Ok(unit)
}

/// A lowercase hyphenated UUID, as the Gateway's Schedule ids are.
fn uuid(value: &str) -> bool {
    let groups: Vec<&str> = value.split('-').collect();
    groups.iter().map(|group| group.len()).eq([8, 4, 4, 4, 12])
        && groups.iter().all(|group| {
            group
                .bytes()
                .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
        })
}

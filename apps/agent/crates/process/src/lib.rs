//! The process family of the executor (ADR 0271): a Process's run state and a Schedule's Node timer.
//!
//! - [`ensure_state`] (`process.ensure_state`): converges one host Process, a systemd unit or a Docker container, to running
//!   or stopped and, for a unit, to its boot policy. One action for start and stop (and later restart); the Gateway names the
//!   desired state. It reads the state back and fails with `process.state_mismatch` when it differs.
//! - [`read_instance`] (`process.read_instance`) and [`restart`] (`process.restart`): a restart is not a desired state, so
//!   it is bound to evidence of the run it replaces, a unit's `InvocationID` or a container's ID and `State.StartedAt`. The
//!   read pins that evidence into the restart's order; the restart acts only while the Process still runs that instance, and
//!   records a durable attempt for its order before it calls the runtime, so no replay of one order restarts twice
//!   (docs/cli/process/engine/restart.mmd).
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

    /// Waits one poll interval while an interrupted restart settles. The tests' double does not wait.
    fn pause(&self) {}

    /// Durably records that the order `key` is about to restart its Process.
    ///
    /// # Errors
    ///
    /// Why the record could not be written and synced.
    fn begin_attempt(&self, key: &str) -> Result<(), String>;

    /// Whether the order `key` recorded an attempt.
    fn attempted(&self, key: &str) -> bool;
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

    fn pause(&self) {
        std::thread::sleep(std::time::Duration::from_secs(1));
    }

    fn begin_attempt(&self, key: &str) -> Result<(), String> {
        Attempts::node().begin(key)
    }

    fn attempted(&self, key: &str) -> bool {
        Attempts::node().attempted(key)
    }
}

/// Where a restart's attempt records live on a Node, beside the executor's journal.
const ATTEMPTS: &str = "/var/lib/orbit/executor/process-restart";

/// How old an attempt must be before a prune removes it: past the journal's 24-hour `done` retention, after which the
/// Gateway sends its order no more.
const ATTEMPT_RETENTION: std::time::Duration = std::time::Duration::from_secs(2 * 24 * 60 * 60);

/// The restart attempts of one directory: one empty, synced file per order (`<operation>-<step>`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Attempts {
    dir: std::path::PathBuf,
}

impl Attempts {
    /// The Node's attempts.
    pub fn node() -> Self {
        Self::at(ATTEMPTS)
    }

    /// The attempts in `dir`.
    pub fn at(dir: impl Into<std::path::PathBuf>) -> Self {
        Self { dir: dir.into() }
    }

    /// Durably records the order's attempt.
    ///
    /// # Errors
    ///
    /// Why the record could not be written and synced.
    pub fn begin(&self, key: &str) -> Result<(), String> {
        std::fs::create_dir_all(&self.dir).map_err(|error| error.to_string())?;
        let file = std::fs::File::create(self.dir.join(key)).map_err(|error| error.to_string())?;
        file.sync_all().map_err(|error| error.to_string())?;
        std::fs::File::open(&self.dir)
            .and_then(|directory| directory.sync_all())
            .map_err(|error| error.to_string())
    }

    /// Whether the order recorded an attempt.
    pub fn attempted(&self, key: &str) -> bool {
        self.dir.join(key).exists()
    }

    /// Removes the attempts older than two days. Call it only once the journal has recovered every order it held open: each
    /// older attempt then belongs to an order with a durable `done` record, which answers its every resend, so no attempt
    /// of a recoverable order is ever removed. An order that starts meanwhile records a fresh attempt, which stays.
    pub fn prune_recovered(&self) {
        let Ok(entries) = std::fs::read_dir(&self.dir) else {
            return;
        };
        for entry in entries.flatten() {
            let old = entry
                .metadata()
                .and_then(|metadata| metadata.modified())
                .ok()
                .and_then(|modified| modified.elapsed().ok())
                .is_some_and(|age| age > ATTEMPT_RETENTION);
            if old {
                let _ = std::fs::remove_file(entry.path());
            }
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

#[derive(Debug, serde::Deserialize)]
#[serde(deny_unknown_fields)]
struct ReadInput {
    process_id: u64,
    runtime: Runtime,
    name: String,
}

/// The run a restart replaces: a unit's `InvocationID`, empty while it is not running, or a container's
/// `<ID>@<State.StartedAt>`.
#[derive(Debug, Clone, PartialEq, Eq, serde::Serialize)]
#[non_exhaustive]
pub struct Instance {
    /// The evidence the restart's order pins.
    pub instance: String,
}

/// Reads the run a restart will replace, and changes nothing. A unit must be loaded and a container must answer its exact
/// name.
///
/// # Errors
///
/// `process.input_invalid`, `process.unit_missing`, `process.container_missing`, or `process.read_failed`.
pub fn read_instance(system: &impl System, input: &serde_json::Value) -> Result<Instance, Failure> {
    let input: ReadInput = serde_json::from_value(input.clone())
        .map_err(|error| Failure::new("process.input_invalid", error.to_string()))?;
    owned(&input.name, input.process_id, input.runtime)?;
    let instance = match input.runtime {
        Runtime::Systemd => {
            let unit = invocation(system, &input.name, "process.read_failed")?;
            if !Unit::is(unit.0.load.as_ref(), "loaded") {
                return Err(Failure::new(
                    "process.unit_missing",
                    format!("{}: {}", input.name, unit.0.describe()),
                ));
            }
            unit.1
        }
        Runtime::Docker => started(system, &input.name, "process.container_missing")?.instance,
    };
    Ok(Instance { instance })
}

fn owned(name: &str, process_id: u64, runtime: Runtime) -> Result<(), Failure> {
    if owned_name(name, process_id, runtime) {
        Ok(())
    } else {
        Err(Failure::new(
            "process.input_invalid",
            format!("{name} is not Process {process_id}'s"),
        ))
    }
}

#[derive(Debug, serde::Deserialize)]
#[serde(deny_unknown_fields)]
struct RestartInput {
    process_id: u64,
    runtime: Runtime,
    name: String,
    /// The evidence `process.read_instance` answered for this Operation.
    instance: String,
    #[serde(default)]
    image: Option<String>,
    #[serde(default)]
    boot: Option<Boot>,
}

/// What a restart did and what its read-back found.
#[derive(Debug, Clone, PartialEq, Eq, serde::Serialize)]
#[non_exhaustive]
pub struct Restart {
    /// `now` when this order restarted the Process, `already` when the pinned run had ended before it acted, `recovered`
    /// when an earlier execution of this order restarted it and its result was lost.
    pub restarted: &'static str,
    /// The run the read-back found, never the pinned one.
    pub instance: String,
}

/// How many polls a replay waits for an interrupted restart to show its new run.
const SETTLE_POLLS: u32 = 30;

/// Restarts one host Process at most once for the order `key`, as Swamp's restart does: a unit first gets its admitted boot
/// policy, then `systemctl restart`; a container `docker container restart`. It acts only while the Process still runs the
/// pinned instance and the order recorded no attempt; the attempt is durable before the runtime is called and stays on the
/// Node, so the order stays spent across a crash or a reboot before its `done` record. A replay that finds its own attempt
/// never restarts again. Each branch then waits a bounded time for a verified new run: a run other than the pinned one, and a
/// unit loaded, `active` and at its boot policy, or a container `running` with its admitted image.
///
/// # Errors
///
/// `process.input_invalid`, `process.unit_missing`, `process.container_missing`, `process.boot_failed` for the boot policy,
/// `process.apply_failed` for the read before the restart or the restart itself, `process.read_failed` for a read after it,
/// `process.state_mismatch` for a new run that is not ready, `process.restart_unverified` for a restart that left the pinned
/// run, or `process.restart_unresolved` for an earlier attempt whose new run never showed.
pub fn restart(
    system: &impl System,
    key: &str,
    input: &serde_json::Value,
) -> Result<Restart, Failure> {
    let input: RestartInput = serde_json::from_value(input.clone())
        .map_err(|error| Failure::new("process.input_invalid", error.to_string()))?;
    owned(&input.name, input.process_id, input.runtime)?;
    if !attempt_key(key) {
        return Err(Failure::new(
            "process.input_invalid",
            format!("{key} is not an order key"),
        ));
    }
    match (input.runtime, input.boot, input.image.as_deref()) {
        (Runtime::Systemd, Some(boot), None) => restart_unit(system, key, &input, boot),
        (Runtime::Docker, None, Some(image)) => restart_container(system, key, &input, image),
        _ => Err(Failure::new(
            "process.input_invalid",
            "a unit needs its boot policy, a container its image",
        )),
    }
}

/// `<operation>-<step>`: the order's Operation step, with its `:` as `-`, the only characters an attempt's file name holds.
fn attempt_key(key: &str) -> bool {
    !key.is_empty()
        && key
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'-' || byte == b'_')
}

/// A unit's properties and its `InvocationID`.
fn invocation(
    system: &impl System,
    name: &str,
    code: &'static str,
) -> Result<(Unit, String), Failure> {
    let unit = show(system, name, code)?;
    let stdout = system
        .run(
            20,
            &[
                SYSTEMCTL,
                "show",
                "--property=InvocationID",
                "--value",
                "--",
                name,
            ],
        )
        .map_err(|detail| Failure::new(code, detail))?;
    Ok((unit, stdout.trim().to_owned()))
}

/// One read of the Process after the decision: its run, whether that run is ready, and whether it is still changing state.
struct Run {
    instance: String,
    ready: bool,
    settling: bool,
    detail: String,
}

/// `now` after this order restarted the Process; `already` when the pinned run had ended before it acted; `recovered` when an
/// earlier execution of this order recorded its attempt, which never restarts again.
fn act(
    system: &impl System,
    key: &str,
    pinned: &str,
    current: &str,
    restart: &dyn Fn() -> Result<(), Failure>,
) -> Result<&'static str, Failure> {
    if system.attempted(key) {
        return Ok("recovered");
    }
    if current != pinned {
        return Ok("already");
    }
    system.begin_attempt(key).map_err(|detail| {
        Failure::new("process.apply_failed", format!("attempt record: {detail}"))
    })?;
    restart()?;
    Ok("now")
}

/// Reads the Process until it runs a verified new run, for at most [`SETTLE_POLLS`] reads: it waits while the pinned run is
/// still in place, no run has started yet, or the new one is still changing state, and stops at a new run that settled
/// anywhere but ready.
fn settle(
    system: &impl System,
    key: &str,
    pinned: &str,
    restarted: &'static str,
    read: &dyn Fn() -> Result<Run, Failure>,
) -> Result<Restart, Failure> {
    // An empty identity is no run yet, such as a unit not yet started during boot: the wait goes on for it.
    let new = |run: &Run| !run.instance.is_empty() && run.instance != pinned;
    let mut run = read()?;
    for _ in 1..SETTLE_POLLS {
        if new(&run) && (run.ready || !run.settling) {
            break;
        }
        system.pause();
        run = read()?;
    }
    if !new(&run) {
        return Err(if restarted == "recovered" {
            Failure::new(
                "process.restart_unresolved",
                format!(
                    "{key}: after an earlier attempt no new run showed: {}",
                    run.detail
                ),
            )
        } else {
            Failure::new(
                "process.restart_unverified",
                format!(
                    "{key}: the pinned run {pinned} is still in place: {}",
                    run.detail
                ),
            )
        });
    }
    if !run.ready {
        return Err(Failure::new("process.state_mismatch", run.detail));
    }
    Ok(Restart {
        restarted,
        instance: run.instance,
    })
}

fn restart_unit(
    system: &impl System,
    key: &str,
    input: &RestartInput,
    boot: Boot,
) -> Result<Restart, Failure> {
    let name = input.name.as_str();
    let before = show(system, name, "process.apply_failed")?;
    if !Unit::is(before.load.as_ref(), "loaded") {
        return Err(Failure::new(
            "process.unit_missing",
            format!("{name}: {}", before.describe()),
        ));
    }
    let file = if boot == Boot::Enabled {
        "enabled"
    } else {
        "disabled"
    };
    // Swamp's `Set boot policy` runs before its restart, on every run; it changes only what differs.
    if !Unit::is(before.file.as_ref(), file) {
        let verb = if boot == Boot::Enabled {
            "enable"
        } else {
            "disable"
        };
        system
            .run(20, &[SYSTEMCTL, verb, "--", name])
            .map_err(|detail| {
                Failure::new("process.boot_failed", format!("systemctl {verb}: {detail}"))
            })?;
    }
    // The run is read after the boot policy, so a run that changed meanwhile is left alone.
    let (_, current) = invocation(system, name, "process.apply_failed")?;
    let restarted = act(system, key, &input.instance, &current, &|| {
        systemctl(system, 60, "restart", name)
    })?;
    settle(system, key, &input.instance, restarted, &|| {
        let (unit, instance) = invocation(system, name, "process.read_failed")?;
        let active = unit.active.as_deref().unwrap_or_default();
        Ok(Run {
            ready: !instance.is_empty()
                && Unit::is(unit.load.as_ref(), "loaded")
                && active == "active"
                && Unit::is(unit.file.as_ref(), file),
            settling: matches!(active, "activating" | "reloading" | "deactivating"),
            detail: format!("{name}: InvocationID={instance} {}", unit.describe()),
            instance,
        })
    })
}

/// A container's run, status and image, read by its exact name.
struct Started {
    instance: String,
    status: String,
    image: String,
}

fn started(system: &impl System, name: &str, code: &'static str) -> Result<Started, Failure> {
    let stdout = system
        .run(
            20,
            &[
                DOCKER,
                "container",
                "inspect",
                "--format",
                "{{.Name}} {{.Id}} {{.State.StartedAt}} {{.State.Status}} {{.Config.Image}}",
                name,
            ],
        )
        .map_err(|detail| Failure::new(code, detail))?;
    let fields: Vec<&str> = stdout.split_whitespace().collect();
    if fields.first().and_then(|owner| owner.strip_prefix('/')) != Some(name) || fields.len() != 5 {
        return Err(Failure::new(
            code,
            format!("{name}: inspect answered {}", stdout.trim()),
        ));
    }
    Ok(Started {
        instance: format!("{}@{}", fields[1], fields[2]),
        status: fields[3].to_owned(),
        image: fields[4].to_owned(),
    })
}

fn restart_container(
    system: &impl System,
    key: &str,
    input: &RestartInput,
    image: &str,
) -> Result<Restart, Failure> {
    let name = input.name.as_str();
    let current = started(system, name, "process.container_missing")?.instance;
    let restarted = act(system, key, &input.instance, &current, &|| {
        system
            .run(60, &[DOCKER, "container", "restart", name])
            .map(|_| ())
            .map_err(|detail| {
                Failure::new(
                    "process.apply_failed",
                    format!("docker container restart: {detail}"),
                )
            })
    })?;
    settle(system, key, &input.instance, restarted, &|| {
        let run = started(system, name, "process.read_failed")?;
        Ok(Run {
            ready: run.status == "running" && run.image == image,
            settling: matches!(run.status.as_str(), "restarting" | "created"),
            detail: format!(
                "{name}: {} State.Status={} Config.Image={}",
                run.instance, run.status, run.image
            ),
            instance: run.instance,
        })
    })
}

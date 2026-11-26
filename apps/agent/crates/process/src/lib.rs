//! The process family of the executor (ADR 0271): a Process's run state and a Schedule's Node timer.
//!
//! - [`ensure_state`] (`process.ensure_state`): converges one host Process, a systemd unit or a Docker container, to running
//!   or stopped and, for a unit, to its boot policy. One action for start and stop (and later restart); the Gateway names the
//!   desired state. It reads the state back and fails with `process.state_mismatch` when it differs.
//! - [`ensure_timer`] (`schedule.ensure_timer`): enables and starts a Schedule's own Node timer, then reads back its
//!   enablement, its state and its next elapse.
//!
//! A unit inside an Instance container is reached through `incus exec` in the container's saved Incus project and name,
//! as instance-container-process.yml does on Swamp.
//!
//! Every name comes from the admitted intent and is checked against the Process or Schedule it belongs to; every command is
//! fixed here. Nothing reaches the Node as a script.

#[cfg(test)]
mod tests;

use std::path::PathBuf;
use std::process::Command;
use std::time::Duration;

const TIMEOUT: &str = "/usr/bin/timeout";
const SYSTEMCTL: &str = "/usr/bin/systemctl";
const DOCKER: &str = "/usr/bin/docker";
const INCUS: &str = "/usr/bin/incus";

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
        Self { code, detail: detail.into() }
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

    /// Where the executor keeps a blob it read for this order, by its SHA-256.
    fn blob_path(&self, sha256: &str) -> PathBuf;

    /// Waits between two readiness reads.
    fn pause(&self, duration: Duration) {
        std::thread::sleep(duration);
    }
}

/// The Node's own system: `timeout`-bounded commands and the executor's blob cache.
#[derive(Debug, Clone)]
pub struct NodeSystem {
    blobs: PathBuf,
}

impl NodeSystem {
    /// A system that finds blobs in `blobs`, the executor's content cache.
    pub fn new(blobs: impl Into<PathBuf>) -> Self {
        Self { blobs: blobs.into() }
    }
}

impl System for NodeSystem {
    fn blob_path(&self, sha256: &str) -> PathBuf {
        self.blobs.join(sha256)
    }

    fn run(&self, seconds: u32, argv: &[&str]) -> Result<String, String> {
        let output = Command::new(TIMEOUT).arg(seconds.to_string()).args(argv).output().map_err(|error| error.to_string())?;
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
    /// The Instance container that holds the unit, by its saved Incus identity.
    #[serde(default)]
    container: Option<Guest>,
}

#[derive(Debug, serde::Deserialize)]
#[serde(deny_unknown_fields)]
struct Guest {
    project: String,
    name: String,
    /// The app-account move (`InstanceImage::appAccount`): its path in the container and its content.
    account_path: String,
    account: BlobRef,
}

#[derive(Debug, serde::Deserialize)]
#[serde(deny_unknown_fields)]
struct BlobRef {
    blob: BlobDigest,
}

#[derive(Debug, serde::Deserialize)]
#[serde(deny_unknown_fields)]
struct BlobDigest {
    sha256: String,
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
/// `process.input_invalid`, `process.unit_missing`, `process.container_missing`, `process.apply_failed` or
/// `process.state_mismatch`.
pub fn ensure_state(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: ProcessInput =
        serde_json::from_value(input.clone()).map_err(|error| Failure::new("process.input_invalid", error.to_string()))?;
    if !owned_name(&input.name, input.process_id, input.runtime) {
        return Err(Failure::new("process.input_invalid", format!("{} is not Process {}'s", input.name, input.process_id)));
    }
    match (input.runtime, input.boot, &input.container) {
        (Runtime::Systemd, Some(boot), None) => unit(system, &input.name, input.state, boot),
        (Runtime::Systemd, Some(boot), Some(guest)) => guest_unit(system, guest, &input.name, input.state, boot),
        (Runtime::Docker, None, None) => match &input.image {
            Some(image) => container(system, &input.name, image, input.state),
            None => Err(Failure::new("process.input_invalid", "a container needs its image")),
        },
        _ => Err(Failure::new("process.input_invalid", "a unit needs its boot policy, a container has none")),
    }
}

/// `orbit-process-<id>-<slug>`, with `.service` for a unit: the names the Gateway gives its Processes.
fn owned_name(name: &str, process_id: u64, runtime: Runtime) -> bool {
    let rest = match runtime {
        Runtime::Systemd => name.strip_suffix(".service"),
        Runtime::Docker => Some(name),
    };
    rest.and_then(|rest| rest.strip_prefix(&format!("orbit-process-{process_id}-")))
        .is_some_and(|slug| !slug.is_empty() && slug.bytes().all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'-'))
}

/// A unit's properties from `systemctl show`.
#[derive(Debug, Default, PartialEq, Eq)]
struct Unit {
    load: String,
    active: String,
    sub: String,
    file: String,
    next_elapse: String,
    job: String,
}

fn show(system: &impl System, name: &str) -> Result<Unit, Failure> {
    let stdout = system
        .run(
            20,
            &[SYSTEMCTL, "show", "--property=LoadState", "--property=ActiveState", "--property=SubState", "--property=UnitFileState",
                "--property=NextElapseUSecRealtime", "--", name],
        )
        .map_err(|detail| Failure::new("process.apply_failed", detail))?;
    Ok(parse_unit(&stdout))
}

fn parse_unit(stdout: &str) -> Unit {
    let mut unit = Unit::default();
    for line in stdout.lines() {
        let Some((key, value)) = line.split_once('=') else { continue };
        let value = value.trim().to_owned();
        match key {
            "LoadState" => unit.load = value,
            "ActiveState" => unit.active = value,
            "SubState" => unit.sub = value,
            "UnitFileState" => unit.file = value,
            "NextElapseUSecRealtime" => unit.next_elapse = value,
            "Job" => unit.job = value,
            _ => {}
        }
    }
    unit
}

fn systemctl(system: &impl System, seconds: u32, verb: &str, name: &str) -> Result<(), Failure> {
    system.run(seconds, &[SYSTEMCTL, verb, "--", name]).map(|_| ()).map_err(|detail| Failure::new("process.apply_failed", format!("systemctl {verb}: {detail}")))
}

fn unit(system: &impl System, name: &str, state: State, boot: Boot) -> Result<u32, Failure> {
    let before = show(system, name)?;
    if before.load != "loaded" {
        return Err(Failure::new("process.unit_missing", format!("{name}: LoadState={}", before.load)));
    }
    let mut changes = 0;
    let file = if boot == Boot::Enabled { "enabled" } else { "disabled" };
    if before.file != file {
        systemctl(system, 20, if boot == Boot::Enabled { "enable" } else { "disable" }, name)?;
        changes += 1;
    }
    let active = if state == State::Running { "active" } else { "inactive" };
    if before.active != active {
        systemctl(system, 20, if state == State::Running { "start" } else { "stop" }, name)?;
        changes += 1;
    }
    let after = show(system, name)?;
    if after.load != "loaded" || after.active != active || after.file != file {
        return Err(Failure::new(
            "process.state_mismatch",
            format!("{name}: LoadState={} ActiveState={} SubState={} UnitFileState={}", after.load, after.active, after.sub, after.file),
        ));
    }
    Ok(changes)
}

/// `<prefix>-<digits>`-style Incus names: lowercase letters, digits and hyphens, not starting with a hyphen.
fn incus_name(value: &str) -> bool {
    (1..=63).contains(&value.len()) && !value.starts_with('-') && value.bytes().all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'-')
}

/// A unit inside an Instance container, as instance-container-process.yml changes it: the container must run; the app
/// account moves to `www-data` first (its file written only when it differs, then run); `enable --now --no-block` for a
/// start, `disable --now` for a stop, each only when the unit differs. A start then waits for its job to leave the queue
/// and the unit to be active, 5 s per read, 10 reads 1 s apart; a stop waits for `inactive`. The read-back requires
/// `LoadState=loaded`, `ActiveState` exactly, and the admitted `UnitFileState`.
fn guest_unit(system: &impl System, guest: &Guest, name: &str, state: State, boot: Boot) -> Result<u32, Failure> {
    if !incus_name(&guest.project) || !incus_name(&guest.name) || !guest.account_path.starts_with('/') || guest.account_path.contains("..") {
        return Err(Failure::new("process.input_invalid", "the container identity is outside the Incus name form"));
    }
    let (project, container) = (guest.project.as_str(), guest.name.as_str());
    let query = format!("/1.0/instances/{container}?project={project}");
    let running = system
        .run(20, &[INCUS, "query", "--", &query])
        .ok()
        .and_then(|stdout| serde_json::from_str::<serde_json::Value>(&stdout).ok())
        .is_some_and(|answer| answer["status"] == "Running" && answer["name"] == container);
    if !running {
        return Err(Failure::new("process.target_inactive", format!("{project}/{container} is not running")));
    }
    let root = |rest: &[&str]| -> Vec<String> {
        ["exec", "--project", project, container, "--"].iter().chain(rest).map(|part| (*part).to_owned()).collect()
    };
    let exec = |seconds: u32, rest: &[&str]| -> Result<String, String> {
        let argv = root(rest);
        let mut full = vec![INCUS];
        full.extend(argv.iter().map(String::as_str));
        system.run(seconds, &full)
    };
    let mut changes = 0;
    // The app account: written only when it differs, then run; a moved container only reads.
    let sha = guest.account.blob.sha256.as_str();
    let placed = exec(20, &["sha256sum", "--", &guest.account_path]).ok().and_then(|stdout| stdout.split_whitespace().next().map(str::to_owned));
    if placed.as_deref() != Some(sha) {
        let source = system.blob_path(sha);
        let destination = format!("{container}{}", guest.account_path);
        system
            .run(30, &[INCUS, "file", "push", "--project", project, "--create-dirs", "--uid", "0", "--gid", "0", "--mode", "0755", "--",
                &source.to_string_lossy(), &destination])
            .map_err(|detail| Failure::new("process.apply_failed", format!("app account: {detail}")))?;
    }
    let moved = exec(60, &[&guest.account_path]).map_err(|detail| Failure::new("process.apply_failed", format!("app account: {detail}")))?;
    if moved.contains("moved to www-data") {
        changes += 1;
    }
    let read = |properties: &[&str]| -> Result<Unit, Failure> {
        let mut rest = vec!["timeout", "5", "systemctl", "show"];
        rest.extend_from_slice(properties);
        rest.extend_from_slice(&["--", name]);
        exec(10, &rest).map(|stdout| parse_unit(&stdout)).map_err(|detail| Failure::new("process.apply_failed", detail))
    };
    let all = ["--property=LoadState", "--property=ActiveState", "--property=UnitFileState", "--property=Job"];
    let file = if boot == Boot::Enabled { "enabled" } else { "disabled" };
    let active = if state == State::Running { "active" } else { "inactive" };
    let before = read(&all)?;
    if before.active != active || before.file != file || !before.job.is_empty() {
        let verb: &[&str] = match boot {
            Boot::Enabled => &["timeout", "5", "systemctl", "enable", "--now", "--no-block"],
            Boot::Disabled => &["timeout", "20", "systemctl", "disable", "--now"],
        };
        let mut rest = verb.to_vec();
        rest.extend_from_slice(&["--", name]);
        exec(25, &rest).map_err(|detail| Failure::new("process.apply_failed", format!("systemctl: {detail}")))?;
        changes += 1;
    }
    let failed = if state == State::Running { "process.start_failed" } else { "process.stop_failed" };
    let mut after = Unit::default();
    for attempt in 0..=10 {
        if attempt > 0 {
            system.pause(Duration::from_secs(1));
        }
        after = read(&all)?;
        if after.active == active && after.job.is_empty() {
            break;
        }
    }
    if after.load != "loaded" || after.active != active || !after.job.is_empty() || after.file != file {
        return Err(Failure::new(
            failed,
            format!("{name}: LoadState={} ActiveState={} UnitFileState={} Job={}", after.load, after.active, after.file, after.job),
        ));
    }
    Ok(changes)
}

/// The container's own name, status and image, read by its exact name.
fn inspect(system: &impl System, name: &str) -> Result<(String, String), Failure> {
    let stdout = system
        .run(20, &[DOCKER, "container", "inspect", "--format", "{{.Name}} {{.State.Status}} {{.Config.Image}}", name])
        .map_err(|detail| Failure::new("process.container_missing", detail))?;
    let mut fields = stdout.split_whitespace();
    let owner = fields.next().unwrap_or_default();
    if owner.strip_prefix('/') != Some(name) {
        return Err(Failure::new("process.container_missing", format!("{name}: inspect answered {owner}")));
    }
    Ok((fields.next().unwrap_or_default().to_owned(), fields.next().unwrap_or_default().to_owned()))
}

fn container(system: &impl System, name: &str, image: &str, state: State) -> Result<u32, Failure> {
    let wanted = if state == State::Running { "running" } else { "exited" };
    let mut changes = 0;
    if inspect(system, name)?.0 != wanted {
        let verb = if state == State::Running { "start" } else { "stop" };
        system
            .run(20, &[DOCKER, "container", verb, name])
            .map_err(|detail| Failure::new("process.apply_failed", format!("docker container {verb}: {detail}")))?;
        changes += 1;
    }
    let (status, running_image) = inspect(system, name)?;
    if status != wanted || running_image != image {
        return Err(Failure::new("process.state_mismatch", format!("{name}: State.Status={status} Config.Image={running_image}")));
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
    /// The next elapse systemd reports, empty when the calendar has none left.
    pub next_elapse: String,
}

/// Enables and starts a Schedule's own Node timer, each only when it differs, as Swamp's `enabled: true, ensure: running`
/// does, then reads it back: LoadState=loaded, UnitFileState=enabled and ActiveState=active, with its next elapse.
///
/// # Errors
///
/// `schedule.input_invalid` for a unit that is not the Schedule's own timer, `schedule.timer_missing`,
/// `schedule.apply_failed` or `schedule.state_mismatch`.
pub fn ensure_timer(system: &impl System, input: &serde_json::Value) -> Result<Timer, Failure> {
    let input: TimerInput =
        serde_json::from_value(input.clone()).map_err(|error| Failure::new("schedule.input_invalid", error.to_string()))?;
    if !uuid(&input.schedule_id) || input.unit != format!("orbit-schedule-{}.timer", input.schedule_id) {
        return Err(Failure::new("schedule.input_invalid", format!("{} is not Schedule {}'s timer", input.unit, input.schedule_id)));
    }
    let retag = |failure: Failure| Failure::new("schedule.apply_failed", failure.detail);
    let before = show(system, &input.unit).map_err(retag)?;
    if before.load != "loaded" {
        return Err(Failure::new("schedule.timer_missing", format!("{}: LoadState={}", input.unit, before.load)));
    }
    let mut changes = 0;
    if before.file != "enabled" {
        systemctl(system, 20, "enable", &input.unit).map_err(retag)?;
        changes += 1;
    }
    if before.active != "active" {
        systemctl(system, 20, "start", &input.unit).map_err(retag)?;
        changes += 1;
    }
    let after = show(system, &input.unit).map_err(retag)?;
    if after.load != "loaded" || after.file != "enabled" || after.active != "active" {
        return Err(Failure::new(
            "schedule.state_mismatch",
            format!("{}: LoadState={} ActiveState={} UnitFileState={}", input.unit, after.load, after.active, after.file),
        ));
    }
    Ok(Timer { changes, unit_file_state: after.file, active_state: after.active, next_elapse: after.next_elapse })
}

/// A lowercase hyphenated UUID, as the Gateway's Schedule ids are.
fn uuid(value: &str) -> bool {
    let groups: Vec<&str> = value.split('-').collect();
    groups.iter().map(|group| group.len()).eq([8, 4, 4, 4, 12])
        && groups.iter().all(|group| group.bytes().all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte)))
}

//! `incus.instance.ensure`: one Incus instance, copied from a snapshot, running, stopped or absent.

use std::sync::LazyLock;
use std::time::{Duration, Instant};

use regex::Regex;
use serde::Deserialize;
use serde_json::{json, Value};

use crate::api::{Answer, NodeSystem, API_FAILED, TIMEOUT};
use crate::Failure;

/// The socket of a system Incus, used when the input names none.
pub(crate) const DEFAULT_SOCKET: &str = "/var/lib/incus/unix.socket";
/// The instance key that records the snapshot a copy came from, as `<project>/<instance>/<snapshot>`.
pub(crate) const SOURCE_KEY: &str = "user.source_snapshot";
/// The longest budget an order may give.
const MAX_SECONDS: u64 = 600;

/// An Incus project, instance, snapshot or profile name: no wildcard, no separator, nothing to escape in a URL.
static NAME: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$").expect("a valid pattern"));
/// An instance name as Incus admits it: a host name label.
static INSTANCE: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^[A-Za-z][A-Za-z0-9-]{0,62}$").expect("a valid pattern"));

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    #[serde(default)]
    socket: Option<String>,
    project: String,
    name: String,
    state: State,
    #[serde(default)]
    source: Option<Source>,
    #[serde(default)]
    profiles: Option<Vec<String>>,
    #[serde(default)]
    force: bool,
    timeout_seconds: u64,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "snake_case")]
enum State {
    Running,
    Stopped,
    Absent,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Source {
    project: String,
    instance: String,
    snapshot: String,
}

impl Source {
    fn marker(&self) -> String {
        format!("{}/{}/{}", self.project, self.instance, self.snapshot)
    }
}

/// What the primitive reads of the instance.
struct Found {
    status: String,
    source: Option<String>,
    /// Whether stopping it deletes it.
    ephemeral: bool,
    /// The custom storage volumes its devices attach, as `<pool>/<volume>`, `dependent` or not.
    volumes: Vec<String>,
}

/// Converges the instance to the order's state and answers the number of changes; a repeat answers 0.
///
/// With `source`, a missing instance is first copied from that snapshot with the order's `profiles`, and an existing one
/// must record that same snapshot. `absent` deletes exactly the named instance in the named project; a running one only with
/// `force`. Every background operation is awaited within `timeout_seconds`.
///
/// # Errors
///
/// `incus.input_invalid` for an input outside the admitted shape; `incus.instance_missing` for a missing instance without a
/// source; `incus.source_missing` for a source snapshot Incus does not hold; `incus.source_conflict` for an existing instance
/// from another source; `incus.instance_running` for a running instance to remove without `force`;
/// `incus.instance_volumes_attached` for an instance to remove whose devices attach a custom storage volume;
/// `incus.instance_ephemeral` for a running ephemeral instance ordered `stopped`, which the stop would delete;
/// `incus.instance_state_unsupported` for an instance neither running nor stopped; `incus.api_failed` for a request Incus
/// refuses or answers unexpectedly; `incus.operation_failed` for a background operation that fails;
/// `incus.operation_timeout` for one that outlasts the budget; `incus.readback_mismatch` when the instance does not read
/// back as ordered.
pub fn instance_ensure(input: &Value) -> Result<u32, Failure> {
    let input: Input =
        serde_json::from_value(input.clone()).map_err(|error| invalid(&error.to_string()))?;
    validate(&input)?;
    let socket = input.socket.as_deref().unwrap_or(DEFAULT_SOCKET);
    let incus = NodeSystem::new(
        socket,
        Instant::now() + Duration::from_secs(input.timeout_seconds),
    );
    let url = format!("/1.0/instances/{}?project={}", input.name, input.project);
    let mut changes = 0;
    let mut found = read(&incus, &url)?;

    if input.state == State::Absent {
        let Some(instance) = found else { return Ok(0) };
        if !instance.volumes.is_empty() {
            return Err(Failure::new("incus.instance_volumes_attached", format!("{url} attaches {}", instance.volumes.join(", "))));
        }
        if instance.status != "Stopped" {
            if !input.force {
                return Err(Failure::new("incus.instance_running", format!("{url} is {}", instance.status)));
            }
            change(&incus, &input, Change::Kill)?;
            changes += 1;
        }
        // Stopping an ephemeral instance deletes it, so the delete runs only while the instance is still there.
        if read(&incus, &url)?.is_some() {
            match incus.request("DELETE", &url, None)? {
                Answer::Error(404, _) => {}
                answer => settle(&incus, answer, &url)?,
            }
            changes += 1;
        }
        return match read(&incus, &url)? {
            None => Ok(changes),
            Some(_) => Err(Failure::new("incus.readback_mismatch", format!("{url} is still there"))),
        };
    }

    match (&found, &input.source) {
        (None, None) => {
            return Err(Failure::new(
                "incus.instance_missing",
                format!("{url} is missing and the order names no source"),
            ))
        }
        (None, Some(source)) => {
            copy(&incus, &input, source)?;
            changes += 1;
            found = read(&incus, &url)?;
        }
        (Some(instance), Some(source))
            if instance.source.as_deref() != Some(source.marker().as_str()) =>
        {
            return Err(Failure::new(
                "incus.source_conflict",
                format!(
                    "{url} records {:?}, the order {}",
                    instance.source,
                    source.marker()
                ),
            ));
        }
        (Some(_), _) => {}
    }

    let instance = found.ok_or_else(|| Failure::new("incus.readback_mismatch", format!("{url} is missing after its copy")))?;
    let status = instance.status;
    if input.state == State::Stopped && status == "Running" && instance.ephemeral {
        return Err(Failure::new("incus.instance_ephemeral", format!("{url} is ephemeral: stopping it would delete it")));
    }
    let action = match (input.state, status.as_str()) {
        (State::Running, "Running") | (State::Stopped, "Stopped") => None,
        (State::Running, "Stopped") => Some(Change::Start),
        (State::Stopped, "Running") => Some(if input.force { Change::Kill } else { Change::Stop }),
        _ => {
            return Err(Failure::new(
                "incus.instance_state_unsupported",
                format!("{url} is {status}"),
            ))
        }
    };
    if let Some(action) = action {
        change(&incus, &input, action)?;
        changes += 1;
    }
    let want = if input.state == State::Running {
        "Running"
    } else {
        "Stopped"
    };
    match read(&incus, &url)? {
        Some(instance) if instance.status == want => Ok(changes),
        other => Err(Failure::new(
            "incus.readback_mismatch",
            format!(
                "{url} reads {:?}, not {want}",
                other.map(|found| found.status)
            ),
        )),
    }
}

fn invalid(detail: &str) -> Failure {
    Failure::new("incus.input_invalid", detail)
}

fn validate(input: &Input) -> Result<(), Failure> {
    if let Some(socket) = &input.socket {
        if !socket.starts_with('/') || socket.split('/').any(|part| part == "..") {
            return Err(invalid("socket"));
        }
    }
    if !NAME.is_match(&input.project) || !INSTANCE.is_match(&input.name) {
        return Err(invalid("project or name"));
    }
    if input.timeout_seconds == 0 || input.timeout_seconds > MAX_SECONDS {
        return Err(invalid("timeout_seconds"));
    }
    match (&input.source, &input.profiles) {
        (None, None) => Ok(()),
        (Some(_), _) if input.state == State::Absent => Err(invalid("source with absent")),
        (Some(source), Some(profiles))
            if NAME.is_match(&source.project)
                && INSTANCE.is_match(&source.instance)
                && NAME.is_match(&source.snapshot)
                && !profiles.is_empty()
                && profiles.iter().all(|profile| NAME.is_match(profile)) =>
        {
            Ok(())
        }
        _ => Err(invalid("source and profiles")),
    }
}

/// The instance, or `None` when Incus does not hold it.
fn read(incus: &NodeSystem, url: &str) -> Result<Option<Found>, Failure> {
    match incus.request("GET", url, None)? {
        Answer::Sync(metadata) => {
            let status = metadata
                .get("status")
                .and_then(Value::as_str)
                .ok_or_else(|| Failure::new(API_FAILED, format!("{url}: no status")))?;
            let source = metadata
                .pointer("/config")
                .and_then(|config| config.get(SOURCE_KEY))
                .and_then(Value::as_str)
                .map(str::to_owned);
            let ephemeral = metadata.get("ephemeral").and_then(Value::as_bool).unwrap_or(false);
            // A device a profile adds counts too, so the expanded devices are read when Incus answers them.
            let devices = metadata.get("expanded_devices").or_else(|| metadata.get("devices")).and_then(Value::as_object);
            let volumes = devices
                .into_iter()
                .flatten()
                .filter(|(_, device)| device.get("type").and_then(Value::as_str) == Some("disk"))
                .filter_map(|(_, device)| {
                    let pool = device.get("pool").and_then(Value::as_str)?;
                    let volume = device.get("source").and_then(Value::as_str)?;
                    Some(format!("{pool}/{volume}"))
                })
                .collect();
            Ok(Some(Found { status: status.to_owned(), source, ephemeral, volumes }))
        }
        Answer::Error(404, _) => Ok(None),
        other => Err(unexpected(url, &other)),
    }
}

fn unexpected(url: &str, answer: &Answer) -> Failure {
    Failure::new(API_FAILED, format!("{url}: {answer:?}"))
}

/// A change of the instance's run state.
#[derive(Clone, Copy)]
enum Change {
    Start,
    /// A clean shutdown within the budget.
    Stop,
    /// A kill.
    Kill,
}

/// Starts or stops the instance and waits for it.
fn change(incus: &NodeSystem, input: &Input, change: Change) -> Result<(), Failure> {
    let url = format!("/1.0/instances/{}/state?project={}", input.name, input.project);
    let body = match change {
        Change::Start => json!({"action": "start", "force": false}),
        Change::Stop => json!({"action": "stop", "force": false, "timeout": incus.seconds_left()}),
        Change::Kill => json!({"action": "stop", "force": true}),
    };
    settle(incus, incus.request("PUT", &url, Some(&body))?, &url)
}

/// Copies the source snapshot into the named instance, with the source instance's type.
fn copy(incus: &NodeSystem, input: &Input, source: &Source) -> Result<(), Failure> {
    let snapshot = format!(
        "/1.0/instances/{}/snapshots/{}?project={}",
        source.instance, source.snapshot, source.project
    );
    match incus.request("GET", &snapshot, None)? {
        Answer::Sync(_) => {}
        Answer::Error(404, _) => return Err(Failure::new("incus.source_missing", snapshot)),
        other => return Err(unexpected(&snapshot, &other)),
    }
    let origin = format!(
        "/1.0/instances/{}?project={}",
        source.instance, source.project
    );
    let kind = match incus.request("GET", &origin, None)? {
        Answer::Sync(metadata) => metadata
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or("container")
            .to_owned(),
        other => return Err(unexpected(&origin, &other)),
    };
    let url = format!("/1.0/instances?project={}", input.project);
    let body = json!({
        "name": input.name,
        "type": kind,
        "profiles": input.profiles,
        "config": {SOURCE_KEY: source.marker()},
        "source": {"type": "copy", "source": format!("{}/{}", source.instance, source.snapshot), "project": source.project},
    });
    settle(incus, incus.request("POST", &url, Some(&body))?, &url)
}

/// Waits for a background operation within the budget; a synchronous success is already settled.
fn settle(incus: &NodeSystem, answer: Answer, url: &str) -> Result<(), Failure> {
    let operation = match answer {
        Answer::Sync(_) => return Ok(()),
        Answer::Async(operation) => operation,
        Answer::Error(..) => return Err(unexpected(url, &answer)),
    };
    let wait = format!("{operation}/wait?timeout={}", incus.seconds_left());
    let metadata = match incus.request("GET", &wait, None)? {
        Answer::Sync(metadata) => metadata,
        other => return Err(unexpected(&wait, &other)),
    };
    match metadata.get("status").and_then(Value::as_str) {
        Some("Success") => Ok(()),
        Some("Failure" | "Cancelled") => Err(Failure::new(
            "incus.operation_failed",
            format!(
                "{url}: {}",
                metadata
                    .get("err")
                    .and_then(Value::as_str)
                    .unwrap_or_default()
            ),
        )),
        status => Err(Failure::new(
            TIMEOUT,
            format!("{url}: {operation} is {status:?} at the deadline"),
        )),
    }
}

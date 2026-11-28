//! `incus.device.ensure`: one device key of one instance with exactly the given configuration, or absent.

use std::collections::BTreeMap;

use serde::Deserialize;
use serde_json::{json, Map, Value};

use crate::{connect, input, not_converged, plain, Failure, State, NAME};

/// The writable fields of an instance that a `PUT` carries back unchanged.
const WRITABLE: [&str; 7] = [
    "architecture",
    "config",
    "devices",
    "ephemeral",
    "profiles",
    "stateful",
    "description",
];

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    #[serde(default)]
    socket: Option<String>,
    project: String,
    instance: String,
    device: String,
    state: State,
    timeout_ms: u64,
    #[serde(default)]
    config: Option<BTreeMap<String, String>>,
}

/// Ensures one device of one instance.
///
/// It reads the instance's own devices, never its profiles', and writes them back with only this key changed, under the
/// `ETag` it read, so a concurrent change refuses instead of being lost. Answers the number of changes, 0 on a repeat.
///
/// # Errors
///
/// `incus.input_invalid`, `incus.instance_missing`, `incus.operation_timeout`, `incus.api_failed` or
/// `incus.not_converged`.
pub fn device_ensure(raw: &Value) -> Result<u32, Failure> {
    let input: Input = input(raw)?;
    let desired = match (input.state, &input.config) {
        (State::Present, Some(config))
            if config.get("type").is_some_and(|kind| !kind.is_empty()) =>
        {
            Some(json!(config))
        }
        (State::Absent, None) => None,
        _ => {
            return Err(Failure::new(
                "incus.input_invalid",
                "present takes a config with a type; absent takes none",
            ))
        }
    };
    if let Some(config) = &input.config {
        if config.iter().any(|(key, value)| !NAME.is_match(key) || !plain(value)) {
            return Err(Failure::new("incus.input_invalid", "a config key is not a plain key, or a value is empty or padded"));
        }
        if config.get("dependent").is_some_and(|value| truthy(value)) {
            return Err(Failure::new("incus.input_invalid", "a dependent disk changes its volume too"));
        }
    }
    let client = connect(
        input.socket.as_deref(),
        input.timeout_ms,
        &[&input.project, &input.instance, &input.device],
    )?;
    let path = format!(
        "/1.0/instances/{}?project={}",
        input.instance, input.project
    );
    let read = || -> Result<(Value, Option<String>), Failure> {
        client.get(&path)?.ok_or_else(|| {
            Failure::new(
                "incus.instance_missing",
                format!("{} in {}", input.instance, input.project),
            )
        })
    };
    let (instance, etag) = read()?;
    if instance["devices"].get(&input.device) == desired.as_ref() {
        return Ok(0);
    }
    // Incus removes a dependent disk's volume with the device, so its change cannot stay within this device key.
    if instance["devices"][input.device.as_str()]["dependent"].as_str().is_some_and(truthy) {
        return Err(Failure::new("incus.device_dependent", format!("{} is a dependent disk", input.device)));
    }
    let mut body: Map<String, Value> = WRITABLE
        .iter()
        .filter_map(|key| {
            instance
                .get(*key)
                .map(|value| ((*key).to_owned(), value.clone()))
        })
        .collect();
    let devices = body.entry("devices").or_insert_with(|| json!({}));
    let devices = devices.as_object_mut().ok_or_else(|| {
        Failure::new(
            "incus.api_failed",
            "the instance's devices are not an object",
        )
    })?;
    match &desired {
        Some(config) => devices.insert(input.device.clone(), config.clone()),
        None => devices.remove(&input.device),
    };
    client.change("PUT", &path, Some(&Value::Object(body)), etag.as_deref())?;
    let (instance, _) = read()?;
    if instance["devices"].get(&input.device) == desired.as_ref() {
        Ok(1)
    } else {
        Err(not_converged(&input.device))
    }
}

/// Whether Incus reads a value as true.
fn truthy(value: &str) -> bool {
    ["true", "1", "yes", "on"].contains(&value.to_ascii_lowercase().as_str())
}

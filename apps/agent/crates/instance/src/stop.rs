//! `instance.stop`: a copy whose create failed stops and stays, for a retry or a destroy.

use serde::Deserialize;
use serde_json::Value;

use crate::{Failure, Incus, System};

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct StopInput {
    project: String,
    name: String,
}

/// Stops a running copy, as Swamp's `stop` phase does after a failed create: `incus stop --timeout 30`. A copy that is not
/// running, or no longer exists, is left as it is. Answers how many changes it made.
///
/// # Errors
///
/// `instance.stop_input_invalid` or `instance.stop_failed`.
pub fn stop(system: &impl System, input: &Value) -> Result<u32, Failure> {
    let input: StopInput = serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.stop_input_invalid", error.to_string()))?;
    if !crate::name(&input.project) || !crate::name(&input.name) {
        return Err(Failure::new("instance.stop_input_invalid", "project or name is not a name"));
    }
    let incus = Incus { system, code: "instance.stop_failed" };
    let copy = incus.query(&format!("/1.0/instances/{}?project={}", input.name, input.project))?;
    if copy.as_ref().and_then(|copy| copy.get("status")).and_then(Value::as_str) != Some("Running") {
        return Ok(0);
    }
    incus.change(&["--project", &input.project, "stop", "--timeout", "30", &input.name])?;
    Ok(1)
}

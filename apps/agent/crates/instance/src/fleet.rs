//! `instance.fleet_ready`: a nested-fleet copy's inner fleet, started and ready, inside the create.
//!
//! The clone part of the Swamp phases `fleet_check` and `fleet_resume` (docs/reference/development-containers.md#a-clone-starts-its-fleet).
//! The copy holds the exact seed of its default, so what the default's deployment checked and placed before its snapshot is in
//! the copy already: the fleet rules, the nested hosts' socket groups and pid-file heal, the nested Gateway's SSH pins and Club
//! key link, and the operator tunnel's boot unit. The copy only waits: the inner Incus, a nested container that did not start by
//! itself, and readiness, as the proof reads it.

use std::time::{Duration, Instant};

use serde::Deserialize;
use serde_json::Value;

use crate::{Failure, System, INCUS};

const INNER_INCUS: &str = "/usr/bin/incus";
const CURL: &str = "/usr/bin/curl";
const TEST: &str = "/usr/bin/test";

/// The operator tunnel's boot unit, which the default's seed holds and a clone keeps (instance.prepare).
pub(crate) const TUNNEL_UNIT: &str = "orbit-inner-fleet-tunnel.service";

const FAILED: &str = "instance.container_fleet_resume_failed";
/// How often readiness is read.
const POLL: Duration = Duration::from_millis(250);

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct FleetInput {
    project: String,
    name: String,
    /// The nested containers, which the inner Incus starts at boot.
    guests: Vec<String>,
    /// The nested Gateway over the operator tunnel, its host name, and the Nodes whose agents must be online.
    hub: String,
    host: String,
    nodes: Vec<String>,
    /// The bound of the readiness wait.
    seconds: u32,
}

/// Waits for the inner Incus, starts a nested container that is stopped, then reads readiness every 250 ms until the bound:
/// Doctor of the nested Gateway is healthy over the operator tunnel, every named Node's agent is online, and the Gateway knows
/// the operator with its key. A copy whose seed holds no tunnel boot unit has no tunnel at its start: it answers
/// `{"fleet": "seed_without_tunnel"}`, and the completion starts its fleet. Else it answers `{"fleet": "ready", "started": [...]}`.
///
/// # Errors
///
/// `instance.fleet_ready_input_invalid`, or `instance.container_fleet_resume_failed` with the check that failed in its detail.
pub fn fleet_ready(system: &impl System, input: &Value) -> Result<String, Failure> {
    let input: FleetInput = serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.fleet_ready_input_invalid", error.to_string()))?;
    let named = [&input.project, &input.name].into_iter().chain(&input.guests).chain(&input.nodes).all(|value| crate::name(value));
    let host = input.host.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'.' || byte == b'-');
    if !named || input.guests.is_empty() || input.hub.parse::<std::net::Ipv4Addr>().is_err() || !host || input.seconds == 0 || input.seconds > 600 {
        return Err(Failure::new("instance.fleet_ready_input_invalid", "a name, the hub, the host or the bound is not valid"));
    }
    // Each command with its own bound: a readiness read never waits out the whole budget.
    let exec = |seconds: u32, argv: &[&str]| -> Result<String, String> {
        let mut command: Vec<String> = [INCUS, "--project", &input.project, "exec", &input.name, "--"].iter().map(|part| (*part).to_owned()).collect();
        command.extend(argv.iter().map(|part| (*part).to_owned()));
        system.run(seconds, &command, None)
    };
    let fail = |check: &str, detail: String| Failure::new(FAILED, format!("Readiness check '{check}' failed: {detail}"));
    // The unit's link, read as a file: right after the start, systemd in the copy may not answer yet.
    let link = format!("/etc/systemd/system/multi-user.target.wants/{TUNNEL_UNIT}");
    if exec(30, &[TEST, "-L", &link]).is_err() {
        return Ok(serde_json::json!({"fleet": "seed_without_tunnel"}).to_string());
    }
    // The inner Incus starts at the copy's boot and starts the fleet; a read before it is ready is not a failure.
    exec(90, &[INNER_INCUS, "admin", "waitready", "--timeout", "80"]).map_err(|detail| fail("incus", detail))?;
    let listed = exec(30, &[INNER_INCUS, "list", "--format", "csv", "--columns", "ns"]).map_err(|detail| fail("running", detail))?;
    let running: Vec<&str> = listed.lines().filter_map(|line| line.strip_suffix(",RUNNING")).collect();
    let stopped: Vec<&str> = input.guests.iter().map(String::as_str).filter(|guest| !running.contains(guest)).collect();
    if !stopped.is_empty() {
        // Incus boots them in parallel in one call.
        let mut start = vec![INNER_INCUS, "start"];
        start.extend(&stopped);
        exec(120, &start).map_err(|detail| fail("running", format!("{} did not start: {detail}", stopped.join(", "))))?;
    }
    let api = |path: &str, post: bool| -> Result<String, String> {
        let url = format!("https://{}/api/v1/{path}", input.hub);
        let host = format!("Host: {}", input.host);
        let mut curl = vec![CURL, "-sSk", "--noproxy", "*", "-m", "2", "-H", &host, "-H", "Accept: application/json"];
        if post {
            curl.extend(["-X", "POST", "-H", "Content-Type: application/json", "-d", "{}"]);
        } else {
            curl.push("--fail");
        }
        curl.push(&url);
        exec(10, &curl)
    };
    let deadline = Instant::now() + Duration::from_secs(u64::from(input.seconds));
    loop {
        let last: String = match api("doctor", true) {
            Ok(body) if body.starts_with(r#"{"data":{"healthy":true"#) => break,
            Ok(body) => body.chars().take(300).collect(),
            Err(detail) => format!("no response: {}", detail.trim()),
        };
        if Instant::now() >= deadline {
            return Err(fail("doctor", format!("after {} s: {last}", input.seconds)));
        }
        std::thread::sleep(POLL);
    }
    let nodes: Value = api("nodes", false)
        .map_err(|detail| fail("agents", detail))
        .and_then(|body| serde_json::from_str(&body).map_err(|error| fail("agents", error.to_string())))?;
    let rows = nodes.get("data").and_then(Value::as_array).cloned().unwrap_or_default();
    let named = |name: &str| rows.iter().find(|row| row.get("name").and_then(Value::as_str) == Some(name));
    let offline: Vec<&str> = input
        .nodes
        .iter()
        .map(String::as_str)
        .filter(|node| named(node).and_then(|row| row.pointer("/agent/state")).and_then(Value::as_str) != Some("online"))
        .collect();
    if !offline.is_empty() {
        return Err(fail("agents", format!("the agent of {} is not online", offline.join(", "))));
    }
    if named("operator").and_then(|row| row.get("wireguard_public_key")).is_none_or(Value::is_null) {
        return Err(fail("operator", "the nested Gateway does not know the operator and its key".to_owned()));
    }
    Ok(serde_json::json!({"fleet": "ready", "started": stopped}).to_string())
}

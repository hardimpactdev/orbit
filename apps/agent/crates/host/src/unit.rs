//! `systemd.unit.ensure`: one unit, enabled or disabled, running or stopped. Lifted from `Site::php_unit` of the route
//! family; the unit name comes from input.

use std::sync::LazyLock;

use regex::Regex;
use serde::Deserialize;

use crate::{invalid, parse, strings, Failure, System};

static UNIT: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[A-Za-z0-9@_.:-]{1,200}\.(service|timer|socket)$").expect("a valid pattern"));

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    unit: String,
    enabled: bool,
    state: State,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "snake_case")]
enum State {
    Running,
    Stopped,
}

/// Reads the unit, changes only what differs, reads it back, and answers how many changes it made.
///
/// # Errors
///
/// `host.input_invalid` for a unit name outside the admitted shape, and `unit.ensure_failed` for a failed change or a
/// read-back that differs.
pub fn unit_ensure(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = parse(input)?;
    if !UNIT.is_match(&input.unit) || input.unit.starts_with('-') {
        return Err(invalid("unit"));
    }
    let failed = |detail: String| Failure::new("unit.ensure_failed", detail);
    let systemctl = |args: &[&str]| system.run(30, &[strings(&["/usr/bin/systemctl"]), strings(args), vec![input.unit.clone()]].concat());
    let want_running = input.state == State::Running;
    let mut changes = 0;
    let (enabled, running) = observe(&systemctl).map_err(failed)?;
    if enabled != input.enabled {
        systemctl(&[if input.enabled { "enable" } else { "disable" }]).map_err(failed)?;
        changes += 1;
    }
    if running != want_running {
        systemctl(&[if want_running { "start" } else { "stop" }]).map_err(failed)?;
        changes += 1;
    }
    if observe(&systemctl).map_err(failed)? != (input.enabled, want_running) {
        return Err(failed(format!("{} read-back", input.unit)));
    }
    Ok(changes)
}

/// Whether the unit is enabled and running, as systemd reports it. Only a reported state counts, `not-found` included; a
/// unit that cannot be read fails, so an unread state never passes for a stopped one.
fn observe(systemctl: &impl Fn(&[&str]) -> Result<String, String>) -> Result<(bool, bool), String> {
    let shown = systemctl(&["show", "--property=LoadState,UnitFileState,ActiveState"])?;
    let value = |name: &str| shown.lines().find_map(|line| line.strip_prefix(name).and_then(|rest| rest.strip_prefix('='))).map(str::trim);
    let (Some(load), Some(active)) = (value("LoadState"), value("ActiveState")) else {
        return Err("systemd gave no state".to_owned());
    };
    if !matches!(load, "loaded" | "not-found" | "masked") {
        return Err(format!("unit load state {load}"));
    }
    let enabled = matches!(value("UnitFileState").unwrap_or_default(), "enabled" | "enabled-runtime");
    Ok((enabled, matches!(active, "active" | "reloading" | "activating")))
}

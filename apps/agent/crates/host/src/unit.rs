//! `systemd.unit.ensure`: one unit, enabled or disabled, running or stopped. Lifted from `Site::php_unit` of the route
//! family; the unit name comes from input.

use std::sync::LazyLock;

use std::time::Duration;

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
    /// How long the unit may take to leave a transitional state (`activating`, `deactivating`) and settle.
    budget_ms: u64,
}

/// The longest settle budget an order may give.
const MAX_BUDGET_MS: u64 = 300_000;

/// The shortest: each `systemctl` call is bounded in whole seconds, so a budget under one second cannot be kept.
const MIN_BUDGET_MS: u64 = 1_000;

/// How often a settling unit is read again.
const INTERVAL: Duration = Duration::from_millis(250);

#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "snake_case")]
enum State {
    Running,
    Stopped,
}

/// Reads the unit once it has settled, changes only what differs, waits for it to settle again, reads it back, and answers
/// how many changes it made. A transitional state never counts as the terminal one asked for.
///
/// # Errors
///
/// `host.input_invalid` for a unit name or budget outside the admitted shape, and `unit.ensure_failed` for a failed change,
/// a unit still in transition when its budget ends, or a read-back that differs.
pub fn unit_ensure(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = parse(input)?;
    if !UNIT.is_match(&input.unit) || input.unit.starts_with('-') || input.budget_ms < MIN_BUDGET_MS || input.budget_ms > MAX_BUDGET_MS {
        return Err(invalid("unit"));
    }
    let failed = |detail: String| Failure::new("unit.ensure_failed", detail);
    // The budget bounds the whole action: every call gets at most the time left, and a start or stop does not wait on the
    // unit (`--no-block`); the action polls the unit itself.
    let deadline = system.now() + Duration::from_millis(input.budget_ms);
    let systemctl = |args: &[&str]| {
        let left = deadline.saturating_duration_since(system.now());
        let seconds = u32::try_from(left.as_secs() + u64::from(left.subsec_nanos() > 0)).unwrap_or(u32::MAX).max(1);
        system.run(seconds, &[strings(&["/usr/bin/systemctl"]), strings(args), vec![input.unit.clone()]].concat())
    };
    let settled = || -> Result<(bool, bool), String> {
        loop {
            let (enabled, active) = observe(&systemctl)?;
            match active {
                Active::Running => return Ok((enabled, true)),
                Active::Stopped => return Ok((enabled, false)),
                Active::Transitional(state) if system.now() >= deadline => return Err(format!("{} still {state} after its budget", input.unit)),
                Active::Transitional(_) => system.sleep(INTERVAL.min(deadline.saturating_duration_since(system.now()))),
            }
        }
    };
    let want_running = input.state == State::Running;
    let mut changes = 0;
    let (enabled, running) = settled().map_err(failed)?;
    if enabled != input.enabled {
        systemctl(&[if input.enabled { "enable" } else { "disable" }]).map_err(failed)?;
        changes += 1;
    }
    if running != want_running {
        systemctl(&[if want_running { "start" } else { "stop" }, "--no-block"]).map_err(failed)?;
        changes += 1;
    }
    if settled().map_err(failed)? != (input.enabled, want_running) {
        return Err(failed(format!("{} read-back", input.unit)));
    }
    Ok(changes)
}

/// A unit's activity as systemd reports it.
enum Active {
    Running,
    Stopped,
    /// `activating`, `deactivating` or any state systemd reports between the two.
    Transitional(String),
}

/// Whether the unit is enabled, and its activity, as systemd reports it. Only a reported state counts, `not-found`
/// included; a unit that cannot be read fails, so an unread state never passes for a stopped one.
fn observe(systemctl: &impl Fn(&[&str]) -> Result<String, String>) -> Result<(bool, Active), String> {
    let shown = systemctl(&["show", "--property=LoadState,UnitFileState,ActiveState"])?;
    let value = |name: &str| shown.lines().find_map(|line| line.strip_prefix(name).and_then(|rest| rest.strip_prefix('='))).map(str::trim);
    let (Some(load), Some(active)) = (value("LoadState"), value("ActiveState")) else {
        return Err("systemd gave no state".to_owned());
    };
    if !matches!(load, "loaded" | "not-found" | "masked") {
        return Err(format!("unit load state {load}"));
    }
    let enabled = matches!(value("UnitFileState").unwrap_or_default(), "enabled" | "enabled-runtime");
    let active = match active {
        "active" | "reloading" => Active::Running,
        "inactive" | "failed" => Active::Stopped,
        other => Active::Transitional(other.to_owned()),
    };
    Ok((enabled, active))
}

//! `systemd.unit.restart` and `systemd.unit.signal`: one run of one unit, restarted or signalled at most once per order.
//! A restart or a signal is not a desired state, so the executor binds it to its order instead: the journal answers a
//! repeat of the order with its recorded result and never runs a recovered one again (`Action::at_most_once`).

use std::sync::LazyLock;
use std::time::{Duration, Instant};

use regex::Regex;
use serde::Deserialize;

use crate::{invalid, parse, strings, Failure, System};

const SYSTEMCTL: &str = "/usr/bin/systemctl";

/// A unit name with no template instance: systemd's name characters and a unit type that can run or carry processes.
static PLAIN: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^[A-Za-z0-9_.:\\-]+\.(service|socket|target|timer|path|mount|swap|scope)$").expect("a valid pattern"));
/// A template instance, `<prefix>@<instance>.<type>`, both parts non-empty.
static INSTANCE: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^[A-Za-z0-9_.:\\-]+@[A-Za-z0-9_.:\\-]+\.(service|socket|target|timer|path|mount|swap|scope)$").expect("a valid pattern"));

/// systemd's longest unit name.
const MAX_UNIT: usize = 255;
/// The longest budget an order may give.
const MAX_BUDGET_MS: u64 = 300_000;
/// The shortest: each `systemctl` call is bounded in whole seconds.
const MIN_BUDGET_MS: u64 = 1_000;
/// How often a restarting unit is read again.
const INTERVAL: Duration = Duration::from_millis(250);

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct RestartInput {
    unit: String,
    /// Admits a template instance such as `worker@1.service`.
    #[serde(default)]
    template: bool,
    /// How long the restart and the read-back of the new run may take, from the order's start.
    budget_ms: u64,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct SignalInput {
    unit: String,
    #[serde(default)]
    template: bool,
    signal: Signal,
    budget_ms: u64,
}

/// The signals an order may send, to the unit's main process only.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize)]
enum Signal {
    #[serde(rename = "SIGHUP")]
    Hup,
    #[serde(rename = "SIGINT")]
    Int,
    #[serde(rename = "SIGQUIT")]
    Quit,
    #[serde(rename = "SIGTERM")]
    Term,
    #[serde(rename = "SIGKILL")]
    Kill,
    #[serde(rename = "SIGUSR1")]
    Usr1,
    #[serde(rename = "SIGUSR2")]
    Usr2,
}

impl Signal {
    fn name(self) -> &'static str {
        match self {
            Self::Hup => "SIGHUP",
            Self::Int => "SIGINT",
            Self::Quit => "SIGQUIT",
            Self::Term => "SIGTERM",
            Self::Kill => "SIGKILL",
            Self::Usr1 => "SIGUSR1",
            Self::Usr2 => "SIGUSR2",
        }
    }
}

/// The run a unit is in, as `systemctl show` reports it.
#[derive(Debug, Clone, PartialEq, Eq)]
struct Run {
    load: String,
    active: String,
    invocation: String,
    entered_us: u64,
    main_pid: u64,
}

/// One unit's `systemctl` calls, each bounded by what is left of the order's budget and run with a clean environment.
struct Unit<'a, S: System> {
    system: &'a S,
    name: &'a str,
    deadline: Instant,
}

impl<S: System> Unit<'_, S> {
    fn left(&self) -> Duration {
        self.deadline.saturating_duration_since(self.system.now())
    }

    /// `systemctl <args> -- <unit>`: the `--` ends the options, so the unit is only ever a unit.
    fn systemctl(&self, args: &[&str]) -> Result<String, String> {
        let left = self.left();
        if left.is_zero() {
            return Err("budget spent".to_owned());
        }
        let seconds = u32::try_from(left.as_secs() + u64::from(left.subsec_nanos() > 0)).unwrap_or(u32::MAX).max(1);
        self.system.run_clean(seconds, &[strings(&[SYSTEMCTL]), strings(args), strings(&["--", self.name])].concat())
    }

    fn read(&self) -> Result<Run, String> {
        let shown = self.systemctl(&["show", "--property=LoadState,ActiveState,InvocationID,ActiveEnterTimestampMonotonic,MainPID"])?;
        let value = |name: &str| shown.lines().find_map(|line| line.strip_prefix(name).and_then(|rest| rest.strip_prefix('='))).map(str::trim);
        let (Some(load), Some(active)) = (value("LoadState"), value("ActiveState")) else {
            return Err("systemd gave no state".to_owned());
        };
        Ok(Run {
            load: load.to_owned(),
            active: active.to_owned(),
            invocation: value("InvocationID").unwrap_or_default().to_owned(),
            entered_us: value("ActiveEnterTimestampMonotonic").and_then(|us| us.parse().ok()).unwrap_or_default(),
            main_pid: value("MainPID").and_then(|pid| pid.parse().ok()).unwrap_or_default(),
        })
    }

    /// The unit's run, refused with `unit.not_loaded` unless systemd has loaded it.
    fn loaded(&self, code: &'static str) -> Result<Run, Failure> {
        let run = self.read().map_err(|detail| Failure::new(code, detail))?;
        if run.load != "loaded" {
            return Err(Failure::new("unit.not_loaded", format!("{} load state {}", self.name, run.load)));
        }
        Ok(run)
    }
}

/// Which unit names an order admits.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Names {
    Plain,
    /// Plain names and template instances.
    Templates,
}

/// The unit, checked, and the order's absolute deadline.
fn unit<'a, S: System>(system: &'a S, name: &'a str, names: Names, budget_ms: u64) -> Result<Unit<'a, S>, Failure> {
    let deadline = system.now() + Duration::from_millis(budget_ms);
    let shaped = PLAIN.is_match(name) || (names == Names::Templates && INSTANCE.is_match(name));
    if !shaped || name.len() > MAX_UNIT || name.starts_with(['-', '.']) || !(MIN_BUDGET_MS..=MAX_BUDGET_MS).contains(&budget_ms) {
        return Err(invalid("unit"));
    }
    Ok(Unit { system, name, deadline })
}

/// Restarts the unit once and reads back a new run: `active`, with an `InvocationID` other than the one before, or a later
/// `ActiveEnterTimestampMonotonic` when either run has none. Answers `{"state": "restarted", "invocation_id",
/// "active_enter_timestamp_monotonic"}`.
///
/// # Errors
///
/// `host.input_invalid` for a unit name or budget outside the admitted shape, `unit.not_loaded` for a unit systemd has not
/// loaded, `unit.restart_failed` for a failed read or restart or a new run that failed, and `unit.restart_timeout` when no
/// new active run shows within the budget.
pub fn unit_restart(system: &impl System, input: &serde_json::Value) -> Result<String, Failure> {
    let input: RestartInput = parse(input)?;
    let unit = unit(system, &input.unit, if input.template { Names::Templates } else { Names::Plain }, input.budget_ms)?;
    let failed = |detail: String| Failure::new("unit.restart_failed", detail);
    let before = unit.loaded("unit.restart_failed")?;
    // `--no-block` queues the restart, so the budget, not systemctl, bounds the wait for the new run.
    unit.systemctl(&["restart", "--no-block"]).map_err(failed)?;
    loop {
        let now = match unit.read() {
            Ok(now) => now,
            Err(_) if unit.left().is_zero() => break,
            Err(detail) => return Err(failed(detail)),
        };
        let new = if before.invocation.is_empty() || now.invocation.is_empty() {
            now.entered_us > before.entered_us
        } else {
            now.invocation != before.invocation
        };
        if new && now.active == "active" {
            return Ok(serde_json::json!({
                "state": "restarted",
                "invocation_id": now.invocation,
                "active_enter_timestamp_monotonic": now.entered_us,
            })
            .to_string());
        }
        if new && now.active == "failed" {
            return Err(failed(format!("{} failed after its restart", input.unit)));
        }
        if unit.left().is_zero() {
            break;
        }
        system.sleep(INTERVAL.min(unit.left()));
    }
    Err(Failure::new("unit.restart_timeout", format!("{} showed no new active run within its budget", input.unit)))
}

/// Sends one signal from the fixed set to the unit's main process, once. Answers `{"state": "delivered", "signal",
/// "main_pid"}`.
///
/// # Errors
///
/// `host.input_invalid` for a unit name, signal or budget outside the admitted shape, `unit.not_loaded` for a unit systemd
/// has not loaded, `unit.not_running` for a unit with no main process, and `unit.signal_failed` for a failed read or a
/// signal systemd did not deliver, including one cut off by the budget.
pub fn unit_signal(system: &impl System, input: &serde_json::Value) -> Result<String, Failure> {
    let input: SignalInput = parse(input)?;
    let unit = unit(system, &input.unit, if input.template { Names::Templates } else { Names::Plain }, input.budget_ms)?;
    let before = unit.loaded("unit.signal_failed")?;
    if before.main_pid == 0 {
        return Err(Failure::new("unit.not_running", format!("{} has no main process", input.unit)));
    }
    let signal = format!("--signal={}", input.signal.name());
    unit.systemctl(&["kill", "--kill-who=main", &signal]).map_err(|detail| Failure::new("unit.signal_failed", detail))?;
    Ok(serde_json::json!({"state": "delivered", "signal": input.signal.name(), "main_pid": before.main_pid}).to_string())
}

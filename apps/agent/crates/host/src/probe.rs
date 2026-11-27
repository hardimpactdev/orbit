//! `http.probe`: one HTTP answer, read again within a bounded wait until it meets a typed expectation. Lifted from the
//! Caddy admin read-back of `route.withdraw_site`; the URL, the budget and the expectation come from input.

use std::time::Duration;

use serde::Deserialize;

use crate::{invalid, parse, strings, Failure, System};

/// The wait between two reads.
const INTERVAL: Duration = Duration::from_millis(250);
/// The shortest time a read gets, also when less of the budget is left.
const FLOOR: Duration = Duration::from_millis(500);
/// The longest budget an order may ask for.
const MAX_BUDGET_MS: u64 = 60_000;

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    /// An `http://` or `https://` URL.
    url: String,
    budget_ms: u64,
    /// Text the body must contain, each.
    #[serde(default)]
    contains: Vec<String>,
    /// Text the body must not contain, any.
    #[serde(default)]
    lacks: Vec<String>,
}

/// Reads the URL until a successful answer meets the expectation, or the budget ends. Reads only: it answers `0`.
///
/// # Errors
///
/// `host.input_invalid` for an input outside the admitted shape, and `http.probe_unmet` when no answer within the budget
/// met the expectation.
pub fn probe(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = parse(input)?;
    let scheme = input.url.starts_with("http://") || input.url.starts_with("https://");
    if !scheme
        || input.url.chars().any(|c| c.is_whitespace() || c.is_control())
        || input.budget_ms == 0
        || input.budget_ms > MAX_BUDGET_MS
        || input.contains.iter().chain(&input.lacks).any(String::is_empty)
    {
        return Err(invalid("probe"));
    }
    let unmet = |detail: &str| Failure::new("http.probe_unmet", detail.to_owned());
    let deadline = system.now() + Duration::from_millis(input.budget_ms);
    loop {
        let limit = deadline.saturating_duration_since(system.now()).max(FLOOR);
        let seconds = format!("{:.3}", limit.as_secs_f64());
        let outer = u32::try_from(limit.as_secs()).unwrap_or(u32::MAX).saturating_add(2);
        let last = match system.run(outer, &strings(&["/usr/bin/curl", "--globoff", "--silent", "--show-error", "--fail", "--max-time", &seconds, "--", &input.url])) {
            // An answer that arrives after the budget meets nothing: the wait is bounded by the budget, not by curl's last read.
            Ok(_) if system.now() > deadline => unmet("the answer came after the budget"),
            Ok(body) if input.contains.iter().all(|text| body.contains(text)) && !input.lacks.iter().any(|text| body.contains(text)) => return Ok(0),
            Ok(_) => unmet("the answer does not meet the expectation"),
            Err(_) => unmet("no successful answer"),
        };
        let left = deadline.saturating_duration_since(system.now());
        if left.is_zero() {
            return Err(last);
        }
        system.sleep(INTERVAL.min(left));
        if system.now() >= deadline {
            return Err(last);
        }
    }
}

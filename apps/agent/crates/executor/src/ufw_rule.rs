//! SPIKE: `ufw.ensure_rule`, a desired-state primitive. The Gateway sends one typed rule; the executor reads its own
//! table, places the rule by Orbit's order, applies each change as an argv it builds itself, reads again, checks, and
//! answers in one short line. A port of swamp/extensions/behaviors/operations/firewall/plan.ts.
//!
//! Lines match by tuple; the comment is only for people. UFW stops at the first match, so new lines go above Orbit's
//! managed lines (comment `orbit:<name>`) and broader operator lines, below narrower ones. Each family is placed on its
//! own.

use std::net::IpAddr;
use std::process::Command;
use std::sync::LazyLock;

use orbit_executor_core::journal::Outcome;
use regex::Regex;
use serde::Deserialize;

const UFW: &str = "/usr/sbin/ufw";
const TIMEOUT: &str = "/usr/bin/timeout";

static NUMBERED: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^\[\s*\d+\]\s+(.+)$").expect("regex"));
static ROW: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^(.+?)\s+(ALLOW|DENY|LIMIT|REJECT)(?:\s+(IN|OUT|FWD))?\s+(.+?)(?:\s+#\s*(.*))?$").expect("regex")
});
static PORTS: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^(\d{1,5})(?::(\d{1,5}))?(?:/(tcp|udp))?(?: \(v6\))?$").expect("regex"));

/// The typed input: the rule as the Gateway intends it.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    action: String,
    port: String,
    protocol: String,
    source: String,
    comment: String,
    ensure: String,
    #[serde(default)]
    retain: bool,
}

#[derive(Debug)]
struct Rule {
    action: String,
    port: String,
    protocols: Vec<&'static str>,
    source: String,
    comment: String,
    absent: bool,
    retain: bool,
}

#[derive(Debug, Clone)]
struct Row {
    to: String,
    action: String,
    direction: String,
    source: String,
    comment: String,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
struct Network {
    six: bool,
    base: u128,
    bits: u32,
}

type Refusal = &'static str;

pub(crate) fn ensure_rule(input: &serde_json::Value) -> Outcome {
    let rule = match serde_json::from_value::<Input>(input.clone()).map_err(|_| "ufw.input_invalid").and_then(validated) {
        Ok(rule) => rule,
        Err(code) => return failed(code),
    };
    let before = match status() {
        Ok(stdout) => stdout,
        Err(code) => return failed(code),
    };
    let changes = match inventory(&before).and_then(|rows| plan(&rule, rows)) {
        Ok(changes) => changes,
        Err(code) => return failed(code),
    };
    for argv in &changes {
        match Command::new(TIMEOUT).arg("60").arg(UFW).args(argv).output() {
            Ok(output) if output.status.success() => {}
            Ok(output) => {
                eprintln!("ufw.ensure_rule change failed: {}", String::from_utf8_lossy(&output.stderr).trim());
                return failed("firewall.apply_failed");
            }
            Err(_) => return failed("firewall.apply_failed"),
        }
    }
    let after = if changes.is_empty() {
        before
    } else {
        match status() {
            Ok(stdout) => stdout,
            Err(code) => return failed(code),
        }
    };
    match inventory(&after).and_then(|rows| check(&rule, &rows)) {
        Ok(()) => Outcome::Applied { changes: u32::try_from(changes.len()).unwrap_or(u32::MAX) },
        Err(code) => failed(code),
    }
}

fn validated(input: Input) -> Result<Rule, Refusal> {
    let bad = "firewall.input_invalid";
    if !matches!(input.action.as_str(), "allow" | "deny")
        || !matches!(input.ensure.as_str(), "present" | "absent")
        || !port_shape(&input.port)
        || !comment_shape(&input.comment)
    {
        return Err(bad);
    }
    let protocols = match input.protocol.as_str() {
        "tcp" => vec!["tcp"],
        "udp" => vec!["udp"],
        "any" => vec!["tcp", "udp"],
        _ => return Err(bad),
    };
    if input.source != "any" {
        network(&input.source).ok_or(bad)?;
    }
    Ok(Rule {
        action: input.action,
        port: input.port,
        protocols,
        source: input.source,
        comment: input.comment,
        absent: input.ensure == "absent",
        retain: input.retain,
    })
}

/// The rows of `ufw status numbered`, in order; refuses an inactive firewall.
fn inventory(stdout: &str) -> Result<Vec<Row>, Refusal> {
    if stdout.lines().filter(|line| line.trim() == "Status: active").count() != 1 {
        return Err("firewall.backend_inactive");
    }
    Ok(stdout
        .lines()
        .filter_map(|line| NUMBERED.captures(line.trim()).map(|c| c[1].to_owned()))
        .map(|rest| match ROW.captures(&rest) {
            // Every numbered row occupies an insert position, including unrelated actions and directions.
            Some(c) => Row {
                to: c[1].trim().to_owned(),
                action: c[2].to_lowercase(),
                direction: c.get(3).map_or("in".to_owned(), |m| m.as_str().to_lowercase()),
                source: c[4].trim().to_owned(),
                comment: c.get(5).map_or(String::new(), |m| m.as_str().trim().to_owned()),
            },
            None => Row { to: rest, action: String::new(), direction: "in".to_owned(), source: String::new(), comment: String::new() },
        })
        .collect())
}

fn network(text: &str) -> Option<Network> {
    let (address, prefix) = text.split_once('/').map_or((text, None), |(a, p)| (a, Some(p)));
    let (six, value, width) = match address.parse::<IpAddr>().ok()? {
        IpAddr::V4(v4) => (false, u128::from(u32::from(v4)), 32),
        IpAddr::V6(v6) => (true, u128::from(v6), 128),
    };
    let bits = match prefix {
        None => width,
        Some(p) if !p.is_empty() && p.len() <= 3 && p.bytes().all(|b| b.is_ascii_digit()) => p.parse().ok()?,
        Some(_) => return None,
    };
    if bits > width {
        return None;
    }
    Some(Network { six, base: value & !host_mask(width, bits), bits })
}

fn host_mask(width: u32, bits: u32) -> u128 {
    let host = width - bits;
    if host >= 128 { u128::MAX } else { (1u128 << host) - 1 }
}

fn contains(wide: Network, narrow: Network) -> bool {
    if wide.six != narrow.six || narrow.bits < wide.bits {
        return false;
    }
    let width = if wide.six { 128 } else { 32 };
    (narrow.base & !host_mask(width, wide.bits)) == wide.base
}

/// (argument for `from`, `ufw status` row) per family: anywhere is one explicit line per family.
fn lines(rule: &Rule, protocol: &str) -> Vec<(String, Row)> {
    let to = format!("{}/{protocol}", rule.port);
    let row = |source: &str, suffix: &str, shown: &str| {
        (source.to_owned(), Row {
            to: format!("{to}{suffix}"),
            action: rule.action.clone(),
            direction: "in".to_owned(),
            source: shown.to_owned(),
            comment: rule.comment.clone(),
        })
    };
    if rule.source == "any" {
        return vec![row("0.0.0.0/0", "", "Anywhere"), row("::/0", " (v6)", "Anywhere (v6)")];
    }
    match network(&rule.source) {
        Some(source) if source.bits == 0 => vec![row(
            &rule.source,
            if source.six { " (v6)" } else { "" },
            if source.six { "Anywhere (v6)" } else { "Anywhere" },
        )],
        // ufw marks only a line for anywhere with `(v6)`; a line from one IPv6 address shows the address alone.
        _ => vec![row(&rule.source, "", &rule.source)],
    }
}

fn incoming(row: &Row) -> bool {
    row.direction == "in" && matches!(row.action.as_str(), "allow" | "deny")
}

fn key(row: &Row) -> String {
    let mut source = row.source.clone();
    if incoming(row) && ports(&row.to).is_some() && !source.contains(" on ") {
        if let Some(address) = scope(&source) {
            source = format!("{}:{}/{}", address.six, address.base, address.bits);
        }
    }
    format!("{}\0{}\0{}\0{}", row.to, row.action, row.direction, source)
}

fn v6(row: &Row) -> bool {
    row.to.contains("(v6)") || row.source.contains("(v6)") || row.source.contains(':')
}

fn managed(row: &Row) -> bool {
    row.comment.starts_with("orbit:")
}

fn ports(to: &str) -> Option<(u32, u32, Option<String>)> {
    let c = PORTS.captures(to)?;
    let first: u32 = c[1].parse().ok()?;
    let last: u32 = c.get(2).map_or(Some(first), |m| m.as_str().parse().ok())?;
    Some((first, last, c.get(3).map(|m| m.as_str().to_owned())))
}

fn scope(source: &str) -> Option<Network> {
    let text = source.split(" on ").next().unwrap_or(source);
    let text = text.strip_suffix(" (v6)").unwrap_or(text);
    if text == "Anywhere" {
        return network(if source.ends_with("(v6)") { "::/0" } else { "0.0.0.0/0" });
    }
    network(text)
}

/// Prefer `row` below `other`: a managed line, or a broader overlapping operator line.
fn below(row: &Row, other: &Row) -> bool {
    if !incoming(row) || !incoming(other) || v6(row) != v6(other) {
        return false;
    }
    let (a, b) = (ports(&row.to), ports(&other.to));
    if let (Some(a), Some(b)) = (&a, &b) {
        if a.1 < b.0 || b.1 < a.0 || matches!((&a.2, &b.2), (Some(x), Some(y)) if x != y) {
            return false;
        }
    }
    if managed(row) || managed(other) {
        return managed(row) && !managed(other);
    }
    if a.is_none() || b.is_none() {
        return false;
    }
    match (scope(&row.source), scope(&other.source)) {
        (Some(wide), Some(narrow)) => wide != narrow && contains(wide, narrow),
        _ => false,
    }
}

fn ordered(rows: &[Row], mine: &[String]) -> bool {
    rows.iter().enumerate().all(|(index, row)| {
        !mine.contains(&key(row))
            || rows.iter().enumerate().all(|(position, other)| {
                if !((position < index && below(other, row)) || (position > index && below(row, other))) {
                    return true;
                }
                // Managed ALLOWs cannot change an existing ALLOW's verdict.
                if row.action == "allow" && other.action == "allow" && (managed(row) || managed(other)) {
                    return true;
                }
                // Otherwise a placement preference requires a move only when the sources overlap.
                match (scope(&row.source), scope(&other.source)) {
                    (Some(a), Some(b)) => !contains(a, b) && !contains(b, a),
                    _ => true,
                }
            })
    })
}

fn rule_args(rule: &Rule, protocol: &str, source: &str) -> Vec<String> {
    [rule.action.as_str(), "in", "proto", protocol, "from", source, "to", "any", "port", rule.port.as_str()]
        .map(str::to_owned)
        .to_vec()
}

/// The ufw argv lists (after `ufw`) that converge the rule, in order; numbers count the rows as they will be.
fn plan(rule: &Rule, mut rows: Vec<Row>) -> Result<Vec<Vec<String>>, Refusal> {
    let mut commands = Vec::new();
    for protocol in &rule.protocols {
        for (source, row) in lines(rule, protocol) {
            let mine = key(&row);
            let mut exists = rows.iter().any(|item| key(item) == mine);
            let delete = || [vec!["--force".to_owned(), "delete".to_owned()], rule_args(rule, protocol, &source)].concat();
            if rule.absent {
                if exists && !rule.retain {
                    commands.push(delete());
                    rows.retain(|item| key(item) != mine);
                }
                continue;
            }
            if exists && !ordered(&rows, std::slice::from_ref(&mine)) {
                commands.push(delete());
                rows.retain(|item| key(item) != mine);
                exists = false;
            }
            if exists {
                continue;
            }
            let bare = Row { comment: String::new(), ..row.clone() };
            let mut add = rule_args(rule, protocol, &source);
            add.extend(["comment".to_owned(), rule.comment.clone()]);
            if let Some(index) = rows.iter().position(|other| below(other, &bare)) {
                commands.push([vec!["insert".to_owned(), (index + 1).to_string()], add].concat());
                rows.insert(index, row);
            } else {
                commands.push(add);
                // ufw appends an IPv4 line after the last IPv4 line and an IPv6 line at the end.
                let end = if v6(&row) { rows.len() } else { rows.iter().position(v6).unwrap_or(rows.len()) };
                rows.insert(end, row);
            }
        }
    }
    Ok(commands)
}

fn check(rule: &Rule, rows: &[Row]) -> Result<(), Refusal> {
    let wanted: Vec<String> =
        rule.protocols.iter().flat_map(|protocol| lines(rule, protocol)).map(|(_, row)| key(&row)).collect();
    let present = rows.iter().filter(|row| wanted.contains(&key(row))).count();
    if rule.absent {
        return if !rule.retain && present > 0 { Err("firewall.remove_verify_failed") } else { Ok(()) };
    }
    if present != wanted.len() || !ordered(rows, &wanted) {
        return Err("firewall.verify_failed");
    }
    Ok(())
}

fn port_shape(text: &str) -> bool {
    let number = |t: &str| (!t.is_empty() && t.len() <= 5 && t.bytes().all(|b| b.is_ascii_digit())).then(|| t.parse::<u32>().ok()).flatten();
    match text.split_once(':') {
        None => number(text).is_some_and(|p| (1..=65_535).contains(&p)),
        Some((a, b)) => matches!((number(a), number(b)), (Some(a), Some(b)) if 1 <= a && a <= b && b <= 65_535),
    }
}

fn comment_shape(text: &str) -> bool {
    let Some(rest) = text.strip_prefix("orbit") else { return false };
    rest.is_empty()
        || (rest.starts_with(' ')
            && (2..=65).contains(&rest.len())
            && rest.bytes().all(|b| (0x20..=0x7e).contains(&b) && b != b'\'' && b != b'"'))
}

fn status() -> Result<String, Refusal> {
    match Command::new(TIMEOUT).args(["30", UFW, "status", "numbered"]).output() {
        Ok(output) if output.status.success() => Ok(String::from_utf8_lossy(&output.stdout).into_owned()),
        _ => Err("firewall.read_failed"),
    }
}

fn failed(code: &str) -> Outcome {
    Outcome::Failed { code: code.to_owned() }
}

#[cfg(test)]
mod tests {
    use super::*;

    const TABLE: &str = "Status: active\n\n     To                         Action      From\n     --                         ------      ----\n\
[ 1] 10.45.0.2 on orbit         ALLOW IN    Anywhere                   # orbit:wireguard-members\n\
[ 2] Anywhere                   ALLOW IN    10.232.2.0/24              # proof-network\n\
[ 3] 67/udp on orbitinst0       ALLOW IN    68/udp                     # orbit:instances-dhcp\n\
[ 4] 67/udp (v6) on orbitinst0  ALLOW IN    68/udp (v6)                # orbit:instances-dhcp\n";

    fn rule(json: serde_json::Value) -> Rule {
        validated(serde_json::from_value(json).expect("shape")).expect("valid")
    }

    #[test]
    fn places_a_new_allow_above_managed_lines_in_each_family() {
        let r = rule(serde_json::json!({"action": "allow", "port": "18500", "protocol": "tcp", "source": "any",
            "comment": "orbit spike", "ensure": "present"}));
        let commands = plan(&r, inventory(TABLE).expect("rows")).expect("plan");
        assert_eq!(commands.len(), 2);
        assert_eq!(commands[0].join(" "), "insert 1 allow in proto tcp from 0.0.0.0/0 to any port 18500 comment orbit spike");
        assert_eq!(commands[1][0], "insert");
    }

    #[test]
    fn an_existing_well_placed_rule_plans_nothing() {
        let table = TABLE.replace("[ 1] 10.45", "[ 0] 18500/tcp                  ALLOW IN    Anywhere                   # orbit spike\n[ 1] 10.45")
            + "[ 5] 18500/tcp (v6)             ALLOW IN    Anywhere (v6)              # orbit spike\n";
        let r = rule(serde_json::json!({"action": "allow", "port": "18500", "protocol": "tcp", "source": "any",
            "comment": "orbit spike", "ensure": "present"}));
        let rows = inventory(&table).expect("rows");
        assert!(plan(&r, rows.clone()).expect("plan").is_empty());
        assert!(check(&r, &rows).is_ok());
    }

    #[test]
    fn refuses_an_inactive_firewall_and_bad_input() {
        assert_eq!(inventory("Status: inactive\n").err(), Some("firewall.backend_inactive"));
        assert!(serde_json::from_value::<Input>(serde_json::json!({"action": "allow", "port": "1", "protocol": "tcp",
            "source": "any", "comment": "orbit", "ensure": "present", "argv": ["sh"]})).is_err());
        assert!(validated(serde_json::from_value(serde_json::json!({"action": "allow", "port": "1", "protocol": "tcp",
            "source": "1.2.3.4/40", "comment": "orbit", "ensure": "present"})).expect("shape")).is_err());
    }
}

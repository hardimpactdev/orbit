//! Plans and checks one Orbit allow or deny from `ufw status numbered`: a port of
//! `swamp/extensions/behaviors/operations/firewall/plan.ts` (ADR 0271), with its tests.
//!
//! Lines match by tuple; the comment is only for people. UFW stops at the first match, so new lines prefer positions
//! above Orbit's managed lines (comment `orbit:<name>`) and broader operator lines, below narrower ones: one address,
//! then a CIDR, then anywhere. Existing allows need not cross harmless managed allows. Each family is placed on its own.

use std::net::IpAddr;
use std::sync::LazyLock;

use regex::Regex;
use serde::Deserialize;

static NUMBERED: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^\[\s*\d+\]\s+(.+)$").expect("the numbered-row pattern is a valid regex"));
static ROW: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^(.+?)\s+(ALLOW|DENY|LIMIT|REJECT)(?:\s+(IN|OUT|FWD))?\s+(.+?)(?:\s+#\s*(.*))?$")
        .expect("the row pattern is a valid regex")
});
static PORTS: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^(\d{1,5})(?::(\d{1,5}))?(?:/(tcp|udp))?(?: \(v6\))?$").expect("the ports pattern is a valid regex")
});

/// A refusal's stable code.
pub type Refusal = &'static str;

/// The primitive's typed input: one rule as the Gateway intends it.
#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    /// `allow` or `deny`.
    pub action: String,
    /// One port or a range `first:last`.
    pub port: String,
    /// `tcp`, `udp` or `any`.
    pub protocol: String,
    /// `any`, an address or a CIDR, IPv4 or IPv6.
    pub source: String,
    /// `orbit` or `orbit <text>`.
    pub comment: String,
    /// `present` or `absent`.
    pub ensure: String,
    /// An absent rule keeps its lines: the Node still needs them for another reason.
    #[serde(default)]
    pub retain: bool,
}

/// A checked rule.
#[derive(Debug, Clone)]
pub struct Rule {
    action: String,
    port: String,
    protocols: Vec<&'static str>,
    source: String,
    comment: String,
    absent: bool,
    retain: bool,
}

/// One numbered row of `ufw status`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Row {
    /// The `To` column.
    pub to: String,
    /// `allow`, `deny`, `limit`, `reject`, or empty for an unparsed row.
    pub action: String,
    /// `in`, `out` or `fwd`.
    pub direction: String,
    /// The `From` column.
    pub source: String,
    /// The comment, without `#`.
    pub comment: String,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
struct Network {
    six: bool,
    base: u128,
    bits: u32,
}

/// Checks the input and returns the rule.
///
/// # Errors
///
/// `firewall.input_invalid` for any field outside its shape.
pub fn validated(input: Input) -> Result<Rule, Refusal> {
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

/// The rows of `ufw status numbered`, in order.
///
/// # Errors
///
/// `firewall.backend_inactive` unless the output says once that the firewall is active.
pub fn inventory(stdout: &str) -> Result<Vec<Row>, Refusal> {
    if stdout.lines().filter(|line| line.trim() == "Status: active").count() != 1 {
        return Err("firewall.backend_inactive");
    }
    let text = |captures: &regex::Captures<'_>, group: usize| captures.get(group).map_or("", |found| found.as_str());
    Ok(stdout
        .lines()
        .filter_map(|line| NUMBERED.captures(line.trim()).map(|numbered| text(&numbered, 1).to_owned()))
        .map(|rest| match ROW.captures(&rest) {
            // Every numbered row occupies an insert position, including unrelated actions and directions.
            Some(row) => Row {
                to: text(&row, 1).trim().to_owned(),
                action: text(&row, 2).to_lowercase(),
                direction: row.get(3).map_or_else(|| "in".to_owned(), |found| found.as_str().to_lowercase()),
                source: text(&row, 4).trim().to_owned(),
                comment: text(&row, 5).trim().to_owned(),
            },
            None => Row { to: rest, action: String::new(), direction: "in".to_owned(), source: String::new(), comment: String::new() },
        })
        .collect())
}

fn network(text: &str) -> Option<Network> {
    let (address, prefix) = text.split_once('/').map_or((text, None), |(address, prefix)| (address, Some(prefix)));
    let (six, value, width) = match address.parse::<IpAddr>().ok()? {
        IpAddr::V4(four) => (false, u128::from(u32::from(four)), 32),
        IpAddr::V6(six) => (true, u128::from(six), 128),
    };
    let bits = match prefix {
        None => width,
        Some(digits) if !digits.is_empty() && digits.len() <= 3 && digits.bytes().all(|b| b.is_ascii_digit()) => {
            digits.parse().ok()?
        }
        Some(_) => return None,
    };
    if bits > width {
        return None;
    }
    Some(Network { six, base: value & !host_mask(width, bits), bits })
}

fn host_mask(width: u32, bits: u32) -> u128 {
    match width.checked_sub(bits) {
        Some(128) => u128::MAX,
        Some(host) => (1_u128 << host) - 1,
        None => 0,
    }
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
    let found = PORTS.captures(to)?;
    let first: u32 = found.get(1)?.as_str().parse().ok()?;
    let last: u32 = found.get(2).map_or(Some(first), |last| last.as_str().parse().ok())?;
    Some((first, last, found.get(3).map(|protocol| protocol.as_str().to_owned())))
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
    let (mine, theirs) = (ports(&row.to), ports(&other.to));
    if let (Some(mine), Some(theirs)) = (&mine, &theirs) {
        let protocols_differ = matches!((&mine.2, &theirs.2), (Some(a), Some(b)) if a != b);
        if mine.1 < theirs.0 || theirs.1 < mine.0 || protocols_differ {
            return false;
        }
    }
    if managed(row) || managed(other) {
        return managed(row) && !managed(other);
    }
    if mine.is_none() || theirs.is_none() {
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
                // Managed ALLOWs cannot change an existing ALLOW's verdict. This also holds for trust scoped to an
                // interface or destination: the unrestricted operator rule overlaps it, but agrees with it. A similarly
                // scoped DENY must still force a move.
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

/// The `ufw` argument lists (after `ufw` itself) that converge the rule, in order. Insert positions count the rows
/// as they will be after the earlier commands.
pub fn plan(rule: &Rule, mut rows: Vec<Row>) -> Vec<Vec<String>> {
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
    commands
}

/// Checks the rule against the rows read back after the changes.
///
/// # Errors
///
/// `firewall.verify_failed` when a wanted line is missing or misplaced; `firewall.remove_verify_failed` when an
/// absent rule's line is still there.
pub fn check(rule: &Rule, rows: &[Row]) -> Result<(), Refusal> {
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
    let number = |digits: &str| {
        (!digits.is_empty() && digits.len() <= 5 && digits.bytes().all(|b| b.is_ascii_digit()))
            .then(|| digits.parse::<u32>().ok())
            .flatten()
    };
    match text.split_once(':') {
        None => number(text).is_some_and(|port| (1..=65_535).contains(&port)),
        Some((first, last)) => {
            matches!((number(first), number(last)), (Some(first), Some(last)) if 1 <= first && first <= last && last <= 65_535)
        }
    }
}

fn comment_shape(text: &str) -> bool {
    let Some(rest) = text.strip_prefix("orbit") else { return false };
    rest.is_empty()
        || (rest.starts_with(' ')
            && (2..=65).contains(&rest.len())
            && rest.bytes().all(|b| (0x20..=0x7e).contains(&b) && b != b'\'' && b != b'"'))
}

//! UFW's text formats are a Node concern. Keep malformed owned rows in the inventory so
//! an unreadable rule can never be mistaken for a missing rule and silently replaced.
use regex::Regex;
use serde::{Deserialize, Serialize};
use std::{collections::BTreeSet, net::IpAddr, sync::LazyLock};

#[derive(schemars::JsonSchema, Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(deny_unknown_fields)]
pub struct Shape {
    pub comment: String,
    pub action: String,
    pub direction: String,
    pub source: String,
    pub destination: String,
    pub port: String,
    pub protocol: String,
    pub in_interface: Option<String>,
    pub out_interface: Option<String>,
    pub family: Option<String>,
}

impl Shape {
    pub fn matches(&self, other: &Self) -> bool {
        let mut expected = self.clone();
        if expected.family.is_none() {
            expected.family = other.family.clone();
        }
        expected == *other
    }

    pub fn valid(&self) -> bool {
        self.comment.len() <= 256
            && self
                .comment
                .bytes()
                .all(|b| b.is_ascii_graphic() && b != b'#')
            && ["allow", "deny"].contains(&self.action.as_str())
            && ["in", "out", "fwd"].contains(&self.direction.as_str())
            && ["any", "tcp", "udp"].contains(&self.protocol.as_str())
            && normalize_endpoint(&self.source).as_ref() == Some(&self.source)
            && normalize_endpoint(&self.destination).as_ref() == Some(&self.destination)
            && (self.port == "any" || normalize_port(&self.port).as_ref() == Some(&self.port))
            && self.in_interface.as_deref().is_none_or(valid_interface)
            && self.out_interface.as_deref().is_none_or(valid_interface)
            && self
                .family
                .as_deref()
                .is_none_or(|f| ["v4", "v6"].contains(&f))
            && (self.direction != "in" || self.out_interface.is_none())
            && (self.direction != "out" || self.in_interface.is_none())
            && [&self.source, &self.destination].iter().all(|endpoint| {
                endpoint.as_str() == "any"
                    || self.family.as_deref()
                        == Some(if endpoint.contains(':') { "v6" } else { "v4" })
            })
    }
}

#[derive(Clone, Debug)]
pub struct Row {
    pub number: Option<u32>,
    pub comment: String,
    pub shape: Option<Shape>,
}

#[derive(schemars::JsonSchema, Clone, Copy, Debug, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum Backend {
    Active,
    Inactive,
    Absent,
}

#[derive(schemars::JsonSchema, Clone, Copy, Debug, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum Ownership {
    Exact,
    Missing,
    Drift,
}

pub fn ownership(rows: &[Row], expected: &Shape) -> Ownership {
    let owned: Vec<_> = rows
        .iter()
        .filter(|r| r.comment == expected.comment)
        .collect();
    if owned.is_empty() {
        return Ownership::Missing;
    }
    let mut families = BTreeSet::new();
    for row in owned {
        let Some(shape) = &row.shape else {
            return Ownership::Drift;
        };
        if !expected.matches(shape) || !families.insert(shape.family.as_deref()) {
            return Ownership::Drift;
        }
    }
    let required = match expected.family.as_deref() {
        Some(family) => BTreeSet::from([Some(family)]),
        None => BTreeSet::from([Some("v4"), Some("v6")]),
    };
    if families == required {
        Ownership::Exact
    } else {
        Ownership::Drift
    }
}

pub fn family_comment(comment: &str, prefix: &str) -> bool {
    comment == prefix
        || comment
            .strip_prefix(prefix)
            .is_some_and(|s| s.starts_with(':'))
}

pub fn normalize_port(value: &str) -> Option<String> {
    let parts: Vec<_> = value.split(':').collect();
    if parts.is_empty() || parts.len() > 2 {
        return None;
    }
    let numbers = parts
        .iter()
        .map(|s| {
            if s.is_empty() || s.len() > 5 || !s.bytes().all(|b| b.is_ascii_digit()) {
                return None;
            }
            s.parse::<u16>().ok().filter(|n| *n > 0)
        })
        .collect::<Option<Vec<_>>>()?;
    if numbers.len() == 2 && numbers[0] > numbers[1] {
        return None;
    }
    Some(
        numbers
            .iter()
            .map(u16::to_string)
            .collect::<Vec<_>>()
            .join(":"),
    )
}

pub fn normalize_endpoint(value: &str) -> Option<String> {
    if ["any", "Anywhere", "0.0.0.0/0", "::/0"].contains(&value) {
        return Some("any".into());
    }
    let (address, prefix) = value
        .split_once('/')
        .map_or((value, None), |(a, p)| (a, Some(p)));
    let address: IpAddr = address.parse().ok()?;
    let Some(prefix) = prefix else {
        return Some(address.to_string());
    };
    if prefix.is_empty()
        || !prefix.bytes().all(|b| b.is_ascii_digit())
        || (prefix.len() > 1 && prefix.starts_with('0'))
    {
        return None;
    }
    let prefix: u32 = prefix.parse().ok()?;
    let (network, max) = match address {
        IpAddr::V4(address) if prefix <= 32 => {
            let mask = u32::MAX.checked_shl(32 - prefix).unwrap_or(0);
            (IpAddr::V4((u32::from(address) & mask).into()), 32)
        }
        IpAddr::V6(address) if prefix <= 128 => {
            let mask = u128::MAX.checked_shl(128 - prefix).unwrap_or(0);
            (IpAddr::V6((u128::from(address) & mask).into()), 128)
        }
        _ => return None,
    };
    Some(if prefix == max {
        network.to_string()
    } else {
        format!("{network}/{prefix}")
    })
}

fn valid_interface(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 64
        && value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"_.:-".contains(&b))
}
fn endpoint_interface(value: &str) -> (&str, Option<String>) {
    match value.rsplit_once(" on ") {
        Some((endpoint, interface)) if valid_interface(interface) => {
            (endpoint.trim(), Some(interface.into()))
        }
        _ => (value, None),
    }
}

static LINE: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^(?:\[\s*(\d+)\]\s*)?(.+?)\s+(ALLOW|DENY)\s+(IN|OUT|FWD)\s+(.+?)(?:\s+#\s*(.*))?$")
        .unwrap()
});
static TARGET: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^(?:(.+?)\s+)?(\d{1,5}(?::\d{1,5})?)(?:/(tcp|udp))?$").unwrap());

pub fn live_row(line: &str) -> Row {
    let line = line.trim();
    let comment = line
        .rsplit_once('#')
        .map_or("", |(_, comment)| comment.trim())
        .to_owned();
    let mut row = Row {
        number: None,
        comment: comment.clone(),
        shape: None,
    };
    let Some(c) = LINE.captures(line) else {
        return row;
    };
    if c.get(6).map_or("", |m| m.as_str()) != comment {
        return row;
    }
    row.number = c
        .get(1)
        .and_then(|m| m.as_str().parse::<u32>().ok())
        .filter(|n| *n > 0);
    let family = if c[2].contains("(v6)") || c[5].contains("(v6)") {
        "v6"
    } else {
        "v4"
    };
    let target = c[2].replace("(v6)", "");
    let source = c[5].replace("(v6)", "");
    let (target, target_interface) = endpoint_interface(target.trim());
    let (source, source_interface) = endpoint_interface(source.trim());
    let (destination, port, protocol) = if let Some(t) = TARGET.captures(target) {
        (
            normalize_endpoint(t.get(1).map_or("any", |m| m.as_str())),
            normalize_port(&t[2]),
            t.get(3).map_or("any", |m| m.as_str()).to_owned(),
        )
    } else {
        (normalize_endpoint(target), Some("any".into()), "any".into())
    };
    let (Some(destination), Some(port), Some(source)) =
        (destination, port, normalize_endpoint(source))
    else {
        return row;
    };
    let direction = c[4].to_lowercase();
    let (in_interface, out_interface) = match direction.as_str() {
        "fwd" => (source_interface, target_interface),
        "out" => (None, target_interface.or(source_interface)),
        _ => (target_interface.or(source_interface), None),
    };
    row.shape = Some(Shape {
        comment,
        action: c[3].to_lowercase(),
        direction,
        source,
        destination,
        port,
        protocol,
        in_interface,
        out_interface,
        family: Some(family.into()),
    });
    row
}

pub fn live(text: &str) -> Option<(Backend, Vec<Row>)> {
    let mut backend = None;
    for line in text.lines().map(str::trim) {
        let value = match line.to_ascii_lowercase().as_str() {
            "status: active" => Some(Backend::Active),
            "status: inactive" => Some(Backend::Inactive),
            "status: absent" => Some(Backend::Absent),
            _ => None,
        };
        if let Some(value) = value {
            if backend.replace(value).is_some() {
                return None;
            }
        }
    }
    let rows = text
        .lines()
        .map(live_row)
        .filter(|r| r.shape.is_some() || !r.comment.is_empty())
        .collect();
    Some((backend?, rows))
}

pub fn stored(text: &str, family: &str) -> Vec<Row> {
    text.lines()
        .filter_map(|line| {
            let (body, hex) = line.trim().rsplit_once(" comment=")?;
            if hex.len() % 2 != 0 || !hex.bytes().all(|b| b.is_ascii_hexdigit()) {
                return None;
            }
            let bytes = (0..hex.len())
                .step_by(2)
                .map(|i| u8::from_str_radix(&hex[i..i + 2], 16).ok())
                .collect::<Option<Vec<_>>>()?;
            let comment = String::from_utf8(bytes).ok()?;
            let shape = stored_shape(body, &comment, family);
            Some(Row {
                number: None,
                comment,
                shape,
            })
        })
        .collect()
}

fn stored_shape(body: &str, comment: &str, family: &str) -> Option<Shape> {
    let tokens: Vec<_> = body
        .strip_prefix("### tuple ### ")?
        .split_whitespace()
        .collect();
    let [action, protocol, port, destination, "any", source, interfaces] = tokens.as_slice() else {
        return None;
    };
    let (action, direction, in_interface, out_interface) =
        if let Some(action) = action.strip_prefix("route:") {
            let (incoming, outgoing) = interfaces.strip_prefix("in_")?.split_once("!out_")?;
            (
                action,
                "fwd",
                Some(incoming.to_owned()),
                Some(outgoing.to_owned()),
            )
        } else {
            let (direction, interface) = interfaces
                .split_once('_')
                .map_or((*interfaces, None), |(d, i)| (d, Some(i.to_owned())));
            match direction {
                "in" => (*action, "in", interface, None),
                "out" => (*action, "out", None, interface),
                _ => return None,
            }
        };
    let shape = Shape {
        comment: comment.into(),
        action: action.into(),
        direction: direction.into(),
        source: normalize_endpoint(source)?,
        destination: normalize_endpoint(destination)?,
        port: if *port == "any" {
            "any".into()
        } else {
            normalize_port(port)?
        },
        protocol: (*protocol).into(),
        in_interface,
        out_interface,
        family: Some(family.into()),
    };
    if !shape.valid()
        || [&shape.source, &shape.destination].iter().any(|endpoint| {
            endpoint.as_str() != "any" && endpoint.contains(':') != (family == "v6")
        })
    {
        return None;
    }
    Some(shape)
}

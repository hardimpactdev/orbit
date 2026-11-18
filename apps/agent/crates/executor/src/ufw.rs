//! SPIKE: typed ufw primitives. The Gateway sends a typed input; this module checks every field and builds the ufw
//! argv itself, so no command text ever travels in an order. The plan (what to add, where, what to move) stays in
//! the Gateway's workflow; these primitives only read and change one rule.

use std::io::Write as _;
use std::net::IpAddr;
use std::process::Command;

use base64::engine::general_purpose::STANDARD;
use base64::Engine as _;
use flate2::write::GzEncoder;
use flate2::Compression;

use orbit_executor_core::journal::Outcome;
use serde::Deserialize;

const UFW: &str = "/usr/sbin/ufw";
const TIMEOUT: &str = "/usr/bin/timeout";

/// `ufw.read` takes no input.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct ReadInput {}

/// `ufw.ensure`: one typed rule line.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct EnsureInput {
    rule: String,
    port: String,
    proto: String,
    from: String,
    comment: String,
    #[serde(default)]
    position: Option<u32>,
    #[serde(default)]
    delete: bool,
}

pub(crate) fn read(input: &serde_json::Value) -> Outcome {
    if !input.is_null() && serde_json::from_value::<ReadInput>(input.clone()).is_err() {
        return failed("ufw.input_invalid");
    }
    status()
}

pub(crate) fn ensure(input: &serde_json::Value) -> Outcome {
    let Ok(input) = serde_json::from_value::<EnsureInput>(input.clone()) else {
        return failed("ufw.input_invalid");
    };
    let Some(argv) = argv(&input) else {
        return failed("ufw.input_invalid");
    };
    let output = match Command::new(TIMEOUT).arg("60").arg(UFW).args(&argv).output() {
        Ok(output) => output,
        Err(_) => return failed("ufw.apply_failed"),
    };
    if !output.status.success() {
        eprintln!(
            "ufw.ensure failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        );
        return failed("ufw.apply_failed");
    }
    // A change reports only that it happened; the workflow reads the table once after its last change.
    Outcome::Done
}

/// The argv after `ufw`, or `None` when any field is outside its shape.
fn argv(input: &EnsureInput) -> Option<Vec<String>> {
    if !matches!(input.rule.as_str(), "allow" | "deny")
        || !matches!(input.proto.as_str(), "tcp" | "udp")
        || !port(&input.port)
        || !source(&input.from)
        || !comment(&input.comment)
    {
        return None;
    }
    let rule = [
        input.rule.as_str(),
        "in",
        "proto",
        input.proto.as_str(),
        "from",
        input.from.as_str(),
        "to",
        "any",
        "port",
        input.port.as_str(),
    ];
    let mut argv: Vec<String> = Vec::new();
    if input.delete {
        if input.position.is_some() {
            return None;
        }
        argv.extend(["--force", "delete"].map(str::to_owned));
        argv.extend(rule.map(str::to_owned));
        return Some(argv);
    }
    if let Some(position) = input.position {
        if position == 0 || position > 10_000 {
            return None;
        }
        argv.push("insert".to_owned());
        argv.push(position.to_string());
    }
    argv.extend(rule.map(str::to_owned));
    argv.push("comment".to_owned());
    argv.push(input.comment.clone());
    Some(argv)
}

fn port(text: &str) -> bool {
    let mut parts = text.split(':');
    let first = parts.next().and_then(number);
    let last = parts.next().map(number);
    if parts.next().is_some() {
        return false;
    }
    match (first, last) {
        (Some(first), None) => (1..=65_535).contains(&first),
        (Some(first), Some(Some(last))) => 1 <= first && first <= last && last <= 65_535,
        _ => false,
    }
}

fn number(text: &str) -> Option<u32> {
    if text.is_empty() || text.len() > 5 || !text.bytes().all(|b| b.is_ascii_digit()) {
        return None;
    }
    text.parse().ok()
}

fn source(text: &str) -> bool {
    let (address, prefix) = match text.split_once('/') {
        Some((address, prefix)) => (address, Some(prefix)),
        None => (text, None),
    };
    let Ok(address) = address.parse::<IpAddr>() else {
        return false;
    };
    let width = if address.is_ipv4() { 32 } else { 128 };
    match prefix {
        None => true,
        Some(prefix) => number(prefix).is_some_and(|bits| bits <= width),
    }
}

fn comment(text: &str) -> bool {
    let Some(rest) = text.strip_prefix("orbit") else {
        return false;
    };
    rest.is_empty()
        || (rest.starts_with(' ')
            && (2..=65).contains(&rest.len())
            && rest.bytes().all(|b| (0x20..=0x7e).contains(&b) && b != b'\'' && b != b'"'))
}

fn status() -> Outcome {
    match Command::new(TIMEOUT)
        .args(["30", UFW, "status", "numbered"])
        .output()
    {
        Ok(output) if output.status.success() => match gzip_base64(&output.stdout) {
            Some(stdout) => Outcome::Output {
                stdout,
                encoding: "gzip+base64".to_owned(),
            },
            None => failed("ufw.read_failed"),
        },
        _ => failed("ufw.read_failed"),
    }
}

/// A table of a busy Node passes one Pusher frame as text; compressed it is about a tenth.
fn gzip_base64(bytes: &[u8]) -> Option<String> {
    let mut encoder = GzEncoder::new(Vec::new(), Compression::best());
    encoder.write_all(bytes).ok()?;
    Some(STANDARD.encode(encoder.finish().ok()?))
}

fn failed(code: &str) -> Outcome {
    Outcome::Failed {
        code: code.to_owned(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn input(json: serde_json::Value) -> EnsureInput {
        serde_json::from_value(json).expect("shape")
    }

    #[test]
    fn builds_an_insert_with_its_comment() {
        let argv = argv(&input(serde_json::json!({
            "rule": "allow", "port": "18200", "proto": "tcp", "from": "0.0.0.0/0",
            "comment": "orbit spike", "position": 1
        })))
        .expect("valid");
        assert_eq!(
            argv.join(" "),
            "insert 1 allow in proto tcp from 0.0.0.0/0 to any port 18200 comment orbit spike"
        );
    }

    #[test]
    fn refuses_anything_outside_the_shape() {
        for bad in [
            serde_json::json!({"rule": "allow; rm", "port": "1", "proto": "tcp", "from": "::/0", "comment": "orbit"}),
            serde_json::json!({"rule": "allow", "port": "0", "proto": "tcp", "from": "::/0", "comment": "orbit"}),
            serde_json::json!({"rule": "allow", "port": "1", "proto": "tcp", "from": "1.2.3.4/33", "comment": "orbit"}),
            serde_json::json!({"rule": "allow", "port": "1", "proto": "tcp", "from": "::/0", "comment": "orbit 'x'"}),
            serde_json::json!({"rule": "allow", "port": "1", "proto": "tcp", "from": "::/0", "comment": "orbit", "delete": true, "position": 1}),
        ] {
            assert!(argv(&input(bad)).is_none());
        }
        assert!(serde_json::from_value::<EnsureInput>(serde_json::json!({
            "rule": "allow", "port": "1", "proto": "tcp", "from": "::/0", "comment": "orbit", "argv": ["sh"]
        }))
        .is_err());
    }
}

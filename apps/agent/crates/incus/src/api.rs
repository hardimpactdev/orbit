//! A minimal client of the Incus REST API over its unix socket: one HTTP/1.0 request per connection, so the answer is never
//! chunked and ends when Incus closes the connection.

use std::io::{Read as _, Write as _};
use std::os::unix::net::UnixStream;
use std::path::PathBuf;
use std::time::{Duration, Instant};

use serde_json::Value;

use crate::Failure;

/// The code for a request Incus did not answer as expected.
pub(crate) const API_FAILED: &str = "incus.api_failed";
/// The code for a background operation that did not finish within the order's budget.
pub(crate) const TIMEOUT: &str = "incus.operation_timeout";
/// The largest answer read from Incus.
const MAX_ANSWER: u64 = 4 * 1024 * 1024;

/// The Incus server behind one unix socket, reached within one deadline.
#[derive(Debug, Clone)]
pub struct NodeSystem {
    socket: PathBuf,
    deadline: Instant,
}

/// One answer of the Incus API.
#[derive(Debug, Clone, PartialEq)]
pub(crate) enum Answer {
    /// A finished request: its metadata.
    Sync(Value),
    /// A background operation: its URL, such as `/1.0/operations/<uuid>`.
    Async(String),
    /// An error with its HTTP code.
    Error(u16, String),
}

impl NodeSystem {
    /// The Incus server behind `socket`, every request finished by `deadline`.
    pub fn new(socket: impl Into<PathBuf>, deadline: Instant) -> Self {
        Self {
            socket: socket.into(),
            deadline,
        }
    }

    fn remaining(&self) -> Option<Duration> {
        self.deadline
            .checked_duration_since(Instant::now())
            .filter(|left| !left.is_zero())
    }

    /// Whole seconds left before the deadline, at least one.
    pub(crate) fn seconds_left(&self) -> u64 {
        self.remaining().map_or(1, |left| left.as_secs().max(1))
    }

    /// Sends one request and answers Incus's envelope.
    pub(crate) fn request(
        &self,
        method: &str,
        path: &str,
        body: Option<&Value>,
    ) -> Result<Answer, Failure> {
        let left = self
            .remaining()
            .ok_or_else(|| Failure::new(TIMEOUT, format!("{method} {path}: no time left")))?;
        let io = |error: std::io::Error| {
            let code = if matches!(
                error.kind(),
                std::io::ErrorKind::WouldBlock | std::io::ErrorKind::TimedOut
            ) {
                TIMEOUT
            } else {
                API_FAILED
            };
            Failure::new(code, format!("{method} {path}: {error}"))
        };
        let mut stream = UnixStream::connect(&self.socket).map_err(io)?;
        stream.set_read_timeout(Some(left)).map_err(io)?;
        stream.set_write_timeout(Some(left)).map_err(io)?;
        let content = body.map(Value::to_string).unwrap_or_default();
        let head = format!("{method} {path} HTTP/1.0\r\nHost: incus\r\nContent-Type: application/json\r\nContent-Length: {}\r\n\r\n", content.len());
        stream.write_all(head.as_bytes()).map_err(io)?;
        stream.write_all(content.as_bytes()).map_err(io)?;
        let mut raw = Vec::new();
        stream.take(MAX_ANSWER).read_to_end(&mut raw).map_err(io)?;
        parse(&raw).map_err(|detail| Failure::new(API_FAILED, format!("{method} {path}: {detail}")))
    }
}

/// Parses one HTTP answer that holds an Incus envelope.
fn parse(raw: &[u8]) -> Result<Answer, String> {
    let split = raw
        .windows(4)
        .position(|window| window == b"\r\n\r\n")
        .ok_or("an answer without headers")?;
    let head = std::str::from_utf8(&raw[..split]).map_err(|_| "headers that are not UTF-8")?;
    let status: u16 = head
        .split(' ')
        .nth(1)
        .and_then(|code| code.parse().ok())
        .ok_or("an answer without a status")?;
    if head
        .lines()
        .any(|line| line.to_ascii_lowercase().replace(' ', "") == "transfer-encoding:chunked")
    {
        return Err("a chunked answer".to_owned());
    }
    let envelope: Value = serde_json::from_slice(&raw[split + 4..])
        .map_err(|error| format!("HTTP {status}: an answer that is not JSON: {error}"))?;
    match envelope.get("type").and_then(Value::as_str) {
        Some("sync") if (200..300).contains(&status) => Ok(Answer::Sync(
            envelope.get("metadata").cloned().unwrap_or(Value::Null),
        )),
        Some("async") if (200..300).contains(&status) => envelope
            .get("operation")
            .and_then(Value::as_str)
            .filter(|url| {
                url.starts_with("/1.0/operations/") && !url.contains(['?', ' ', '\r', '\n'])
            })
            .map(|url| Answer::Async(url.to_owned()))
            .ok_or_else(|| "an async answer without an operation".to_owned()),
        Some("error") => {
            let code = envelope
                .get("error_code")
                .and_then(Value::as_u64)
                .and_then(|code| u16::try_from(code).ok())
                .unwrap_or(status);
            Ok(Answer::Error(
                code,
                envelope
                    .get("error")
                    .and_then(Value::as_str)
                    .unwrap_or_default()
                    .to_owned(),
            ))
        }
        _ => Err(format!("HTTP {status}: an unknown envelope")),
    }
}

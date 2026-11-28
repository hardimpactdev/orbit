//! A small Incus REST client over the unix socket: one HTTP/1.1 request per connection, every wait bounded by one deadline.

use std::fmt::Write as _;
use std::io::{ErrorKind, Read as _, Write as _};
use std::os::unix::net::UnixStream;
use std::path::PathBuf;
use std::time::{Duration, Instant};

use serde_json::Value;

use crate::Failure;

/// The most bytes one answer may hold.
const MAX_ANSWER: u64 = 16 * 1024 * 1024;

/// One answer of the Incus API: its HTTP status, its `ETag` and its JSON envelope.
#[derive(Debug)]
pub(crate) struct Answer {
    pub(crate) status: u16,
    pub(crate) etag: Option<String>,
    pub(crate) body: Value,
}

/// The Incus API behind one socket, until one deadline.
#[derive(Debug)]
pub(crate) struct Client {
    socket: PathBuf,
    deadline: Instant,
}

impl Client {
    pub(crate) fn new(socket: PathBuf, budget: Duration) -> Self {
        Self {
            socket,
            deadline: Instant::now() + budget,
        }
    }

    fn left(&self) -> Result<Duration, Failure> {
        self.deadline
            .checked_duration_since(Instant::now())
            .filter(|left| !left.is_zero())
            .ok_or_else(|| Failure::new("incus.timeout", "the time limit passed"))
    }

    /// Sends one request and reads its whole answer.
    pub(crate) fn request(
        &self,
        method: &str,
        path: &str,
        body: Option<&Value>,
        if_match: Option<&str>,
    ) -> Result<Answer, Failure> {
        let left = self.left()?;
        let mut stream = UnixStream::connect(&self.socket).map_err(|error| {
            Failure::new(
                "incus.unreachable",
                format!("{}: {error}", self.socket.display()),
            )
        })?;
        stream
            .set_read_timeout(Some(left))
            .and_then(|()| stream.set_write_timeout(Some(left)))
            .map_err(|error| io(&error))?;
        let payload = body.map(Value::to_string).unwrap_or_default();
        let mut head = format!("{method} {path} HTTP/1.1\r\nHost: incus\r\nConnection: close\r\nAccept: application/json\r\n");
        if body.is_some() {
            let _ = write!(head, "Content-Type: application/json\r\nContent-Length: {}\r\n", payload.len());
        }
        if let Some(etag) = if_match {
            let _ = write!(head, "If-Match: {etag}\r\n");
        }
        head.push_str("\r\n");
        stream
            .write_all(head.as_bytes())
            .and_then(|()| stream.write_all(payload.as_bytes()))
            .map_err(|error| io(&error))?;
        let mut raw = Vec::new();
        stream
            .take(MAX_ANSWER + 1)
            .read_to_end(&mut raw)
            .map_err(|error| io(&error))?;
        if u64::try_from(raw.len()).map_or(true, |length| length > MAX_ANSWER) {
            return Err(Failure::new("incus.api_failed", "the answer is too large"));
        }
        parse(&raw)
    }

    /// Reads one object: its metadata and `ETag`, or `None` when Incus answers 404.
    pub(crate) fn get(&self, path: &str) -> Result<Option<(Value, Option<String>)>, Failure> {
        let answer = self.request("GET", path, None, None)?;
        if answer.status == 404 {
            return Ok(None);
        }
        let metadata = sync(&answer, path)?;
        Ok(Some((metadata, answer.etag)))
    }

    /// Sends one change and, when Incus runs it in the background, waits for its operation within the deadline.
    pub(crate) fn change(
        &self,
        method: &str,
        path: &str,
        body: &Value,
        if_match: Option<&str>,
    ) -> Result<(), Failure> {
        let answer = self.request(method, path, Some(body), if_match)?;
        match answer.body["type"].as_str() {
            Some("sync") if answer.status < 300 => Ok(()),
            Some("async") if answer.status < 300 => {
                let operation = answer.body["operation"]
                    .as_str()
                    .filter(|operation| {
                        operation.starts_with("/1.0/operations/")
                            && !operation.contains(['?', ' ', '\r', '\n'])
                    })
                    .ok_or_else(|| {
                        Failure::new(
                            "incus.api_failed",
                            format!("{method} {path}: no operation in an async answer"),
                        )
                    })?
                    .to_owned();
                self.wait(&operation)
            }
            _ => Err(refused(&answer, &format!("{method} {path}"))),
        }
    }

    fn wait(&self, operation: &str) -> Result<(), Failure> {
        loop {
            // Incus holds the wait at most `timeout` seconds; the socket's own timeout bounds a server that never answers.
            let seconds = self.left()?.as_secs().max(1);
            let path = format!("{operation}/wait?timeout={seconds}");
            let answer = self.request("GET", &path, None, None)?;
            let metadata = sync(&answer, &path)?;
            match metadata["status_code"].as_u64() {
                Some(200) => return Ok(()),
                // Created, started, running, pending: Incus has not finished it within the wait.
                Some(100..=106) => {
                    self.left()?;
                }
                _ => {
                    return Err(Failure::new(
                        "incus.api_failed",
                        format!(
                            "{operation}: {} {}",
                            metadata["status"].as_str().unwrap_or("failed"),
                            metadata["err"].as_str().unwrap_or("")
                        ),
                    ))
                }
            }
        }
    }
}

/// The metadata of a sync answer, or the API's refusal.
pub(crate) fn sync(answer: &Answer, what: &str) -> Result<Value, Failure> {
    if answer.status == 200 && answer.body["type"] == "sync" {
        Ok(answer.body["metadata"].clone())
    } else {
        Err(refused(answer, what))
    }
}

fn refused(answer: &Answer, what: &str) -> Failure {
    Failure::new(
        "incus.api_failed",
        format!(
            "{what}: {} {}",
            answer.status,
            answer.body["error"].as_str().unwrap_or("")
        ),
    )
}

fn io(error: &std::io::Error) -> Failure {
    if matches!(error.kind(), ErrorKind::WouldBlock | ErrorKind::TimedOut) {
        Failure::new("incus.timeout", error.to_string())
    } else {
        Failure::new("incus.api_failed", error.to_string())
    }
}

/// Parses one HTTP/1.1 answer that ends with the connection: its status, `ETag` and JSON body, plain or chunked.
fn parse(raw: &[u8]) -> Result<Answer, Failure> {
    let bad =
        |detail: &str| Failure::new("incus.api_failed", format!("not an HTTP answer: {detail}"));
    let end = raw
        .windows(4)
        .position(|window| window == b"\r\n\r\n")
        .ok_or_else(|| bad("no end of the head"))?;
    let head = std::str::from_utf8(&raw[..end]).map_err(|_| bad("the head is not UTF-8"))?;
    let mut lines = head.split("\r\n");
    let status = lines
        .next()
        .and_then(|line| {
            line.strip_prefix("HTTP/1.1 ")
                .or_else(|| line.strip_prefix("HTTP/1.0 "))
        })
        .and_then(|rest| rest.get(..3))
        .and_then(|code| code.parse::<u16>().ok())
        .ok_or_else(|| bad("no status"))?;
    let (mut etag, mut chunked, mut length) = (None, false, None);
    for line in lines {
        let Some((name, value)) = line.split_once(':') else {
            continue;
        };
        let value = value.trim();
        match name.to_ascii_lowercase().as_str() {
            "etag" => etag = Some(value.to_owned()),
            "transfer-encoding" => chunked = value.eq_ignore_ascii_case("chunked"),
            "content-length" => {
                length = Some(value.parse::<usize>().map_err(|_| bad("a bad length"))?);
            }
            _ => {}
        }
    }
    let rest = &raw[end + 4..];
    let body = if chunked {
        dechunk(rest).ok_or_else(|| bad("a bad chunk"))?
    } else if let Some(length) = length {
        rest.get(..length)
            .ok_or_else(|| bad("a short body"))?
            .to_vec()
    } else {
        rest.to_vec()
    };
    let body = if body.is_empty() {
        Value::Null
    } else {
        serde_json::from_slice(&body).map_err(|error| bad(&error.to_string()))?
    };
    Ok(Answer { status, etag, body })
}

fn dechunk(mut rest: &[u8]) -> Option<Vec<u8>> {
    let mut body = Vec::new();
    loop {
        let line = rest.windows(2).position(|window| window == b"\r\n")?;
        let size = std::str::from_utf8(&rest[..line])
            .ok()?
            .split(';')
            .next()?
            .trim();
        let size = usize::from_str_radix(size, 16).ok()?;
        rest = &rest[line + 2..];
        if size == 0 {
            return Some(body);
        }
        body.extend_from_slice(rest.get(..size)?);
        rest = rest.get(size + 2..)?;
    }
}

//! A small Incus REST client over the unix socket: one HTTP/1.1 request per connection, every wait bounded by one deadline.

use std::fmt::Write as _;
use std::io::{ErrorKind, Read as _, Write as _};
use std::os::unix::net::UnixStream;
use std::path::PathBuf;
use std::sync::mpsc;
use std::thread;
use std::time::{Duration, Instant};

use serde_json::Value;

use crate::{Failure, NAME};

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

    /// Sends one request and reads its whole answer. The connect, every write and every read take only what is left of the
    /// one deadline, so an answer that arrives in fragments cannot stretch the bound.
    pub(crate) fn request(&self, method: &str, path: &str, body: Option<&Value>, if_match: Option<&str>) -> Result<Answer, Failure> {
        let mut stream = self.connect()?;
        let payload = body.map(Value::to_string).unwrap_or_default();
        let mut head = format!("{method} {path} HTTP/1.1\r\nHost: incus\r\nConnection: close\r\nAccept: application/json\r\n");
        if body.is_some() {
            let _ = write!(head, "Content-Type: application/json\r\nContent-Length: {}\r\n", payload.len());
        }
        if let Some(etag) = if_match {
            let _ = write!(head, "If-Match: {etag}\r\n");
        }
        head.push_str("\r\n");
        let mut out = head.into_bytes();
        out.extend_from_slice(payload.as_bytes());
        let mut written = 0;
        while written < out.len() {
            stream.set_write_timeout(Some(self.left()?)).map_err(|error| io(&error))?;
            match stream.write(&out[written..]) {
                Ok(0) => return Err(Failure::new("incus.api_failed", "the socket closed before the request was sent")),
                Ok(count) => written += count,
                Err(error) if error.kind() == ErrorKind::Interrupted => {}
                Err(error) => return Err(io(&error)),
            }
        }
        let mut raw = Vec::new();
        let mut chunk = [0_u8; 16 * 1024];
        loop {
            stream.set_read_timeout(Some(self.left()?)).map_err(|error| io(&error))?;
            match stream.read(&mut chunk) {
                Ok(0) => break,
                Ok(count) => raw.extend_from_slice(&chunk[..count]),
                Err(error) if error.kind() == ErrorKind::Interrupted => {}
                Err(error) => return Err(io(&error)),
            }
            if u64::try_from(raw.len()).map_or(true, |length| length > MAX_ANSWER) {
                return Err(Failure::new("incus.api_failed", "the answer is too large"));
            }
        }
        parse(&raw)
    }

    /// Connects within what is left of the deadline. A unix connect blocks while the daemon's backlog is full, so it runs on
    /// a thread the deadline waits for.
    fn connect(&self) -> Result<UnixStream, Failure> {
        let left = self.left()?;
        let socket = self.socket.clone();
        let (sender, receiver) = mpsc::channel();
        thread::spawn(move || {
            let _ = sender.send(UnixStream::connect(&socket));
        });
        match receiver.recv_timeout(left) {
            Ok(Ok(stream)) => Ok(stream),
            Ok(Err(error)) => Err(Failure::new("incus.unreachable", format!("{}: {error}", self.socket.display()))),
            Err(_) => Err(Failure::new("incus.timeout", "the connect outlasted the time limit")),
        }
    }

    /// The operations still running in a project that name the given resource path, such as a volume being copied.
    pub(crate) fn running(&self, project: &str, resource: &str) -> Result<Vec<String>, Failure> {
        let path = format!("/1.0/operations?project={project}&recursion=1");
        let (operations, _) = self.get(&path)?.ok_or_else(|| Failure::new("incus.api_failed", "no operations list"))?;
        let mut found = Vec::new();
        for operation in operations["running"].as_array().into_iter().flatten() {
            let names = operation["resources"].as_object().into_iter().flat_map(|kinds| kinds.values()).filter_map(Value::as_array).flatten();
            if names.filter_map(Value::as_str).any(|name| name.split('?').next() == Some(resource)) {
                let id = operation["id"].as_str().filter(|id| NAME.is_match(id)).ok_or_else(|| Failure::new("incus.api_failed", "an operation without an id"))?;
                found.push(format!("/1.0/operations/{id}"));
            }
        }
        Ok(found)
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

    /// Sends one change and, when Incus runs it in the background, waits for its operation within the deadline. A `DELETE`
    /// carries no body: Incus answers it without reading one, and unread bytes would reset the connection before the answer.
    pub(crate) fn change(&self, method: &str, path: &str, body: Option<&Value>, if_match: Option<&str>) -> Result<(), Failure> {
        let answer = self.request(method, path, body, if_match)?;
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

    pub(crate) fn wait(&self, operation: &str) -> Result<(), Failure> {
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

//! The executor's own Unix socket and its connection threads.
//!
//! Each connection is checked as a caller first. An allowed caller sends one order; the executor writes an
//! `accepted` line at once and then one result line, and closes the connection.

use std::fs;
use std::io::{self, BufRead as _, BufReader, Read as _, Write as _};
use std::os::unix::fs::{FileTypeExt as _, PermissionsExt as _};
use std::os::unix::net::{UnixListener, UnixStream};
use std::path::Path;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use serde_json::json;

use crate::action::Enabled;
use crate::caller::{self, Policy};
use crate::executor::{Executor, Run, StateRefusal};
use crate::ids::NodeId;
use crate::order::{self, Checks, PinnedKeys, MAX_ORDER_BYTES};

/// The socket's path on a Node.
pub const SOCKET_PATH: &str = "/run/orbit-executor.sock";

/// How many connections the executor serves at once: the observer keeps at most 64 orders in flight.
pub const MAX_CONNECTIONS: usize = 64;

/// How long a caller may take to send its order.
pub const READ_TIMEOUT: Duration = Duration::from_secs(10);

/// Writes one log line to the executor's journal (its standard error under systemd).
pub type Log = fn(&str);

/// What every connection is checked against.
#[derive(Debug)]
pub struct Settings {
    /// Who may connect.
    pub policy: Policy,
    /// The Gateway's pinned keys.
    pub keys: PinnedKeys,
    /// The families this Node enables.
    pub enabled: Enabled,
    /// This Node's id.
    pub node: NodeId,
}

/// Binds the socket at `path` with mode `0660`, owned by root and the relay group when one is given.
/// A stale socket from an earlier run is removed first; any other file at `path` is an error.
///
/// # Errors
///
/// Returns the I/O error of the removal, bind, chmod or chown that failed.
pub fn bind(path: &Path, relay_gid: Option<u32>) -> io::Result<UnixListener> {
    match fs::symlink_metadata(path) {
        Ok(meta) if meta.file_type().is_socket() => fs::remove_file(path)?,
        Ok(_) => {
            return Err(io::Error::new(
                io::ErrorKind::AlreadyExists,
                "a non-socket file holds the socket path",
            ))
        }
        Err(error) if error.kind() == io::ErrorKind::NotFound => {}
        Err(error) => return Err(error),
    }
    let listener = UnixListener::bind(path)?;
    fs::set_permissions(path, fs::Permissions::from_mode(0o660))?;
    if let Some(gid) = relay_gid {
        // Root keeps ownership; the relay group gets connect rights, and the kernel refuses every other user.
        rustix::fs::chown(path, None, Some(rustix::fs::Gid::from_raw(gid)))?;
    }
    Ok(listener)
}

/// The current time in Unix milliseconds.
///
/// # Panics
///
/// Panics when the clock is before 1970 or past the year 584 million, which no Node runs.
pub fn now_ms() -> u64 {
    let since = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .expect("the clock is after 1970");
    u64::try_from(since.as_millis()).expect("milliseconds since 1970 fit u64")
}

/// Serves connections for the life of the process, one thread per connection, at most 64 at once.
pub fn serve<R: Run + 'static>(
    listener: &UnixListener,
    settings: &Arc<Settings>,
    executor: &Arc<Executor<R>>,
    log: Log,
) {
    let active = Arc::new(AtomicUsize::new(0));
    for stream in listener.incoming() {
        let stream = match stream {
            Ok(stream) => stream,
            Err(error) => {
                log(&format!("accept failed: {error}"));
                continue;
            }
        };
        if active.fetch_add(1, Ordering::SeqCst) >= MAX_CONNECTIONS {
            active.fetch_sub(1, Ordering::SeqCst);
            log("refused connection: more than 64 at once");
            continue;
        }
        let (settings, executor, slot) = (
            Arc::clone(settings),
            Arc::clone(executor),
            Arc::clone(&active),
        );
        let spawned = thread::Builder::new()
            .name("order".to_owned())
            .spawn(move || {
                connection(&stream, &settings, &executor, log);
                slot.fetch_sub(1, Ordering::SeqCst);
            });
        if let Err(error) = spawned {
            active.fetch_sub(1, Ordering::SeqCst);
            log(&format!("connection thread failed to start: {error}"));
        }
    }
}

/// Handles one connection: the caller check, the order checks, the action and the reply.
pub fn connection<R: Run>(
    stream: &UnixStream,
    settings: &Settings,
    executor: &Executor<R>,
    log: Log,
) {
    let peer = match caller::identify(stream) {
        Ok(peer) => peer,
        Err(refusal) => {
            log(&format!("refused caller: {}: {refusal}", refusal.code()));
            return;
        }
    };
    let caller = match settings.policy.admit(&peer) {
        Ok(caller) => caller,
        Err(refusal) => {
            log(&format!(
                "refused caller uid={} pid={} cgroup={:?}: {}",
                peer.uid,
                peer.pid,
                peer.cgroup,
                refusal.code()
            ));
            return;
        }
    };
    if let Err(error) = answer(stream, settings, executor, log, caller) {
        log(&format!(
            "connection from {caller:?} pid={} failed: {error}",
            peer.pid
        ));
    }
}

fn answer<R: Run>(
    stream: &UnixStream,
    settings: &Settings,
    executor: &Executor<R>,
    log: Log,
    caller: caller::Caller,
) -> io::Result<()> {
    stream.set_read_timeout(Some(READ_TIMEOUT))?;
    let limit = u64::try_from(MAX_ORDER_BYTES + 1).expect("the order bound fits u64");
    let mut wire = Vec::new();
    BufReader::new(stream.take(limit)).read_until(b'\n', &mut wire)?;
    let mut out = stream;
    writeln!(out, "{}", json!({ "status": "accepted" }))?;

    let now = now_ms();
    let checks = Checks {
        keys: &settings.keys,
        enabled: &settings.enabled,
        node: &settings.node,
    };
    let line = match order::check(&wire, &checks, now) {
        Err(refusal) => {
            log(&format!(
                "refused order from {caller:?}: {}",
                refusal.code()
            ));
            json!({ "status": "refused", "reason": refusal.code(), "node_clock_ms": now })
        }
        Ok(verified) => match executor.handle(&verified, now) {
            Ok(reply) => {
                let mut value = serde_json::to_value(&reply).map_err(io::Error::other)?;
                value["node_clock_ms"] = now.into();
                value
            }
            Err(refusal) => {
                log(&format!(
                    "refused order {} from {caller:?}: {}",
                    verified.operation(),
                    refusal.code()
                ));
                let first = match &refusal {
                    StateRefusal::ReplayedNonce { first } => first
                        .as_deref()
                        .map(serde_json::to_value)
                        .transpose()
                        .map_err(io::Error::other)?,
                    _ => None,
                };
                json!({ "status": "refused", "reason": refusal.code(), "operation": verified.operation(), "first": first, "node_clock_ms": now })
            }
        },
    };
    writeln!(out, "{line}")
}

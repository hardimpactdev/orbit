//! The executor's own Unix socket and its connection threads.
//!
//! Each connection is checked as a caller first. An allowed caller sends one order; the executor writes an
//! `accepted` line at once and then one result line, and closes the connection.

use std::fs;
use std::io::{self, Read as _, Write as _};
use std::os::unix::fs::{DirBuilderExt as _, FileTypeExt as _, PermissionsExt as _};
use std::os::unix::net::{UnixListener, UnixStream};
use std::path::Path;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

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

/// How long a caller may take to send its whole order.
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
///
/// The socket is never reachable with wider rights: it is bound inside a new directory of mode `0700` beside
/// `path`, given its mode and group there, and only then renamed into place. The rename replaces a stale
/// socket of an earlier run; any other file at `path` is an error.
///
/// # Errors
///
/// Returns the I/O error of the directory, bind, chmod, chown or rename that failed.
pub fn bind(path: &Path, relay_gid: Option<u32>) -> io::Result<UnixListener> {
    match fs::symlink_metadata(path) {
        Ok(meta) if meta.file_type().is_socket() => {}
        Ok(_) => {
            return Err(io::Error::new(
                io::ErrorKind::AlreadyExists,
                "a non-socket file holds the socket path",
            ))
        }
        Err(error) if error.kind() == io::ErrorKind::NotFound => {}
        Err(error) => return Err(error),
    }
    let parent = path.parent().ok_or_else(|| {
        io::Error::new(io::ErrorKind::InvalidInput, "the socket path has no folder")
    })?;
    let private = parent.join(format!(".orbit-executor-bind.{}", std::process::id()));
    if fs::symlink_metadata(&private).is_ok() {
        fs::remove_dir_all(&private)?;
    }
    fs::DirBuilder::new().mode(0o700).create(&private)?;
    let staged = private.join("socket");
    let bound =
        stage(&staged, relay_gid).and_then(|listener| fs::rename(&staged, path).map(|()| listener));
    // The folder is empty after the rename; after a failure its leftover socket goes with it.
    let removed = fs::remove_dir_all(&private);
    let listener = bound?;
    removed?;
    Ok(listener)
}

fn stage(staged: &Path, relay_gid: Option<u32>) -> io::Result<UnixListener> {
    let listener = UnixListener::bind(staged)?;
    fs::set_permissions(staged, fs::Permissions::from_mode(0o660))?;
    if let Some(gid) = relay_gid {
        // Root keeps ownership; the relay group gets connect rights, and the kernel refuses every other user.
        rustix::fs::chown(staged, None, Some(rustix::fs::Gid::from_raw(gid)))?;
    }
    Ok(listener)
}

/// Reads one order: bytes up to the first newline, at most `MAX_ORDER_BYTES + 1` of them, within `total`
/// for the whole order, however the caller spreads its bytes.
///
/// # Errors
///
/// Returns [`io::ErrorKind::TimedOut`] when `total` passes first, and any read error.
pub fn read_order(mut stream: &UnixStream, total: Duration) -> io::Result<Vec<u8>> {
    let deadline = Instant::now() + total;
    let mut wire = Vec::new();
    let mut chunk = [0_u8; 1024];
    loop {
        let left = deadline.saturating_duration_since(Instant::now());
        if left.is_zero() {
            return Err(io::Error::new(
                io::ErrorKind::TimedOut,
                "the order did not arrive in time",
            ));
        }
        stream.set_read_timeout(Some(left))?;
        let read = match stream.read(&mut chunk) {
            Ok(read) => read,
            Err(error)
                if matches!(
                    error.kind(),
                    io::ErrorKind::WouldBlock | io::ErrorKind::TimedOut
                ) =>
            {
                return Err(io::Error::new(
                    io::ErrorKind::TimedOut,
                    "the order did not arrive in time",
                ))
            }
            Err(error) if error.kind() == io::ErrorKind::Interrupted => continue,
            Err(error) => return Err(error),
        };
        let bytes = chunk.get(..read).unwrap_or_default();
        if let Some(end) = bytes.iter().position(|byte| *byte == b'\n') {
            wire.extend_from_slice(bytes.get(..end).unwrap_or_default());
            return Ok(wire);
        }
        wire.extend_from_slice(bytes);
        if read == 0 || wire.len() > MAX_ORDER_BYTES {
            return Ok(wire);
        }
    }
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
///
/// # Panics
///
/// A connection thread panics when a lock of the executor's state is poisoned; the binary aborts on any panic.
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
///
/// # Panics
///
/// Panics when a lock of the executor's state is poisoned, which is fatal.
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
    let wire = read_order(stream, READ_TIMEOUT)?;
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_whole_order_has_one_deadline_however_slowly_it_trickles() {
        let (ours, mut theirs) = UnixStream::pair().expect("socket pair");
        let writer = thread::spawn(move || {
            // One byte every 100 ms: each read alone would finish within any per-read timeout.
            for _ in 0..20 {
                if theirs.write_all(b"a").is_err() {
                    return;
                }
                thread::sleep(Duration::from_millis(100));
            }
        });
        let started = Instant::now();
        let read = read_order(&ours, Duration::from_millis(400));
        assert_eq!(
            read.map_err(|error| error.kind()),
            Err(io::ErrorKind::TimedOut)
        );
        assert!(
            started.elapsed() < Duration::from_millis(900),
            "took {:?}",
            started.elapsed()
        );
        drop(ours);
        writer.join().expect("writer ends");
    }

    #[test]
    fn an_order_ends_at_its_newline_or_the_caller_closing() {
        let (ours, mut theirs) = UnixStream::pair().expect("socket pair");
        theirs.write_all(b"abc\nignored").expect("write");
        assert_eq!(read_order(&ours, READ_TIMEOUT).expect("order"), b"abc");
        let (ours, mut theirs) = UnixStream::pair().expect("socket pair");
        theirs.write_all(b"xyz").expect("write");
        drop(theirs);
        assert_eq!(read_order(&ours, READ_TIMEOUT).expect("order"), b"xyz");
    }

    #[test]
    fn the_socket_appears_only_with_its_final_mode_and_replaces_a_stale_one() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("executor.sock");
        drop(bind(&path, None).expect("first bind"));
        let listener = bind(&path, None).expect("a stale socket is replaced");
        let mode = fs::metadata(&path).expect("socket").permissions().mode() & 0o7777;
        assert_eq!(mode, 0o660);
        let names: Vec<_> = fs::read_dir(dir.path())
            .expect("dir")
            .map(|entry| entry.expect("entry").file_name())
            .collect();
        assert_eq!(
            names,
            vec![std::ffi::OsString::from("executor.sock")],
            "the private folder is gone"
        );
        assert!(
            UnixStream::connect(&path).is_ok(),
            "the renamed socket still accepts"
        );
        drop(listener);
    }

    #[test]
    fn a_non_socket_file_at_the_path_is_refused() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("executor.sock");
        fs::write(&path, "x").expect("file");
        assert_eq!(
            bind(&path, None).map(drop).map_err(|error| error.kind()),
            Err(io::ErrorKind::AlreadyExists)
        );
    }
}

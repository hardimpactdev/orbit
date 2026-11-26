//! The Node's publication locks, shared with Swamp's writers: an exclusive `flock` on the same lock file, held until the guard drops.

use std::fs::{File, OpenOptions};
use std::os::unix::fs::OpenOptionsExt as _;
use std::time::{Duration, Instant};

use crate::{Failure, System};

/// How long a writer waits for another one holding the lock, as Swamp's withdrawal does.
const WAIT: Duration = Duration::from_secs(60);

/// Takes the exclusive lock on `path`, or fails with `busy` once it stayed held past the wait.
pub(crate) fn exclusive(system: &impl System, path: &str, busy: &'static str) -> Result<File, Failure> {
    let file = OpenOptions::new()
        .read(true)
        .write(true)
        .create(true)
        .truncate(false)
        .mode(0o600)
        .custom_flags(0o400_000)
        .open(system.path(path))
        .map_err(|error| Failure::new("route.publish_lock_invalid", error.to_string()))?;
    let deadline = Instant::now() + WAIT;
    loop {
        match file.try_lock() {
            Ok(()) => return Ok(file),
            Err(std::fs::TryLockError::WouldBlock) if Instant::now() < deadline => std::thread::sleep(Duration::from_millis(200)),
            Err(std::fs::TryLockError::WouldBlock) => return Err(Failure::new(busy, format!("{path} stayed held past the lock deadline"))),
            Err(std::fs::TryLockError::Error(error)) => return Err(Failure::new("route.publish_lock_failed", error.to_string())),
        }
    }
}

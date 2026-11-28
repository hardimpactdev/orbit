//! A publication lock that other writers of the same files share: an exclusive `flock` on the lock file the Gateway names,
//! held until the guard drops. Lifted from the route and dns families, whose lock paths were fixed in Rust.

use std::fs::{File, OpenOptions};
use std::os::unix::fs::OpenOptionsExt as _;
use std::time::Duration;

use crate::{Failure, System};

/// How long a writer waits for another one holding the lock, as Swamp's writers do.
pub(crate) const WAIT: Duration = Duration::from_secs(60);

/// Takes the exclusive lock on `path`, or fails with `host.lock_busy` once it stayed held past `wait`.
pub(crate) fn exclusive(system: &impl System, path: &str, wait: Duration) -> Result<File, Failure> {
    let file = OpenOptions::new()
        .read(true)
        .write(true)
        .create(true)
        .truncate(false)
        .mode(0o600)
        .custom_flags(crate::fs::NOFOLLOW)
        .open(system.path(path))
        .map_err(|error| Failure::new("host.lock_invalid", error.to_string()))?;
    let deadline = system.now() + wait;
    loop {
        match file.try_lock() {
            Ok(()) => return Ok(file),
            Err(std::fs::TryLockError::WouldBlock) if system.now() < deadline => system.sleep(Duration::from_millis(200)),
            Err(std::fs::TryLockError::WouldBlock) => return Err(Failure::new("host.lock_busy", format!("{path} stayed held past the lock deadline"))),
            Err(std::fs::TryLockError::Error(error)) => return Err(Failure::new("host.lock_failed", error.to_string())),
        }
    }
}

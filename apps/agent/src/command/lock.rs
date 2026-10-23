//! Node-local locks for mutations. Locks follow one order: the target, then the shared resources in
//! sorted order, then the update lock. Each is an exclusive `flock` on a file opened close-on-exec, so
//! project code never inherits one and the kernel releases it when the holder exits.

use std::{
    fs::{File, OpenOptions},
    path::{Path, PathBuf},
    thread,
    time::{Duration, Instant},
};

/// The directory of target and resource locks on Linux. It is not `/run/lock/orbit`, which root-only scripts own.
pub const LOCK_DIR: &str = "/run/lock/orbit-agent";
/// The lock that installation, CLI replacement, and final recipe verification hold.
pub const UPDATE_LOCK_LINUX: &str = "/run/lock/orbit-self-update.lock";

/// The lock directory of this platform. On Linux it is shared by root and the managed account, so it is created
/// world-writable with the sticky bit, like `/run/lock` itself: anyone may add a lock file, none may remove another's.
pub fn lock_directory() -> PathBuf {
    if cfg!(target_os = "linux") {
        let directory = PathBuf::from(LOCK_DIR);
        if !directory.is_dir() && std::fs::create_dir(&directory).is_ok() {
            use std::os::unix::fs::PermissionsExt;
            let _ = std::fs::set_permissions(&directory, std::fs::Permissions::from_mode(0o1777));
        }
        directory
    } else {
        PathBuf::from(std::env::var_os("HOME").unwrap_or_default()).join(".orbit/locks")
    }
}

/// A held lock; dropping it releases the lock.
#[derive(Debug)]
pub struct Held {
    _file: File,
    pub path: PathBuf,
}

#[derive(Debug, PartialEq, Eq)]
pub enum LockError {
    /// The lock stayed held until the deadline.
    Busy(PathBuf),
    Io(PathBuf, String),
}

/// Takes one exclusive lock, waiting until `deadline`.
pub fn acquire(path: &Path, deadline: Instant) -> Result<Held, LockError> {
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent)
            .map_err(|error| LockError::Io(parent.into(), error.to_string()))?;
    }
    let file = OpenOptions::new()
        .create(true)
        .truncate(false)
        .write(true)
        .open(path)
        .map_err(|error| LockError::Io(path.into(), error.to_string()))?;
    loop {
        match rustix::fs::flock(&file, rustix::fs::FlockOperation::NonBlockingLockExclusive) {
            Ok(()) => {
                return Ok(Held {
                    _file: file,
                    path: path.into(),
                })
            }
            Err(rustix::io::Errno::WOULDBLOCK) if Instant::now() < deadline => {
                thread::sleep(Duration::from_millis(100));
            }
            Err(rustix::io::Errno::WOULDBLOCK) => return Err(LockError::Busy(path.into())),
            Err(error) => return Err(LockError::Io(path.into(), error.to_string())),
        }
    }
}

/// The lock file of a canonical target or resource name, such as `instance:12` or `caddy`.
pub fn path_for(directory: &Path, name: &str) -> PathBuf {
    let safe: String = name
        .chars()
        .map(|character| {
            if character.is_ascii_alphanumeric() || "-_".contains(character) {
                character
            } else {
                '_'
            }
        })
        .collect();
    directory.join(format!("{safe}.lock"))
}

/// Takes the target lock and then each resource lock in sorted order.
pub fn acquire_all(
    directory: &Path,
    target: &str,
    resources: &[&str],
    deadline: Instant,
) -> Result<Vec<Held>, LockError> {
    let mut sorted: Vec<&str> = resources.to_vec();
    sorted.sort_unstable();
    sorted.dedup();
    let mut held = vec![acquire(&path_for(directory, target), deadline)?];
    for resource in sorted {
        held.push(acquire(&path_for(directory, resource), deadline)?);
    }
    Ok(held)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn temporary() -> PathBuf {
        let directory = std::env::temp_dir().join(format!(
            "orbit-agent-lock-{}-{}",
            std::process::id(),
            rand::random::<u64>()
        ));
        std::fs::create_dir_all(&directory).unwrap();
        directory
    }

    #[test]
    fn a_second_holder_waits_until_the_deadline_and_reports_busy() {
        let directory = temporary();
        let path = path_for(&directory, "instance:12");
        let _first = acquire(&path, Instant::now()).unwrap();
        let started = Instant::now();
        let second = acquire(&path, Instant::now() + Duration::from_millis(300));
        assert_eq!(second.unwrap_err(), LockError::Busy(path.clone()));
        assert!(started.elapsed() >= Duration::from_millis(300));
    }

    #[test]
    fn a_released_lock_can_be_taken_again() {
        let directory = temporary();
        let path = path_for(&directory, "caddy");
        drop(acquire(&path, Instant::now()).unwrap());
        assert!(acquire(&path, Instant::now()).is_ok());
    }

    #[test]
    fn resources_lock_in_sorted_order_after_the_target() {
        let directory = temporary();
        let held = acquire_all(
            &directory,
            "instance:3",
            &["caddy", "apt", "caddy"],
            Instant::now(),
        )
        .unwrap();
        let names: Vec<String> = held
            .iter()
            .map(|lock| {
                lock.path
                    .file_name()
                    .unwrap()
                    .to_string_lossy()
                    .into_owned()
            })
            .collect();
        assert_eq!(names, ["instance_3.lock", "apt.lock", "caddy.lock"]);
    }

    #[test]
    fn lock_files_are_not_inherited_by_children() {
        let directory = temporary();
        let held = acquire(&path_for(&directory, "target"), Instant::now()).unwrap();
        let fd = std::os::fd::AsRawFd::as_raw_fd(&held._file);
        let flags = rustix::io::fcntl_getfd(&held._file).unwrap();
        assert!(
            flags.contains(rustix::io::FdFlags::CLOEXEC),
            "fd {fd} is inheritable"
        );
    }
}

//! Node-local locks for mutations. Locks follow one order: the target, then the shared resources in
//! sorted order, then the update lock. Each is an exclusive `flock` on a file opened close-on-exec, so
//! project code never inherits one and the kernel releases it when the holder exits.

use std::{
    fs::{File, OpenOptions},
    path::{Path, PathBuf},
    thread,
    time::{Duration, Instant},
};

/// The parent of the per-account lock directories on Linux. Only root writes `/run`, so no account can create or
/// replace it.
pub const LOCK_ROOT_LINUX: &str = "/run/orbit-agent/locks";
/// The variable through which the starting root process gives a supervised mutation its lock directory.
pub const LOCK_DIR_ENV: &str = "ORBIT_AGENT_LOCK_DIR";
/// The lock that installation, CLI replacement, and final recipe verification hold.
pub const UPDATE_LOCK_LINUX: &str = "/run/lock/orbit-self-update.lock";

/// Creates the lock directory of one account under `root`: `<root>/<uid>`, owned by that account with mode 0700, so no
/// other account can hold, replace, or link one of its locks. The root process that starts a Linux mutation calls it.
pub fn prepare(root: &Path, uid: u32, gid: u32) -> std::io::Result<PathBuf> {
    use std::os::unix::fs::DirBuilderExt;
    std::fs::DirBuilder::new()
        .recursive(true)
        .mode(0o755)
        .create(root)?;
    let directory = root.join(uid.to_string());
    match std::fs::DirBuilder::new().mode(0o700).create(&directory) {
        Err(error) if error.kind() != std::io::ErrorKind::AlreadyExists => return Err(error),
        _ => {}
    }
    rustix::fs::chown(
        &directory,
        Some(rustix::fs::Uid::from_raw(uid)),
        Some(rustix::fs::Gid::from_raw(gid)),
    )?;
    Ok(directory)
}

/// The lock directory of this process. On Linux it comes from the starting root process through `LOCK_DIR_ENV`, and
/// it must be a real directory that this account owns with no access for others. On macOS it is the account's own.
pub fn lock_directory() -> Result<PathBuf, String> {
    if !cfg!(target_os = "linux") {
        let directory =
            PathBuf::from(std::env::var_os("HOME").unwrap_or_default()).join(".orbit/locks");
        std::fs::create_dir_all(&directory).map_err(|error| error.to_string())?;
        return Ok(directory);
    }
    let directory = std::env::var_os(LOCK_DIR_ENV)
        .map(PathBuf::from)
        .ok_or("no lock directory was prepared for this command")?;
    private_to_this_account(&directory)?;
    Ok(directory)
}

fn private_to_this_account(directory: &Path) -> Result<(), String> {
    use std::os::unix::fs::{MetadataExt, PermissionsExt};
    let metadata = std::fs::symlink_metadata(directory).map_err(|error| error.to_string())?;
    if !metadata.is_dir() {
        return Err(format!("{} is not a directory", directory.display()));
    }
    if metadata.uid() != super::identity::effective_uid()
        || metadata.permissions().mode() & 0o077 != 0
    {
        return Err(format!(
            "{} is not private to this account",
            directory.display()
        ));
    }
    Ok(())
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
    let file = std::os::unix::fs::OpenOptionsExt::custom_flags(
        OpenOptions::new().create(true).truncate(false).write(true),
        libc::O_NOFOLLOW,
    )
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
    fn a_prepared_directory_is_private_to_its_account_and_others_are_refused() {
        use std::os::unix::fs::PermissionsExt;
        let root = temporary().join("locks");
        let uid = super::super::identity::effective_uid();
        let directory = prepare(&root, uid, unsafe { libc::getegid() }).unwrap();
        assert_eq!(directory, root.join(uid.to_string()));
        assert!(private_to_this_account(&directory).is_ok());
        // Preparing again keeps the same directory.
        assert_eq!(
            prepare(&root, uid, unsafe { libc::getegid() }).unwrap(),
            directory
        );

        std::fs::set_permissions(&directory, std::fs::Permissions::from_mode(0o755)).unwrap();
        assert!(private_to_this_account(&directory).is_err());
        let link = root.join("link");
        std::os::unix::fs::symlink(&directory, &link).unwrap();
        assert!(private_to_this_account(&link).is_err());
    }

    #[test]
    fn a_lock_file_that_is_a_link_is_refused() {
        let directory = temporary();
        let path = path_for(&directory, "target");
        std::os::unix::fs::symlink(directory.join("elsewhere"), &path).unwrap();
        assert!(matches!(
            acquire(&path, Instant::now()),
            Err(LockError::Io(..))
        ));
        assert!(!directory.join("elsewhere").exists());
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

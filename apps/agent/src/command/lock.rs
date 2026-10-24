//! Node-local locks for mutations. Locks follow one order: the target, then the shared resources in
//! sorted order, then the update lock. Each is an exclusive `flock` on a file opened close-on-exec, so
//! project code never inherits one and the kernel releases it when the holder exits.

use std::{
    fs::{File, OpenOptions},
    io::{self, Write},
    os::unix::fs::FileExt,
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

impl Held {
    /// End a synchronous scope before the enclosing service exits. The caller must have waited
    /// for every child of that scope; ordinary command locks retain their record on drop.
    pub fn release_after_children(self) -> Result<(), LockError> {
        self._file
            .set_len(0)
            .map_err(|error| LockError::Io(self.path.clone(), error.to_string()))
    }
}

#[derive(Debug, PartialEq, Eq)]
pub enum LockError {
    /// The lock stayed held until the deadline.
    Busy(PathBuf),
    Io(PathBuf, String),
}

/// Takes one exclusive lock, waiting until `deadline`.
pub fn acquire(path: &Path, deadline: Instant) -> Result<Held, LockError> {
    let cgroup = current_cgroup().map_err(|error| LockError::Io(path.into(), error.to_string()))?;
    acquire_in_cgroup(
        path,
        deadline,
        cgroup.as_deref(),
        Path::new("/sys/fs/cgroup"),
    )
}

/// Only command services outlive their command through systemd cleanup. A startup hook in a
/// long-lived service must not reserve its locks for that service's entire lifetime.
fn current_cgroup() -> io::Result<Option<String>> {
    if !cfg!(target_os = "linux") {
        return Ok(None);
    }
    let membership = std::fs::read_to_string("/proc/self/cgroup")?;
    Ok(membership.lines().find_map(|line| {
        line.strip_prefix("0::")
            .filter(|path| valid_cgroup(path))
            .map(str::to_owned)
    }))
}

fn valid_cgroup(path: &str) -> bool {
    path.len() <= 4096
        && path.starts_with('/')
        && !path.bytes().any(|b| b.is_ascii_control())
        && path
            .split('/')
            .skip(1)
            .all(|part| !matches!(part, "" | "." | ".."))
        && Path::new(path)
            .file_name()
            .and_then(|name| name.to_str())
            .is_some_and(|name| name.starts_with("orbit-command-") && name.ends_with(".service"))
}

fn previous_cgroup(file: &File) -> io::Result<Option<String>> {
    let mut bytes = vec![0; 4098];
    let count = file.read_at(&mut bytes, 0)?;
    if count == 0 {
        return Ok(None);
    }
    let path = std::str::from_utf8(&bytes[..count])
        .ok()
        .and_then(|path| path.strip_suffix('\n'))
        .filter(|path| valid_cgroup(path))
        .ok_or_else(|| io::Error::other("invalid prior command control group"))?;
    Ok(Some(path.into()))
}

fn populated(root: &Path, cgroup: &str) -> io::Result<bool> {
    let events = match std::fs::read_to_string(root.join(&cgroup[1..]).join("cgroup.events")) {
        Ok(events) => events,
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(false),
        Err(error) => return Err(error),
    };
    match events
        .lines()
        .find_map(|line| line.strip_prefix("populated "))
    {
        Some("0") => Ok(false),
        Some("1") => Ok(true),
        _ => Err(io::Error::other(
            "invalid prior command control-group state",
        )),
    }
}

/// Observe a step without creating or changing its lock file. A released flock can still
/// belong to a service whose children are stopping, so admission checks both observations.
pub fn is_busy(path: &Path) -> Result<bool, LockError> {
    busy_in_cgroup(path, Path::new("/sys/fs/cgroup"))
}

fn busy_in_cgroup(path: &Path, root: &Path) -> Result<bool, LockError> {
    use std::os::unix::fs::OpenOptionsExt;
    let failure = |error: io::Error| LockError::Io(path.into(), error.to_string());
    let file = match OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW)
        .open(path)
    {
        Ok(file) => file,
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(false),
        Err(error) => return Err(failure(error)),
    };
    match rustix::fs::flock(&file, rustix::fs::FlockOperation::NonBlockingLockExclusive) {
        Ok(()) => previous_cgroup(&file)
            .map_err(failure)?
            .map(|group| populated(root, &group).map_err(failure))
            .unwrap_or(Ok(false)),
        Err(rustix::io::Errno::WOULDBLOCK) => Ok(true),
        Err(error) => Err(failure(error.into())),
    }
}

fn acquire_in_cgroup(
    path: &Path,
    deadline: Instant,
    cgroup: Option<&str>,
    root: &Path,
) -> Result<Held, LockError> {
    let failure = |error: io::Error| LockError::Io(path.into(), error.to_string());
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent)
            .map_err(|error| LockError::Io(parent.into(), error.to_string()))?;
    }
    let file = std::os::unix::fs::OpenOptionsExt::custom_flags(
        OpenOptions::new()
            .create(true)
            .truncate(false)
            .read(true)
            .write(true),
        libc::O_NOFOLLOW,
    )
    .open(path)
    .map_err(|error| LockError::Io(path.into(), error.to_string()))?;
    loop {
        match rustix::fs::flock(&file, rustix::fs::FlockOperation::NonBlockingLockExclusive) {
            Ok(()) => {
                let previous = previous_cgroup(&file).map_err(failure)?;
                if let Some(previous) =
                    previous.filter(|previous| Some(previous.as_str()) != cgroup)
                {
                    while populated(root, &previous).map_err(failure)? {
                        if Instant::now() >= deadline {
                            return Err(LockError::Busy(path.into()));
                        }
                        thread::sleep(Duration::from_millis(100));
                    }
                }
                // Keep the inode stable. The next holder sees this record under the same flock,
                // even if this process exits before systemd has killed its remaining children.
                let record = cgroup.map(|path| format!("{path}\n")).unwrap_or_default();
                (&file).write_all(record.as_bytes()).map_err(failure)?;
                file.set_len(record.len() as u64).map_err(failure)?;
                return Ok(Held {
                    _file: file,
                    path: path.into(),
                });
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
    fn a_released_flock_still_waits_for_the_previous_services_children() {
        let directory = temporary();
        let path = directory.join("target.lock");
        let root = directory.join("cgroups");
        let first = "/system.slice/orbit-command-first.service";
        let second = "/system.slice/orbit-command-second.service";
        let events = root.join(&first[1..]).join("cgroup.events");
        std::fs::create_dir_all(events.parent().unwrap()).unwrap();
        std::fs::write(&events, "populated 1\nfrozen 0\n").unwrap();
        let held = acquire_in_cgroup(&path, Instant::now(), Some(first), &root).unwrap();
        drop(held);

        assert_eq!(
            acquire_in_cgroup(&path, Instant::now(), Some(second), &root).unwrap_err(),
            LockError::Busy(path.clone()),
        );
        assert_eq!(
            std::fs::read_to_string(&path).unwrap(),
            format!("{first}\n")
        );
        std::fs::write(&events, "populated 0\nfrozen 0\n").unwrap();
        drop(acquire_in_cgroup(&path, Instant::now(), Some(second), &root).unwrap());
        assert_eq!(
            std::fs::read_to_string(&path).unwrap(),
            format!("{second}\n")
        );
        // A collected unit has no cgroup. A caller outside command supervision clears its old record.
        drop(acquire_in_cgroup(&path, Instant::now(), None, &root).unwrap());
        assert!(std::fs::read(&path).unwrap().is_empty());
        std::fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn inspection_observes_prior_children_without_rewriting_the_lock_record() {
        let directory = temporary();
        let path = directory.join("step.lock");
        let group = "/system.slice/orbit-command-prior.service";
        let events = directory.join(&group[1..]).join("cgroup.events");
        assert!(!busy_in_cgroup(&path, &directory).unwrap());
        assert!(!path.exists());
        std::fs::create_dir_all(events.parent().unwrap()).unwrap();
        std::fs::write(&events, "populated 1\n").unwrap();
        drop(acquire_in_cgroup(&path, Instant::now(), Some(group), &directory).unwrap());
        let before = std::fs::read(&path).unwrap();
        assert!(busy_in_cgroup(&path, &directory).unwrap());
        assert_eq!(std::fs::read(&path).unwrap(), before);
        std::fs::write(&events, "populated 0\n").unwrap();
        assert!(!busy_in_cgroup(&path, &directory).unwrap());
        assert_eq!(std::fs::read(&path).unwrap(), before);
        std::fs::write(&events, "invalid\n").unwrap();
        assert!(matches!(
            busy_in_cgroup(&path, &directory),
            Err(LockError::Io(..))
        ));
        assert_eq!(std::fs::read(&path).unwrap(), before);
        std::fs::remove_file(&events).unwrap();
        assert!(!busy_in_cgroup(&path, &directory).unwrap());
        assert_eq!(std::fs::read(&path).unwrap(), before);
        std::fs::write(&path, "invalid record\n").unwrap();
        assert!(matches!(
            busy_in_cgroup(&path, &directory),
            Err(LockError::Io(..))
        ));
        assert_eq!(std::fs::read_to_string(&path).unwrap(), "invalid record\n");
        std::fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn a_command_can_reacquire_its_own_lock_before_its_service_exits() {
        let directory = temporary();
        let cgroup = "/system.slice/orbit-command-current.service";
        let events = directory.join(&cgroup[1..]).join("cgroup.events");
        std::fs::create_dir_all(events.parent().unwrap()).unwrap();
        std::fs::write(&events, "populated 1\n").unwrap();
        let path = directory.join("target.lock");
        for _ in 0..2 {
            drop(acquire_in_cgroup(&path, Instant::now(), Some(cgroup), &directory).unwrap());
        }
        // Compiled startup hooks finish before the parent starts FPM, whose ExecStartPre needs
        // this same lock. Explicit release is safe once those synchronous hooks have returned.
        acquire_in_cgroup(&path, Instant::now(), Some(cgroup), &directory)
            .unwrap()
            .release_after_children()
            .unwrap();
        drop(acquire_in_cgroup(&path, Instant::now(), None, &directory).unwrap());
        std::fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn unreadable_or_invalid_prior_service_state_does_not_grant_the_lock() {
        let directory = temporary();
        let path = directory.join("target.lock");
        let valid = "/system.slice/orbit-command-prior.service";
        for record in [
            "garbage\n",
            "/../../orbit-command-escape.service\n",
            "/system.slice/unrelated.service\n",
        ] {
            std::fs::write(&path, record).unwrap();
            assert!(matches!(
                acquire_in_cgroup(&path, Instant::now(), None, &directory),
                Err(LockError::Io(..))
            ));
            assert_eq!(std::fs::read_to_string(&path).unwrap(), record);
        }
        std::fs::write(&path, format!("{valid}\n")).unwrap();
        let events = directory.join(&valid[1..]).join("cgroup.events");
        std::fs::create_dir_all(events.parent().unwrap()).unwrap();
        std::fs::write(&events, "frozen 0\n").unwrap();
        assert!(matches!(
            acquire_in_cgroup(&path, Instant::now(), None, &directory),
            Err(LockError::Io(..))
        ));
        std::fs::remove_file(&events).unwrap();
        std::fs::create_dir(&events).unwrap();
        assert!(matches!(
            acquire_in_cgroup(&path, Instant::now(), None, &directory),
            Err(LockError::Io(..))
        ));
        std::fs::remove_dir_all(directory).unwrap();
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

    /// Run only by the SPIKE cgroup acceptance driver, in separate transient services.
    #[test]
    #[ignore]
    #[allow(
        clippy::zombie_processes,
        reason = "The fixture must leave its child for systemd to stop and reap after this process exits."
    )]
    fn service_lifetime_worker() {
        use std::io::BufRead;
        let directory = PathBuf::from(std::env::var("ORBIT_LOCK_FIXTURE").unwrap());
        let _held = acquire(
            &directory.join("target.lock"),
            Instant::now() + Duration::from_secs(15),
        )
        .unwrap();
        let mode = std::env::var("ORBIT_LOCK_FIXTURE_MODE").unwrap();
        if mode == "first" || mode == "crash" {
            // A detached project child can survive the main command until systemd finishes stopping its unit.
            let mut child = std::process::Command::new("/usr/bin/python3")
                .args(["-c", "import os,signal,time; os.setsid(); signal.signal(signal.SIGTERM, signal.SIG_IGN); print('ready', flush=True); time.sleep(30)"])
                .stdin(std::process::Stdio::null())
                .stdout(std::process::Stdio::piped())
                .stderr(std::process::Stdio::null())
                .spawn().unwrap();
            let mut ready = String::new();
            std::io::BufReader::new(child.stdout.take().unwrap())
                .read_line(&mut ready)
                .unwrap();
            assert_eq!(ready.trim(), "ready");
            std::fs::write(directory.join("child-pid"), child.id().to_string()).unwrap();
            if mode == "crash" {
                std::fs::write(directory.join("released"), "ready for SIGKILL").unwrap();
                std::thread::sleep(Duration::from_secs(30));
                panic!("the crash fixture was not killed by its driver");
            }
            drop(_held);
            std::fs::write(directory.join("released"), "ready").unwrap();
        } else {
            let pid = std::fs::read_to_string(directory.join("child-pid")).unwrap();
            let status = std::fs::read_to_string(format!("/proc/{pid}/stat"));
            let alive = status.is_ok_and(|s| {
                s.rsplit_once(") ")
                    .is_some_and(|(_, s)| !s.starts_with('Z'))
            });
            assert!(!alive, "a successor acquired the lock while a detached child of the prior service was still alive");
            std::fs::write(directory.join("successor"), "acquired after cleanup").unwrap();
        }
    }
}

//! Node Caddy: the publication lock that Caddy builds and certificate steps share at `/run/lock/orbit/caddy.lock`,
//! a reload under it, and `caddy.install-certificate` and `caddy.remove-certificate` for a role's certificate
//! directory that Caddy reads.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::Path,
    process::{Command as Process, Stdio},
    time::{Duration, Instant},
};

/// The lock a Node Caddy build holds while it swaps the Caddyfile and reloads.
pub const LOCK: &str = "/run/lock/orbit/caddy.lock";

/// Holds the Caddy publication lock, a root-owned 0600 file in a root-owned 0700 directory, for up to 30 seconds.
pub fn lock() -> io::Result<fs::File> {
    lock_at(Path::new(LOCK), 0, 0, Duration::from_secs(30))
}

fn lock_at(path: &Path, uid: u32, gid: u32, wait: Duration) -> io::Result<fs::File> {
    let directory = path.parent().ok_or(io::ErrorKind::InvalidInput)?;
    match fs::DirBuilder::new().mode(0o700).create(directory) {
        Err(error) if error.kind() != io::ErrorKind::AlreadyExists => return Err(error),
        _ => {}
    }
    let metadata = fs::symlink_metadata(directory)?;
    if !metadata.is_dir()
        || metadata.uid() != uid
        || metadata.gid() != gid
        || metadata.mode() & 0o7777 != 0o700
    {
        return Err(io::Error::other(
            "the Caddy lock directory is not private to root",
        ));
    }
    let file = fs::OpenOptions::new()
        .create(true)
        .append(true)
        .mode(0o600)
        .custom_flags(libc::O_NOFOLLOW)
        .open(path)?;
    let metadata = file.metadata()?;
    if !metadata.is_file() || metadata.uid() != uid || metadata.gid() != gid {
        return Err(io::Error::other("the Caddy lock is not a root-owned file"));
    }
    file.set_permissions(fs::Permissions::from_mode(0o600))?;
    let deadline = Instant::now() + wait;
    loop {
        match rustix::fs::flock(&file, rustix::fs::FlockOperation::NonBlockingLockExclusive) {
            Ok(()) => return Ok(file),
            Err(rustix::io::Errno::WOULDBLOCK) if Instant::now() < deadline => {
                std::thread::sleep(Duration::from_millis(50));
            }
            Err(error) => return Err(error.into()),
        }
    }
}

fn systemctl(arguments: &[&str]) -> io::Result<bool> {
    Ok(Process::new("/usr/bin/systemctl")
        .args(arguments)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()?
        .success())
}

command_errors! {
    pub enum ReloadError {
        Lock {} => "caddy.publication_lock_failed", "The Caddy publication lock could not be held.";
        Failed {} => "caddy.reload_failed", "Caddy could not be reloaded.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReloadInput {}

#[derive(Debug, Serialize, PartialEq)]
pub struct Reloaded {}

fn reload_at(
    lock_path: &Path,
    uid: u32,
    gid: u32,
    wait: Duration,
    systemctl: &Path,
) -> Result<Reloaded, ReloadError> {
    let _lock = lock_at(lock_path, uid, gid, wait).map_err(|_| ReloadError::Lock {})?;
    let success = Process::new(systemctl)
        .args(["reload", "caddy"])
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok_and(|status| status.success());
    if success {
        Ok(Reloaded {})
    } else {
        Err(ReloadError::Failed {})
    }
}

pub struct Reload;

impl Command for Reload {
    const NAME: &'static str = "caddy.reload";
    const SUMMARY: &'static str =
        "Reload Caddy under its publication lock to close revoked Metrics streams.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = ReloadInput;
    type Output = Reloaded;
    type Error = ReloadError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(_: &ReloadInput) -> Option<String> {
        Some("caddy".into())
    }

    fn run(_: ReloadInput, context: &Context) -> Result<Reloaded, ReloadError> {
        reload_at(
            Path::new(LOCK),
            0,
            0,
            context.remaining().min(Duration::from_secs(30)),
            Path::new("/usr/bin/systemctl"),
        )
    }
}

/// Reloads `service` under the publication lock when it runs, so a live site picks up a changed certificate.
pub fn reload_if_active(service: &str) -> io::Result<()> {
    if !systemctl(&["is-active", "--quiet", service])? {
        return Ok(());
    }
    let _lock = lock()?;
    if systemctl(&["reload-or-restart", service])? {
        Ok(())
    } else {
        Err(io::Error::other(format!("{service} did not reload")))
    }
}

/// The public key of a PEM certificate or key, as `openssl` prints it.
pub fn public_key(kind: &str, path: &Path) -> Option<Vec<u8>> {
    let arguments: &[&str] = if kind == "certificate" {
        &["x509", "-pubkey", "-noout", "-in"]
    } else {
        &["pkey", "-pubout", "-in"]
    };
    let output = Process::new("/usr/bin/openssl")
        .args(arguments)
        .arg(path)
        .stdin(Stdio::null())
        .stderr(Stdio::null())
        .output()
        .ok()?;
    (output.status.success() && !output.stdout.is_empty()).then_some(output.stdout)
}

pub fn group_id(name: &str) -> Option<u32> {
    crate::command::identity::group_id(name)
}

pub fn chown(path: &Path, uid: u32, gid: u32) -> io::Result<()> {
    rustix::fs::chown(
        path,
        Some(rustix::fs::Uid::from_raw(uid)),
        Some(rustix::fs::Gid::from_raw(gid)),
    )?;
    Ok(())
}

#[cfg(test)]
mod reload_tests {
    use super::*;

    #[test]
    fn lock_is_private_and_refuses_symlinks_and_public_directories() {
        let work = super::super::recipe::WorkDir::new().unwrap();
        let path = work.0.join("locks/caddy.lock");
        let uid = rustix::process::geteuid().as_raw();
        let gid = rustix::process::getegid().as_raw();
        let held = lock_at(&path, uid, gid, Duration::ZERO).unwrap();
        assert_eq!(held.metadata().unwrap().mode() & 0o7777, 0o600);
        assert_eq!(
            fs::metadata(path.parent().unwrap()).unwrap().mode() & 0o7777,
            0o700
        );
        drop(held);
        fs::remove_file(&path).unwrap();
        let outside = work.0.join("outside");
        fs::write(&outside, "keep").unwrap();
        std::os::unix::fs::symlink(&outside, &path).unwrap();
        assert!(lock_at(&path, uid, gid, Duration::ZERO).is_err());
        assert_eq!(fs::read_to_string(&outside).unwrap(), "keep");
        fs::remove_file(&path).unwrap();
        fs::set_permissions(path.parent().unwrap(), fs::Permissions::from_mode(0o755)).unwrap();
        assert!(lock_at(&path, uid, gid, Duration::ZERO).is_err());
        assert!(!path.exists());
    }

    #[test]
    fn reloads_repeatedly_and_refuses_before_execution_when_publication_is_locked() {
        let output = Process::new(std::env::current_exe().unwrap())
            .args([
                "--ignored",
                "--exact",
                "commands::caddy::reload_tests::reload_helper",
                "--nocapture",
            ])
            .output()
            .unwrap();
        assert!(
            output.status.success(),
            "{}{}",
            String::from_utf8_lossy(&output.stdout),
            String::from_utf8_lossy(&output.stderr)
        );
    }

    #[test]
    #[ignore = "runs in a separate process so parallel test forks cannot retain the publication lock"]
    fn reload_helper() {
        let root =
            std::env::temp_dir().join(format!("orbit-caddy-reload-{:016x}", rand::random::<u64>()));
        fs::create_dir(&root).unwrap();
        let program = root.join("systemctl");
        let log = root.join("systemctl.log");
        fs::write(&program, "#!/bin/sh\nprintf '%s\\n' \"$*\" >> \"$0.log\"\n").unwrap();
        fs::set_permissions(&program, fs::Permissions::from_mode(0o755)).unwrap();
        let lock_path = root.join("locks/caddy.lock");
        let uid = rustix::process::geteuid().as_raw();
        let gid = rustix::process::getegid().as_raw();
        let reload = || reload_at(&lock_path, uid, gid, Duration::ZERO, &program);

        let held = lock_at(&lock_path, uid, gid, Duration::ZERO).unwrap();
        assert_eq!(reload(), Err(ReloadError::Lock {}));
        assert!(!log.exists());
        drop(held);
        assert_eq!(reload(), Ok(Reloaded {}));
        assert_eq!(reload(), Ok(Reloaded {}));
        assert_eq!(
            fs::read_to_string(&log).unwrap(),
            "reload caddy\nreload caddy\n"
        );

        fs::write(&program, "#!/bin/sh\nexit 1\n").unwrap();
        assert_eq!(reload(), Err(ReloadError::Failed {}));
        let held = lock_at(&lock_path, uid, gid, Duration::ZERO).unwrap();
        drop(held);
        fs::set_permissions(
            lock_path.parent().unwrap(),
            fs::Permissions::from_mode(0o755),
        )
        .unwrap();
        assert_eq!(reload(), Err(ReloadError::Lock {}));
        assert_eq!(
            fs::read_to_string(&log).unwrap(),
            "reload caddy\nreload caddy\n"
        );
        fs::remove_dir_all(root).unwrap();
    }
}

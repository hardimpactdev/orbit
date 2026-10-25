//! `instances.copy-sqlite`: copies one development SQLite database as the managed account, who owns development
//! checkouts. It refuses a path outside a checkout or through a symbolic link. On one Node it takes a reflink of the
//! database and its WAL while it holds SQLite's write lock, and takes a backup snapshot when the filesystem cannot
//! clone or a writer keeps the lock for two seconds. Between Nodes it exports a snapshot, and installs the copy that
//! the Gateway moved after it checks its size and digest.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use rusqlite::{Connection, OpenFlags};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    fs,
    io::{self, Read},
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Component, Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum CopyError {
        Refused {} => "instances.sqlite_copy_refused", "A path is outside its checkout, passes a symbolic link, or is not the expected file.";
        Failed {} => "instances.sqlite_copy_failed", "The SQLite copy failed.";
    }
}

/// The prefix of an export directory, which only the managed account can enter.
const EXPORT_PREFIX: &str = "/tmp/orbit-sqlite-clone-";
const FICLONE: libc::c_ulong = 0x4004_9409;

#[derive(Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum CopyInput {
    /// Copies `source` in `checkout` to `target` in `target_checkout`, on this Node.
    Local {
        checkout: PathBuf,
        source: PathBuf,
        target_checkout: PathBuf,
        target: PathBuf,
    },
    /// Writes a snapshot of `source` to `directory/snapshot.sqlite`.
    Export {
        checkout: PathBuf,
        source: PathBuf,
        directory: PathBuf,
    },
    /// Creates the directories of `target` inside `target_checkout`.
    Prepare {
        target_checkout: PathBuf,
        target: PathBuf,
    },
    /// Replaces `target` with `incoming` once its size and digest match.
    Install {
        target_checkout: PathBuf,
        target: PathBuf,
        incoming: PathBuf,
        bytes: u64,
        sha256: String,
        mode: u32,
    },
    /// Removes an export directory.
    Cleanup { directory: PathBuf },
    /// Removes a copied database; a missing one is removed already.
    Remove { checkout: PathBuf, path: PathBuf },
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Copied {
    pub bytes: u64,
    pub sha256: String,
    pub mode: u32,
    /// `reflink` or `snapshot`.
    pub copy: &'static str,
}

#[derive(Debug, Serialize, PartialEq)]
#[serde(untagged)]
pub enum CopyOutput {
    Copied(Copied),
    Done {},
}

enum Fault {
    Refused,
    Failed,
    /// An entry on the way is missing.
    Missing,
    /// The filesystem cannot clone, or a writer holds the lock: take a snapshot instead.
    UseSnapshot,
}

impl From<io::Error> for Fault {
    fn from(_: io::Error) -> Self {
        Fault::Failed
    }
}

impl From<rusqlite::Error> for Fault {
    fn from(_: rusqlite::Error) -> Self {
        Fault::Failed
    }
}

type Result<T> = std::result::Result<T, Fault>;

fn refuse<T>() -> Result<T> {
    Err(Fault::Refused)
}

/// An absolute path without `.`, `..`, or empty segments.
fn normalized(path: &Path) -> Result<&Path> {
    let text = path.as_os_str().as_encoded_bytes();
    let clean = path.is_absolute()
        && !text.ends_with(b"/")
        && !text.windows(2).any(|pair| pair == b"//")
        && path
            .components()
            .skip(1)
            .all(|part| matches!(part, Component::Normal(_)));
    if clean {
        Ok(path)
    } else {
        refuse()
    }
}

fn inside<'a>(checkout: &Path, path: &'a Path) -> Result<&'a Path> {
    normalized(checkout)?;
    normalized(path)?;
    if path.starts_with(checkout) && path != checkout {
        Ok(path)
    } else {
        refuse()
    }
}

/// Walks `path` from `/` and refuses a symbolic link or a file on the way. A missing directory below `create_below`
/// is made.
fn walk(path: &Path, create_below: Option<&Path>) -> Result<()> {
    let mut current = PathBuf::from("/");
    for part in normalized(path)?.components().skip(1) {
        current.push(part);
        let metadata = match fs::symlink_metadata(&current) {
            Ok(metadata) => metadata,
            Err(error) if error.kind() == io::ErrorKind::NotFound => {
                match create_below {
                    Some(base) if current.starts_with(base) && current != base => {}
                    _ => return Err(Fault::Missing),
                }
                fs::DirBuilder::new().mode(0o755).create(&current)?;
                fs::symlink_metadata(&current)?
            }
            Err(error) => return Err(error.into()),
        };
        if !metadata.is_dir() {
            return refuse();
        }
    }
    Ok(())
}

fn parent(path: &Path) -> &Path {
    path.parent().unwrap_or(Path::new("/"))
}

fn source_file(checkout: &Path, source: &Path) -> Result<fs::Metadata> {
    inside(checkout, source)?;
    walk(parent(source), None)?;
    let metadata = fs::symlink_metadata(source)?;
    if !metadata.is_file() || metadata.uid() != rustix::process::geteuid().as_raw() {
        return refuse();
    }
    Ok(metadata)
}

pub fn digest(path: &Path) -> io::Result<String> {
    let mut file = fs::File::open(path)?;
    let mut hash = Sha256::new();
    let mut buffer = vec![0; 1 << 20];
    loop {
        let read = file.read(&mut buffer)?;
        if read == 0 {
            break;
        }
        hash.update(&buffer[..read]);
    }
    Ok(format!("{:x}", hash.finalize()))
}

/// A new private file `.orbit-sqlite-*` in `directory`.
fn temporary(directory: &Path) -> Result<(PathBuf, fs::File)> {
    for _ in 0..16 {
        let path = directory.join(format!(".orbit-sqlite-{:016x}", rand::random::<u64>()));
        match fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&path)
        {
            Ok(file) => return Ok((path, file)),
            Err(error) if error.kind() == io::ErrorKind::AlreadyExists => continue,
            Err(error) => return Err(error.into()),
        }
    }
    Err(Fault::Failed)
}

fn open_existing(path: &Path, flags: OpenFlags) -> Result<Connection> {
    Ok(Connection::open_with_flags(
        path,
        flags | OpenFlags::SQLITE_OPEN_NO_MUTEX,
    )?)
}

/// A consistent copy through SQLite's backup, synced to disk.
fn snapshot(source: &Path, directory: &Path) -> Result<PathBuf> {
    let (path, file) = temporary(directory)?;
    let result = (|| {
        let origin = open_existing(source, OpenFlags::SQLITE_OPEN_READ_WRITE)?;
        let mut copy = Connection::open(&path)?;
        rusqlite::backup::Backup::new(&origin, &mut copy)?.run_to_completion(
            i32::MAX,
            Duration::ZERO,
            None,
        )?;
        drop(copy);
        file.sync_all()?;
        Ok(())
    })();
    match result {
        Ok(()) => Ok(path),
        Err(fault) => {
            let _ = fs::remove_file(&path);
            Err(fault)
        }
    }
}

/// A block clone of `source` in `directory`.
fn clone(source: &Path, directory: &Path) -> Result<PathBuf> {
    use std::os::fd::AsRawFd;
    let (path, file) = temporary(directory)?;
    let origin = fs::File::open(source)?;
    // SAFETY: both descriptors are open for this call; FICLONE reads the source and writes only the target.
    if unsafe { libc::ioctl(file.as_raw_fd(), FICLONE as _, origin.as_raw_fd()) } != 0 {
        drop(file);
        let _ = fs::remove_file(&path);
        return Err(Fault::UseSnapshot);
    }
    Ok(path)
}

fn wal(path: &Path) -> PathBuf {
    let mut name = path.as_os_str().to_owned();
    name.push("-wal");
    PathBuf::from(name)
}

fn shm(path: &Path) -> PathBuf {
    let mut name = path.as_os_str().to_owned();
    name.push("-shm");
    PathBuf::from(name)
}

fn reflinked(source: &Path, directory: &Path) -> Result<(PathBuf, Option<PathBuf>)> {
    let log = wal(source);
    let has_log = match fs::symlink_metadata(&log) {
        Ok(metadata) if metadata.is_file() => true,
        Ok(_) => return refuse(),
        Err(_) => false,
    };
    // An unlocked clone proves these filesystems can clone before any writer has to wait.
    fs::remove_file(clone(source, directory)?)?;
    let origin = open_existing(source, OpenFlags::SQLITE_OPEN_READ_WRITE)?;
    origin.busy_timeout(Duration::from_secs(2))?;
    if origin.execute_batch("BEGIN IMMEDIATE").is_err() {
        return Err(Fault::UseSnapshot);
    }
    let cloned = (|| {
        let database = clone(source, directory)?;
        if !has_log {
            return Ok((database, None));
        }
        match clone(&log, directory) {
            Ok(copy) => Ok((database, Some(copy))),
            Err(fault) => {
                let _ = fs::remove_file(&database);
                Err(fault)
            }
        }
    })();
    origin.execute_batch("ROLLBACK")?;
    cloned
}

fn remove_if_present(path: &Path) -> Result<()> {
    match fs::remove_file(path) {
        Err(error) if error.kind() != io::ErrorKind::NotFound => Err(error.into()),
        _ => Ok(()),
    }
}

fn place(temporary: &Path, log: Option<&Path>, target: &Path, mode: u32) -> Result<()> {
    fs::set_permissions(temporary, fs::Permissions::from_mode(mode))?;
    remove_if_present(&shm(target))?;
    match log {
        None => remove_if_present(&wal(target))?,
        Some(log) => {
            fs::set_permissions(log, fs::Permissions::from_mode(mode))?;
            fs::rename(log, wal(target))?;
        }
    }
    fs::rename(temporary, target)?;
    Ok(())
}

fn report(path: &Path, mode: u32, copy: &'static str) -> Result<CopyOutput> {
    Ok(CopyOutput::Copied(Copied {
        bytes: fs::symlink_metadata(path)?.len(),
        sha256: digest(path)?,
        mode,
        copy,
    }))
}

fn export_directory(directory: &Path) -> Result<&Path> {
    if directory
        .as_os_str()
        .as_encoded_bytes()
        .starts_with(EXPORT_PREFIX.as_bytes())
    {
        normalized(directory)
    } else {
        refuse()
    }
}

fn run(input: &CopyInput) -> Result<CopyOutput> {
    match input {
        CopyInput::Local {
            checkout,
            source,
            target_checkout,
            target,
        } => {
            let mode = source_file(checkout, source)?.mode() & 0o7777;
            inside(target_checkout, target)?;
            walk(target_checkout, None)?;
            let directory = parent(target);
            walk(directory, Some(target_checkout))?;
            let (temporary, log, copy) = match reflinked(source, directory) {
                Ok((database, log)) => (database, log, "reflink"),
                Err(Fault::UseSnapshot) => (snapshot(source, directory)?, None, "snapshot"),
                Err(fault) => return Err(fault),
            };
            place(&temporary, log.as_deref(), target, mode)?;
            report(target, mode, copy)
        }
        CopyInput::Export {
            checkout,
            source,
            directory,
        } => {
            let mode = source_file(checkout, source)?.mode() & 0o7777;
            fs::DirBuilder::new()
                .mode(0o700)
                .create(export_directory(directory)?)?;
            let temporary = snapshot(source, directory)?;
            let snapshot = directory.join("snapshot.sqlite");
            fs::rename(temporary, &snapshot)?;
            report(&snapshot, mode, "snapshot")
        }
        CopyInput::Prepare {
            target_checkout,
            target,
        } => {
            inside(target_checkout, target)?;
            walk(target_checkout, None)?;
            walk(parent(target), Some(target_checkout))?;
            Ok(CopyOutput::Done {})
        }
        CopyInput::Install {
            target_checkout,
            target,
            incoming,
            bytes,
            sha256,
            mode,
        } => {
            inside(target_checkout, target)?;
            inside(target_checkout, incoming)?;
            if parent(incoming) != parent(target) {
                return refuse();
            }
            walk(parent(target), None)?;
            let metadata = fs::symlink_metadata(incoming)?;
            if !metadata.is_file() || metadata.uid() != rustix::process::geteuid().as_raw() {
                return refuse();
            }
            if metadata.len() != *bytes || &digest(incoming)? != sha256 {
                return refuse();
            }
            fs::set_permissions(incoming, fs::Permissions::from_mode(mode & 0o666))?;
            fs::rename(incoming, target)?;
            Ok(CopyOutput::Done {})
        }
        CopyInput::Cleanup { directory } => {
            let directory = export_directory(directory)?;
            remove_if_present(&directory.join("snapshot.sqlite"))?;
            match fs::remove_dir(directory) {
                Err(error) if error.kind() != io::ErrorKind::NotFound => Err(error.into()),
                _ => Ok(CopyOutput::Done {}),
            }
        }
        CopyInput::Remove { checkout, path } => {
            inside(checkout, path)?;
            let found = walk(parent(path), None).and_then(|()| {
                fs::symlink_metadata(path).map_err(|error| match error.kind() {
                    io::ErrorKind::NotFound => Fault::Missing,
                    _ => Fault::Failed,
                })
            });
            let metadata = match found {
                Ok(metadata) => metadata,
                Err(Fault::Missing) => return Ok(CopyOutput::Done {}),
                Err(fault) => return Err(fault),
            };
            if !metadata.is_file() {
                return refuse();
            }
            fs::remove_file(path)?;
            Ok(CopyOutput::Done {})
        }
    }
}

pub fn copy(input: &CopyInput) -> std::result::Result<CopyOutput, CopyError> {
    run(input).map_err(|fault| match fault {
        Fault::Refused => CopyError::Refused {},
        Fault::Failed | Fault::Missing | Fault::UseSnapshot => CopyError::Failed {},
    })
}

pub struct CopySqlite;

impl Command for CopySqlite {
    const NAME: &'static str = "instances.copy-sqlite";
    const SUMMARY: &'static str = "Copy, export, install, or remove a development SQLite database.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(600);
    type Input = CopyInput;
    type Output = CopyOutput;
    type Error = CopyError;

    /// The managed account owns every development checkout and runs no project code here.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: CopyInput, _: &Context) -> std::result::Result<CopyOutput, CopyError> {
        copy(&input)
    }
}

#[cfg(test)]
#[path = "sqlite_copy_tests.rs"]
mod tests;

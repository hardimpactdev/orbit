//! `instances.prepare-sqlite-seed` and `instances.install-sqlite-seed`: the two Node halves of a SQLite seed. The
//! source half takes an integrity-checked backup snapshot as the source user and hands it to the transport account;
//! the target half installs the copy that the Gateway moved, as a new file the target user owns, and never replaces
//! one. Each half records its progress in a root-owned state directory, so a retry resumes or cleans up exactly what
//! an earlier run left.

use super::sqlite_copy::digest;
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use rusqlite::{Connection, OpenFlags};
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use std::{
    fs,
    io::{self, Write},
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Component, Path, PathBuf},
    time::{Duration, Instant},
};

command_errors! {
    pub enum SeedError {
        Refused {} => "instances.sqlite_seed_refused", "A seed path, owner, state, or payload does not pass its checks.";
        Failed {} => "instances.sqlite_seed_failed", "The SQLite seed step failed before it changed the target.";
        Unconfirmed {} => "instances.sqlite_seed_unconfirmed", "The SQLite seed was installed, but recording it failed.";
    }
}

const RESERVE: u64 = 1_048_576;

enum Fault {
    Refused,
    Failed,
}

impl From<io::Error> for Fault {
    fn from(_: io::Error) -> Self {
        Fault::Failed
    }
}

impl From<rusqlite::Error> for Fault {
    fn from(_: rusqlite::Error) -> Self {
        // A database that SQLite cannot read is a payload that does not pass its checks.
        Fault::Refused
    }
}

impl From<serde_json::Error> for Fault {
    fn from(_: serde_json::Error) -> Self {
        Fault::Failed
    }
}

type Result<T> = std::result::Result<T, Fault>;

fn refuse<T>() -> Result<T> {
    Err(Fault::Refused)
}

fn require(condition: bool) -> Result<()> {
    if condition {
        Ok(())
    } else {
        refuse()
    }
}

fn not_found(error: &io::Error) -> bool {
    error.kind() == io::ErrorKind::NotFound
}

fn lstat(path: &Path) -> Result<Option<fs::Metadata>> {
    match fs::symlink_metadata(path) {
        Ok(metadata) => Ok(Some(metadata)),
        Err(error) if not_found(&error) => Ok(None),
        Err(error) => Err(error.into()),
    }
}

fn safe_absolute(path: &Path) -> bool {
    let text = path.as_os_str().as_encoded_bytes();
    path.is_absolute()
        && text.len() > 1
        && !text.ends_with(b"/")
        && !text.windows(2).any(|pair| pair == b"//")
        && path
            .components()
            .skip(1)
            .all(|part| matches!(part, Component::Normal(_)))
}

fn euid() -> u32 {
    rustix::process::geteuid().as_raw()
}

struct Account {
    #[cfg(target_os = "linux")]
    name: String,
    uid: u32,
    gid: u32,
    home: PathBuf,
}

fn account(name: &str) -> Result<Account> {
    let (uid, gid, home) = identity::account(name).map_err(|_| Fault::Refused)?;
    Ok(Account {
        #[cfg(target_os = "linux")]
        name: name.to_string(),
        uid,
        gid,
        home: PathBuf::from(home),
    })
}

/// Runs `body` with this thread's effective identity switched to `account`, as the Python program's `seteuid` did.
/// The switch is per thread: the raw system calls do not reach the agent's other threads.
#[cfg(target_os = "linux")]
fn as_account<T>(account: &Account, body: impl FnOnce() -> Result<T>) -> Result<T> {
    let current = euid();
    if current == account.uid {
        return body();
    }
    require(current == 0)?;
    let name = std::ffi::CString::new(account.name.as_str()).map_err(|_| Fault::Refused)?;
    let mut groups = vec![0 as libc::gid_t; 256];
    let mut count = groups.len() as libc::c_int;
    // SAFETY: groups has room for count entries; getgrouplist writes at most that many and updates count.
    if unsafe { libc::getgrouplist(name.as_ptr(), account.gid, groups.as_mut_ptr(), &mut count) }
        < 0
    {
        return refuse();
    }
    let mut saved = vec![0 as libc::gid_t; 256];
    // SAFETY: saved has room for 256 entries.
    let saved_count = unsafe { libc::getgroups(saved.len() as libc::c_int, saved.as_mut_ptr()) };
    require(saved_count >= 0)?;
    saved.truncate(saved_count as usize);
    let none = u32::MAX as libc::c_long;
    // SAFETY: raw setgroups, setresgid, and setresuid change only the calling thread's credentials.
    let switched = unsafe {
        libc::syscall(libc::SYS_setgroups, count as libc::c_long, groups.as_ptr()) == 0
            && libc::syscall(libc::SYS_setresgid, none, account.gid as libc::c_long, none) == 0
            && libc::syscall(libc::SYS_setresuid, none, account.uid as libc::c_long, none) == 0
    };
    let result = if switched { body() } else { Err(Fault::Failed) };
    // SAFETY: as above; the saved root user id permits every switch back.
    let restored = unsafe {
        libc::syscall(libc::SYS_setresuid, none, 0 as libc::c_long, none) == 0
            && libc::syscall(libc::SYS_setresgid, none, 0 as libc::c_long, none) == 0
            && libc::syscall(
                libc::SYS_setgroups,
                saved.len() as libc::c_long,
                saved.as_ptr(),
            ) == 0
    };
    if !restored {
        // A thread that cannot become root again must not act further.
        std::process::abort();
    }
    result
}

#[cfg(not(target_os = "linux"))]
fn as_account<T>(_: &Account, _: impl FnOnce() -> Result<T>) -> Result<T> {
    Err(Fault::Refused)
}

/// Each directory on the way to `path` is a real directory; `path` itself is a directory, or a regular file.
fn safe_tree(path: &Path, final_regular: bool) -> Result<fs::Metadata> {
    let mut current = PathBuf::from("/");
    let parts: Vec<_> = path.components().skip(1).collect();
    for (index, part) in parts.iter().enumerate() {
        current.push(part);
        let metadata = fs::symlink_metadata(&current)?;
        require(!metadata.file_type().is_symlink())?;
        require(index + 1 == parts.len() || metadata.is_dir())?;
    }
    let metadata = fs::symlink_metadata(path)?;
    require(if final_regular {
        metadata.is_file()
    } else {
        metadata.is_dir()
    })?;
    Ok(metadata)
}

/// The root-owned state directory and its parent, private to root. Creates them unless `create` is false.
fn state_directory(state: &Path, create: bool) -> Result<bool> {
    if !state.exists() && !create {
        return Ok(false);
    }
    let parent = state.parent().ok_or(Fault::Refused)?;
    fs::DirBuilder::new()
        .recursive(true)
        .mode(0o700)
        .create(parent)?;
    let private = |path: &Path| -> Result<()> {
        let metadata = fs::symlink_metadata(path)?;
        require(metadata.is_dir() && metadata.uid() == euid() && metadata.mode() & 0o7777 == 0o700)
    };
    private(parent)?;
    if !state.exists() {
        fs::DirBuilder::new().mode(0o700).create(state)?;
    }
    private(state)?;
    Ok(true)
}

fn load_state(file: &Path) -> Result<Option<Map<String, Value>>> {
    let Some(metadata) = lstat(file)? else {
        return Ok(None);
    };
    require(metadata.is_file() && metadata.uid() == euid() && metadata.mode() & 0o7777 == 0o600)?;
    match serde_json::from_slice(&fs::read(file)?)? {
        Value::Object(value) => Ok(Some(value)),
        _ => refuse(),
    }
}

fn write_state(state: &Path, value: &Map<String, Value>) -> Result<()> {
    let temporary = state.join(format!(".state-{:016x}", rand::random::<u64>()));
    let result = (|| {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&temporary)?;
        file.write_all(&serde_json::to_vec(value)?)?;
        file.sync_all()?;
        fs::rename(&temporary, state.join("state.json"))?;
        fs::File::open(state)?.sync_all()?;
        Ok(())
    })();
    let _ = fs::remove_file(&temporary);
    result
}

fn int(value: &Map<String, Value>, key: &str) -> Option<u64> {
    value.get(key).and_then(Value::as_u64)
}

fn text<'a>(value: &'a Map<String, Value>, key: &str) -> Option<&'a str> {
    value.get(key).and_then(Value::as_str)
}

fn valid_digest(text: &str) -> bool {
    text.len() == 64
        && text
            .bytes()
            .all(|byte| matches!(byte, b'0'..=b'9' | b'a'..=b'f'))
}

fn sync(path: &Path) -> Result<()> {
    fs::OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW)
        .open(path)?
        .sync_all()?;
    Ok(())
}

fn available(path: &Path) -> Result<(u64, bool)> {
    let statistics = rustix::fs::statvfs(path).map_err(|_| Fault::Failed)?;
    Ok((
        statistics.f_bavail * statistics.f_frsize,
        statistics
            .f_flag
            .contains(rustix::fs::StatVfsMountFlags::RDONLY),
    ))
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SourceInput {
    /// `prepare` or `cleanup`.
    pub mode: String,
    pub base: PathBuf,
    pub source_path: PathBuf,
    pub source_user: String,
    pub transport_user: String,
    pub operation: String,
    pub state_dir: PathBuf,
    pub snapshot_path: PathBuf,
}

#[derive(Debug, Serialize, PartialEq)]
#[serde(untagged)]
pub enum SourceOutput {
    Ready {
        bytes: u64,
        sha256: String,
        snapshot: PathBuf,
    },
    Cleaned {},
}

impl SourceInput {
    fn expected(&self, state: &Map<String, Value>) -> Result<()> {
        require(
            text(state, "operation") == Some(self.operation.as_str())
                && text(state, "base") == self.base.to_str()
                && text(state, "source_path") == self.source_path.to_str()
                && text(state, "snapshot_path") == self.snapshot_path.to_str(),
        )
    }

    fn validate_snapshot(
        &self,
        state: &Map<String, Value>,
        transport: &Account,
    ) -> Result<Option<fs::Metadata>> {
        let Some(metadata) = lstat(&self.snapshot_path)? else {
            return Ok(None);
        };
        require(
            metadata.is_file()
                && metadata.uid() == transport.uid
                && metadata.mode() & 0o7777 == 0o600
                && Some(metadata.ino()) == int(state, "inode")
                && Some(metadata.len()) == int(state, "size")
                && Some(digest(&self.snapshot_path)?.as_str()) == text(state, "digest"),
        )?;
        Ok(Some(metadata))
    }

    fn remove_snapshot(
        &self,
        state: &Map<String, Value>,
        source: &Account,
        transport: &Account,
    ) -> Result<()> {
        let Some(metadata) = lstat(&self.snapshot_path)? else {
            return Ok(());
        };
        require(
            metadata.is_file() && (metadata.uid() == source.uid || metadata.uid() == transport.uid),
        )?;
        if let Some(inode) = int(state, "inode") {
            require(metadata.ino() == inode)?;
        }
        fs::remove_file(&self.snapshot_path)?;
        Ok(())
    }

    fn record(&self, status: &str) -> Map<String, Value> {
        let mut value = Map::new();
        value.insert("base".into(), json!(self.base));
        value.insert("operation".into(), json!(self.operation));
        value.insert("snapshot_path".into(), json!(self.snapshot_path));
        value.insert("source_path".into(), json!(self.source_path));
        value.insert("status".into(), json!(status));
        value
    }
}

fn prepare_source(input: &SourceInput) -> Result<SourceOutput> {
    require(
        safe_absolute(&input.base)
            && safe_absolute(&input.source_path)
            && safe_absolute(&input.snapshot_path),
    )?;
    require(input.source_path.starts_with(&input.base) && input.source_path != input.base)?;
    let source = account(&input.source_user)?;
    let transport = account(&input.transport_user)?;
    let state_file = input.state_dir.join("state.json");
    if input.mode == "cleanup" {
        if !state_directory(&input.state_dir, false)? {
            require(lstat(&input.snapshot_path)?.is_none())?;
            return Ok(SourceOutput::Cleaned {});
        }
        match load_state(&state_file)? {
            None => require(lstat(&input.snapshot_path)?.is_none())?,
            Some(state) => {
                input.expected(&state)?;
                input.remove_snapshot(&state, &source, &transport)?;
                fs::remove_file(&state_file)?;
            }
        }
        fs::remove_dir(&input.state_dir)?;
        return Ok(SourceOutput::Cleaned {});
    }
    require(input.mode == "prepare")?;
    if input.state_dir.exists() {
        state_directory(&input.state_dir, true)?;
    }
    match load_state(&state_file)? {
        Some(state) => {
            input.expected(&state)?;
            if text(&state, "status") == Some("ready") {
                match input.validate_snapshot(&state, &transport)? {
                    Some(metadata) => {
                        return Ok(SourceOutput::Ready {
                            bytes: metadata.len(),
                            sha256: text(&state, "digest").unwrap_or_default().to_string(),
                            snapshot: input.snapshot_path.clone(),
                        })
                    }
                    None => fs::remove_file(&state_file)?,
                }
            } else {
                input.remove_snapshot(&state, &source, &transport)?;
                fs::remove_file(&state_file)?;
            }
        }
        None => require(lstat(&input.snapshot_path)?.is_none())?,
    }

    let (origin, page_size, available) = as_account(&source, || {
        safe_tree(&input.base, false)?;
        safe_tree(&input.source_path, true)?;
        let opened = fs::OpenOptions::new()
            .read(true)
            .custom_flags(libc::O_NONBLOCK | libc::O_NOFOLLOW)
            .open(&input.source_path)?
            .metadata()?;
        require(opened.is_file())?;
        let origin = Connection::open_with_flags(
            &input.source_path,
            OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_NO_MUTEX,
        )?;
        origin.busy_timeout(Duration::from_secs(5))?;
        origin.execute_batch("PRAGMA query_only = ON")?;
        let check: String = origin.query_row("PRAGMA quick_check", [], |row| row.get(0))?;
        require(check == "ok")?;
        let page_size: i64 = origin.query_row("PRAGMA page_size", [], |row| row.get(0))?;
        let page_size = u64::try_from(page_size).map_err(|_| Fault::Refused)?;
        let page_count: i64 = origin.query_row("PRAGMA page_count", [], |row| row.get(0))?;
        let page_count = u64::try_from(page_count).map_err(|_| Fault::Refused)?;
        let required = opened.len().max(page_size * page_count);
        let (available, _) = available(Path::new("/tmp"))?;
        require(required > 0 && available >= required + RESERVE)?;
        Ok((origin, page_size, available))
    })?;

    state_directory(&input.state_dir, true)?;
    write_state(&input.state_dir, &input.record("preparing"))?;

    as_account(&source, || {
        // Create the private database before SQLite opens it. umask is process-wide,
        // so changing it here would also change unrelated threads' file permissions.
        let snapshot = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&input.snapshot_path)?;
        drop(snapshot);
        let mut destination = Connection::open_with_flags(
            &input.snapshot_path,
            OpenFlags::SQLITE_OPEN_READ_WRITE | OpenFlags::SQLITE_OPEN_NO_MUTEX,
        )?;
        let maximum_pages = (available - RESERVE) / page_size;
        let deadline = Instant::now() + Duration::from_secs(30);
        {
            let backup = rusqlite::backup::Backup::new(&origin, &mut destination)?;
            loop {
                let step = backup.step(-1)?;
                require(backup.progress().pagecount as u64 <= maximum_pages)?;
                if step == rusqlite::backup::StepResult::Done {
                    break;
                }
                require(Instant::now() < deadline)?;
                std::thread::sleep(Duration::from_millis(10));
            }
        }
        let integrity: String =
            destination.query_row("PRAGMA integrity_check", [], |row| row.get(0))?;
        require(integrity == "ok")?;
        drop(destination);
        sync(&input.snapshot_path)
    })?;
    drop(origin);

    rustix::fs::chown(
        &input.snapshot_path,
        Some(rustix::fs::Uid::from_raw(transport.uid)),
        Some(rustix::fs::Gid::from_raw(transport.gid)),
    )
    .map_err(|_| Fault::Failed)?;
    fs::set_permissions(&input.snapshot_path, fs::Permissions::from_mode(0o600))?;
    let metadata = fs::symlink_metadata(&input.snapshot_path)?;
    let sha256 = digest(&input.snapshot_path)?;
    let mut state = input.record("ready");
    state.insert("digest".into(), json!(sha256));
    state.insert("inode".into(), json!(metadata.ino()));
    state.insert("size".into(), json!(metadata.len()));
    write_state(&input.state_dir, &state)?;
    Ok(SourceOutput::Ready {
        bytes: metadata.len(),
        sha256,
        snapshot: input.snapshot_path.clone(),
    })
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TargetInput {
    /// `prepare`, `install`, or `cleanup`.
    pub mode: String,
    pub base: PathBuf,
    pub target_user: String,
    pub transport_user: String,
    pub operation: String,
    pub state_dir: PathBuf,
    pub expected_size: u64,
    pub expected_digest: String,
    /// `production` or `development`.
    pub environment: String,
    pub relative_path: String,
}

#[derive(Debug, Serialize, PartialEq)]
#[serde(tag = "state", rename_all = "snake_case")]
pub enum TargetOutput {
    /// The transport account writes the snapshot to `incoming`.
    Ready {
        incoming: PathBuf,
    },
    /// A snapshot is in place to install.
    Resume,
    /// An earlier run installed this seed.
    Complete,
    Changed,
    Cleaned,
}

struct Target<'a> {
    input: &'a TargetInput,
    destination: PathBuf,
    home: PathBuf,
    state_file: PathBuf,
    target: Account,
    transport: Account,
}

impl Target<'_> {
    fn destination(&self, require_present: bool) -> Result<Option<fs::Metadata>> {
        let Some(metadata) = lstat(&self.destination)? else {
            return if require_present { refuse() } else { Ok(None) };
        };
        require(
            metadata.is_file()
                && metadata.uid() == self.target.uid
                && metadata.mode() & 0o7777 == 0o600,
        )?;
        Ok(Some(metadata))
    }

    fn valid_payload(
        &self,
        path: &Path,
        uid: Option<u32>,
        inode: Option<u64>,
        size: u64,
        sha256: &str,
    ) -> Result<fs::Metadata> {
        let metadata = fs::symlink_metadata(path)?;
        require(metadata.is_file())?;
        require(uid.is_none_or(|uid| metadata.uid() == uid))?;
        require(inode.is_none_or(|inode| metadata.ino() == inode))?;
        require(metadata.len() == size && digest(path)? == sha256)?;
        let uri = format!("file:{}?mode=ro&immutable=1", uri_path(path));
        let database = Connection::open_with_flags(
            uri,
            OpenFlags::SQLITE_OPEN_READ_ONLY
                | OpenFlags::SQLITE_OPEN_URI
                | OpenFlags::SQLITE_OPEN_NO_MUTEX,
        )?;
        database.execute_batch("PRAGMA query_only = ON")?;
        let integrity: String =
            database.query_row("PRAGMA integrity_check", [], |row| row.get(0))?;
        require(integrity == "ok")?;
        Ok(metadata)
    }

    fn expected_payload(
        &self,
        path: &Path,
        uid: Option<u32>,
        inode: Option<u64>,
    ) -> Result<fs::Metadata> {
        self.valid_payload(
            path,
            uid,
            inode,
            self.input.expected_size,
            &self.input.expected_digest,
        )
    }

    fn operation_state(&self, state: &Map<String, Value>) -> Result<()> {
        require(
            text(state, "operation") == Some(self.input.operation.as_str())
                && text(state, "destination") == self.destination.to_str(),
        )
    }

    fn state_payload(state: &Map<String, Value>) -> Result<(u64, String)> {
        let size = int(state, "expected_size")
            .filter(|size| *size > 0)
            .ok_or(Fault::Refused)?;
        let sha256 = text(state, "expected_digest")
            .filter(|text| valid_digest(text))
            .ok_or(Fault::Refused)?;
        Ok((size, sha256.to_string()))
    }

    fn remove_owned(path: &str, inode: u64) -> Result<()> {
        let path = Path::new(path);
        require(safe_absolute(path))?;
        let Some(metadata) = lstat(path)? else {
            return Ok(());
        };
        require(metadata.is_file() && metadata.ino() == inode)?;
        fs::remove_file(path)?;
        Ok(())
    }

    fn remove_recorded(state: &Map<String, Value>, path: &str, inode: &str) -> Result<()> {
        if let (Some(path), Some(inode)) = (text(state, path), int(state, inode)) {
            Self::remove_owned(path, inode)?;
        }
        Ok(())
    }

    fn complete(&self, state: &mut Map<String, Value>) -> Result<()> {
        let (size, sha256) = Self::state_payload(state)?;
        let destination = self.valid_payload(
            &self.destination,
            Some(self.target.uid),
            None,
            size,
            &sha256,
        )?;
        Self::remove_recorded(state, "candidate", "candidate_inode")?;
        Self::remove_recorded(state, "incoming", "incoming_inode")?;
        state.insert("status".into(), json!("complete"));
        state.insert("destination_inode".into(), json!(destination.ino()));
        for key in ["candidate", "candidate_inode", "incoming", "incoming_inode"] {
            state.remove(key);
        }
        write_state(&self.input.state_dir, state)
    }

    fn abandon(&self, state: &Map<String, Value>) -> Result<()> {
        Self::remove_recorded(state, "candidate", "candidate_inode")?;
        Self::remove_recorded(state, "incoming", "incoming_inode")?;
        fs::remove_file(&self.state_file)?;
        fs::remove_dir(&self.input.state_dir)?;
        Ok(())
    }

    /// Abandons the state when `step` refuses, as each refusal on a recorded seed does.
    fn or_abandon<T>(&self, state: &Map<String, Value>, step: Result<T>) -> Result<T> {
        if let Err(Fault::Refused) = step {
            let _ = self.abandon(state);
        }
        step
    }

    fn create_incoming(&self) -> Result<(PathBuf, u64)> {
        for _ in 0..16 {
            let prefix: String = self.input.operation.chars().take(16).collect();
            let path = PathBuf::from(format!(
                "/tmp/orbit-sqlite-{prefix}-{:016x}",
                rand::random::<u64>()
            ));
            match fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .open(&path)
            {
                Ok(file) => {
                    rustix::fs::fchown(
                        &file,
                        Some(rustix::fs::Uid::from_raw(self.transport.uid)),
                        Some(rustix::fs::Gid::from_raw(self.transport.gid)),
                    )
                    .map_err(|_| Fault::Failed)?;
                    return Ok((path, file.metadata()?.ino()));
                }
                Err(error) if error.kind() == io::ErrorKind::AlreadyExists => continue,
                Err(error) => return Err(error.into()),
            }
        }
        Err(Fault::Failed)
    }

    /// Copies `incoming` to a new file the target user owns, beside the destination.
    fn candidate(&self, incoming: &Path) -> Result<(PathBuf, u64)> {
        for _ in 0..16 {
            let path = self.home.join(format!(
                ".database.sqlite.orbit-{:016x}",
                rand::random::<u64>()
            ));
            let mut file = match fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .open(&path)
            {
                Ok(file) => file,
                Err(error) if error.kind() == io::ErrorKind::AlreadyExists => continue,
                Err(error) => return Err(error.into()),
            };
            let written = (|| {
                rustix::fs::fchown(
                    &file,
                    Some(rustix::fs::Uid::from_raw(self.target.uid)),
                    Some(rustix::fs::Gid::from_raw(self.target.gid)),
                )
                .map_err(|_| Fault::Failed)?;
                io::copy(&mut fs::File::open(incoming)?, &mut file)?;
                file.sync_all()?;
                Ok(file.metadata()?.ino())
            })();
            return match written {
                Ok(inode) => Ok((path, inode)),
                Err(fault) => {
                    let _ = fs::remove_file(&path);
                    Err(fault)
                }
            };
        }
        Err(Fault::Failed)
    }
}

fn uri_path(path: &Path) -> String {
    let mut encoded = String::new();
    for byte in path.as_os_str().as_encoded_bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'/' | b'-' | b'_' | b'.' | b'~' => {
                encoded.push(*byte as char)
            }
            _ => encoded.push_str(&format!("%{byte:02X}")),
        }
    }
    encoded
}

/// The outcome of a target run, and whether the replacement was already in place when it failed.
fn target_run(input: &TargetInput, installed: &mut bool) -> Result<TargetOutput> {
    let destination = input.base.join(&input.relative_path);
    let home = destination
        .parent()
        .map(Path::to_path_buf)
        .unwrap_or_default();
    require(
        safe_absolute(&input.base)
            && safe_absolute(&destination)
            && safe_absolute(&input.state_dir)
            && destination.starts_with(&input.base)
            && destination != input.base
            && matches!(input.environment.as_str(), "production" | "development"),
    )?;
    let target = Target {
        input,
        destination,
        home,
        state_file: input.state_dir.join("state.json"),
        target: account(&input.target_user)?,
        transport: account(&input.transport_user)?,
    };
    if input.environment == "production" {
        require(input.base == target.target.home && input.relative_path == "database.sqlite")?;
    }
    if input.mode == "cleanup" {
        if !state_directory(&input.state_dir, false)? {
            return Ok(TargetOutput::Cleaned);
        }
        match load_state(&target.state_file)? {
            None => fs::remove_dir(&input.state_dir)?,
            Some(state) => {
                target.operation_state(&state)?;
                target.abandon(&state)?;
            }
        }
        return Ok(TargetOutput::Cleaned);
    }
    require(input.expected_size > 0 && valid_digest(&input.expected_digest))?;
    as_account(&target.target, || {
        for directory in [&input.base, &target.home] {
            safe_tree(directory, false)?;
            require(fs::symlink_metadata(directory)?.uid() == target.target.uid)?;
        }
        require(
            rustix::fs::accessat(
                rustix::fs::CWD,
                &target.home,
                rustix::fs::Access::WRITE_OK | rustix::fs::Access::EXEC_OK,
                rustix::fs::AtFlags::EACCESS,
            )
            .is_ok(),
        )
    })?;
    if input.state_dir.exists() {
        state_directory(&input.state_dir, true)?;
    }
    let mut state = load_state(&target.state_file)?;
    if let Some(recorded) = state.clone() {
        let status = text(&recorded, "status").unwrap_or_default();
        if status == "complete" {
            target.operation_state(&recorded)?;
            let (size, sha256) = Target::state_payload(&recorded)?;
            let inode = int(&recorded, "destination_inode").ok_or(Fault::Refused)?;
            target.valid_payload(
                &target.destination,
                Some(target.target.uid),
                Some(inode),
                size,
                &sha256,
            )?;
            return Ok(TargetOutput::Complete);
        }
        target.operation_state(&recorded)?;
        let (size, sha256) = Target::state_payload(&recorded)?;
        match status {
            "installing" => {
                let present = target.or_abandon(&recorded, target.destination(false))?;
                if present.is_some() {
                    let mut recorded = recorded.clone();
                    let checked = target.valid_payload(
                        &target.destination,
                        Some(target.target.uid),
                        None,
                        size,
                        &sha256,
                    );
                    target.or_abandon(&recorded, checked)?;
                    let completed = target.complete(&mut recorded);
                    target.or_abandon(&recorded, completed)?;
                    return Ok(TargetOutput::Complete);
                }
                let candidate = text(&recorded, "candidate").ok_or(Fault::Refused)?;
                int(&recorded, "candidate_inode").ok_or(Fault::Refused)?;
                if lstat(Path::new(candidate))?.is_none() {
                    target.abandon(&recorded)?;
                    state = None;
                }
            }
            "prepared" => {
                let present = target.or_abandon(&recorded, target.destination(false))?;
                if present.is_some() {
                    target.abandon(&recorded)?;
                    return refuse();
                }
                let incoming = text(&recorded, "incoming").ok_or(Fault::Refused)?;
                int(&recorded, "incoming_inode").ok_or(Fault::Refused)?;
                if lstat(Path::new(incoming))?.is_none() {
                    target.abandon(&recorded)?;
                    state = None;
                }
            }
            _ => return refuse(),
        }
        if state.is_some() && (size != input.expected_size || sha256 != input.expected_digest) {
            target.abandon(&recorded)?;
            state = None;
        }
    } else {
        require(target.destination(false)?.is_none())?;
    }

    let (space, read_only) = available(&target.home)?;
    require(!read_only && space >= input.expected_size + RESERVE)?;

    if input.mode == "prepare" {
        let Some(state) = state else {
            state_directory(&input.state_dir, true)?;
            let (incoming, inode) = target.create_incoming()?;
            let mut value = Map::new();
            value.insert("destination".into(), json!(target.destination));
            value.insert("expected_digest".into(), json!(input.expected_digest));
            value.insert("expected_size".into(), json!(input.expected_size));
            value.insert("incoming".into(), json!(incoming));
            value.insert("incoming_inode".into(), json!(inode));
            value.insert("operation".into(), json!(input.operation));
            value.insert("status".into(), json!("prepared"));
            write_state(&input.state_dir, &value)?;
            return Ok(TargetOutput::Ready { incoming });
        };
        if text(&state, "status") == Some("installing") {
            if let (Some(candidate), Some(inode)) =
                (text(&state, "candidate"), int(&state, "candidate_inode"))
            {
                let checked = target.expected_payload(
                    Path::new(candidate),
                    Some(target.target.uid),
                    Some(inode),
                );
                target.or_abandon(&state, checked)?;
                return Ok(TargetOutput::Resume);
            }
        }
        require(text(&state, "status") == Some("prepared"))?;
        let incoming = PathBuf::from(text(&state, "incoming").ok_or(Fault::Refused)?);
        let inode = int(&state, "incoming_inode").ok_or(Fault::Refused)?;
        as_account(&target.transport, || {
            let file = fs::OpenOptions::new()
                .write(true)
                .truncate(true)
                .custom_flags(libc::O_NOFOLLOW)
                .open(&incoming)?;
            let metadata = file.metadata()?;
            require(
                metadata.is_file()
                    && metadata.ino() == inode
                    && metadata.uid() == target.transport.uid,
            )?;
            file.set_permissions(fs::Permissions::from_mode(0o600))?;
            Ok(())
        })?;
        return Ok(TargetOutput::Ready { incoming });
    }

    require(input.mode == "install")?;
    let mut state = state.ok_or(Fault::Refused)?;
    let candidate = match text(&state, "status") {
        Some("prepared") => {
            let incoming = PathBuf::from(text(&state, "incoming").ok_or(Fault::Refused)?);
            let inode = int(&state, "incoming_inode").ok_or(Fault::Refused)?;
            target.expected_payload(&incoming, Some(target.transport.uid), Some(inode))?;
            let (candidate, candidate_inode) = target.candidate(&incoming)?;
            state.insert("status".into(), json!("installing"));
            state.insert("candidate".into(), json!(candidate));
            state.insert("candidate_inode".into(), json!(candidate_inode));
            if let Err(fault) = write_state(&input.state_dir, &state) {
                let _ = Target::remove_owned(&candidate.to_string_lossy(), candidate_inode);
                return Err(fault);
            }
            candidate
        }
        Some("installing") => {
            let candidate = PathBuf::from(text(&state, "candidate").ok_or(Fault::Refused)?);
            let inode = int(&state, "candidate_inode").ok_or(Fault::Refused)?;
            target.expected_payload(&candidate, Some(target.target.uid), Some(inode))?;
            candidate
        }
        _ => return refuse(),
    };
    let present = target.or_abandon(&state, target.destination(false))?;
    if present.is_some() {
        target.abandon(&state)?;
        return refuse();
    }
    if let Err(error) = fs::hard_link(&candidate, &target.destination) {
        if error.kind() == io::ErrorKind::AlreadyExists {
            target.abandon(&state)?;
            return refuse();
        }
        return Err(error.into());
    }
    *installed = true;
    fs::File::open(&target.home)?.sync_all()?;
    target.complete(&mut state)?;
    Ok(TargetOutput::Changed)
}

pub fn prepare(input: &SourceInput) -> std::result::Result<SourceOutput, SeedError> {
    prepare_source(input).map_err(|fault| match fault {
        Fault::Refused => SeedError::Refused {},
        Fault::Failed => SeedError::Failed {},
    })
}

pub fn install(input: &TargetInput) -> std::result::Result<TargetOutput, SeedError> {
    let mut installed = false;
    target_run(input, &mut installed).map_err(|fault| match (installed, fault) {
        (true, _) => SeedError::Unconfirmed {},
        (false, Fault::Refused) => SeedError::Refused {},
        (false, Fault::Failed) => SeedError::Failed {},
    })
}

pub struct PrepareSqliteSeed;

impl Command for PrepareSqliteSeed {
    const NAME: &'static str = "instances.prepare-sqlite-seed";
    const SUMMARY: &'static str = "Take or clean up the source snapshot of a SQLite seed.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = SourceInput;
    type Output = SourceOutput;
    type Error = SeedError;

    /// Root keeps the state and acts as the source user only while it reads the database.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: SourceInput, _: &Context) -> std::result::Result<SourceOutput, SeedError> {
        prepare(&input)
    }
}

pub struct InstallSqliteSeed;

impl Command for InstallSqliteSeed {
    const NAME: &'static str = "instances.install-sqlite-seed";
    const SUMMARY: &'static str = "Prepare, install, or clean up the target of a SQLite seed.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = TargetInput;
    type Output = TargetOutput;
    type Error = SeedError;

    /// Root keeps the state and hands the incoming file to the transport account and the copy to the target user.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: TargetInput, _: &Context) -> std::result::Result<TargetOutput, SeedError> {
        install(&input)
    }
}

#[cfg(all(test, target_os = "linux"))]
#[path = "sqlite_seed_tests.rs"]
mod tests;

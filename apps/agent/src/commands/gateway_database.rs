//! Release snapshots are Node operations. The Gateway supplies the configured SQLite path and policy.
use super::{
    gateway_adoption::{directory, hex, owned},
    gateway_build,
};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use rusqlite::{Connection, OpenFlags};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    os::unix::fs::{DirBuilderExt, OpenOptionsExt},
    path::{Path, PathBuf},
    time::{Duration, Instant},
};

command_errors! {
    pub enum Error {
        Unavailable {} => "gateway.release_snapshot_unavailable", "The configured database is not an owned SQLite file at a canonical path.";
        Failed {} => "gateway.release_snapshot_failed", "The private release database snapshot could not be created. No snapshot was published.";
        DiskLow { free: u64, needed: u64 } => "gateway.release_disk_low", "The backup filesystem has too little space for a snapshot and the free-space floor.";
        Invalid {} => "gateway.release_layout_invalid", "The release snapshot identity or policy is invalid.";
        LockUnavailable {} => "gateway.release_lock_unavailable", "The private release step lock could not be opened.";
        Busy {} => "gateway.release_in_progress", "A command from an earlier Gateway release step still runs.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectInput {
    pub database: PathBuf,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SnapshotInput {
    pub database: PathBuf,
    pub orbit_home: PathBuf,
    pub release: String,
    pub kept_snapshots: usize,
    pub minimum_free_bytes: u64,
    pub step_lock: PathBuf,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Size {
    bytes: u64,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Snapshot {
    path: PathBuf,
}
fn regular(path: &Path) -> Result<Option<fs::Metadata>, Error> {
    let meta = owned(path).map_err(|_| Error::Unavailable {})?;
    if meta.as_ref().is_some_and(|m| !m.is_file()) {
        return Err(Error::Unavailable {});
    }
    Ok(meta)
}
fn database(path: &Path) -> Result<u64, Error> {
    if !path.is_absolute() || fs::canonicalize(path).ok().as_deref() != Some(path) {
        return Err(Error::Unavailable {});
    }
    let size = regular(path)?.ok_or(Error::Unavailable {})?.len();
    let wal = regular(&PathBuf::from(format!("{}-wal", path.display())))?.map_or(0, |m| m.len());
    regular(&PathBuf::from(format!("{}-shm", path.display())))?;
    size.checked_add(wal).ok_or(Error::Invalid {})
}
fn inspect(input: &InspectInput) -> Result<Size, Error> {
    Ok(Size {
        bytes: database(&input.database)?,
    })
}
fn free_space(path: &Path) -> Option<u64> {
    rustix::fs::statvfs(path)
        .ok()
        .map(|s| s.f_bavail.saturating_mul(s.f_frsize))
}
fn snapshot(
    input: &SnapshotInput,
    deadline: Instant,
    space: impl Fn(&Path) -> Option<u64>,
) -> Result<Snapshot, Error> {
    if !hex(&input.release, 12) || input.kept_snapshots == 0 {
        return Err(Error::Invalid {});
    }
    let bytes = database(&input.database)?;
    if !input.orbit_home.is_absolute()
        || fs::canonicalize(&input.orbit_home).ok().as_deref() != Some(&input.orbit_home)
    {
        return Err(Error::Invalid {});
    }
    directory(&input.orbit_home).map_err(|_| Error::Failed {})?;
    let backups = input.orbit_home.join("backups");
    let exists = owned(&backups).map_err(|_| Error::Failed {})?.is_some();
    if exists {
        directory(&backups).map_err(|_| Error::Failed {})?;
    }
    let _step = gateway_build::hold_step(&input.step_lock, deadline).map_err(|e| {
        use crate::command::CommandError;
        if e.code() == "gateway.release_in_progress" {
            Error::Busy {}
        } else {
            Error::LockUnavailable {}
        }
    })?;
    let needed = bytes
        .checked_add(input.minimum_free_bytes)
        .ok_or(Error::Invalid {})?;
    let free = space(if exists { &backups } else { &input.orbit_home }).ok_or(Error::Failed {})?;
    if free < needed {
        return Err(Error::DiskLow { free, needed });
    }
    if Instant::now() >= deadline {
        return Err(Error::Failed {});
    }
    let connection = Connection::open_with_flags(
        &input.database,
        OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_NO_MUTEX,
    )
    .map_err(|_| Error::Unavailable {})?;
    connection
        .busy_timeout(
            deadline
                .saturating_duration_since(Instant::now())
                .min(Duration::from_secs(5)),
        )
        .map_err(|_| Error::Failed {})?;
    connection
        .progress_handler(1000, Some(move || Instant::now() >= deadline))
        .map_err(|_| Error::Failed {})?;
    // Validate SQLite before creating the backup directory or any candidate.
    connection
        .query_row("PRAGMA schema_version", [], |r| r.get::<_, i64>(0))
        .map_err(|_| Error::Unavailable {})?;
    let mut old = Vec::new();
    if exists {
        for entry in fs::read_dir(&backups).map_err(|_| Error::Failed {})? {
            let entry = entry.map_err(|_| Error::Failed {})?;
            let name = entry.file_name();
            let name = name.to_string_lossy();
            if name.starts_with("pre-")
                && name.ends_with(".sqlite")
                && entry.path() != input.database
            {
                let meta = regular(&entry.path())?.ok_or(Error::Failed {})?;
                old.push((meta.modified().map_err(|_| Error::Failed {})?, entry.path()));
            }
        }
    }
    old.sort_by(|a, b| b.cmp(a));
    let paused = fs::read(input.orbit_home.join("gateway-release.paused"))
        .ok()
        .and_then(|bytes| serde_json::from_slice::<serde_json::Value>(&bytes).ok())
        .and_then(|v| {
            v.get("snapshot")
                .and_then(|s| s.as_str())
                .map(PathBuf::from)
        });
    if !exists {
        fs::DirBuilder::new()
            .mode(0o700)
            .create(&backups)
            .map_err(|_| Error::Failed {})?;
    }
    let stamp = time::OffsetDateTime::now_utc()
        .format(time::macros::format_description!(
            "[year][month][day]T[hour][minute][second]Z"
        ))
        .map_err(|_| Error::Failed {})?;
    let target = backups.join(format!(
        "pre-{}-{stamp}-{:016x}.sqlite",
        input.release,
        rand::random::<u64>()
    ));
    let partial = target.with_extension("sqlite.partial");
    let candidate = fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&partial)
        .map_err(|_| Error::Failed {})?;
    let result = (|| {
        connection
            .execute(
                "VACUUM INTO ?1",
                [partial.to_str().ok_or(Error::Invalid {})?],
            )
            .map_err(|_| Error::Failed {})?;
        candidate.sync_all().map_err(|_| Error::Failed {})?;
        fs::rename(&partial, &target).map_err(|_| Error::Failed {})?;
        Ok(Snapshot {
            path: target.clone(),
        })
    })();
    if result.is_err() {
        let _ = fs::remove_file(&partial);
    }
    // A published snapshot stays valid even when best-effort retention cannot remove an older copy.
    if result.is_ok() {
        for (_, path) in old.into_iter().skip(input.kept_snapshots - 1) {
            if paused.as_ref() != Some(&path) {
                let _ = fs::remove_file(path);
            }
        }
    }
    result
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "gateway.inspect-release-database";
    const SUMMARY: &'static str = "Inspect the configured release database and WAL size.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = InspectInput;
    type Output = Size;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Self::Input, _: &Context) -> Result<Size, Error> {
        inspect(&input)
    }
}
pub struct Create;
impl Command for Create {
    const NAME: &'static str = "gateway.snapshot-release-database";
    const SUMMARY: &'static str =
        "Create a private consistent release database snapshot and retain recovery copies.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = SnapshotInput;
    type Output = Snapshot;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn target(input: &Self::Input) -> Option<String> {
        Some(format!(
            "gateway-release-database:{}",
            input.database.display()
        ))
    }
    fn run(input: Self::Input, context: &Context) -> Result<Snapshot, Error> {
        snapshot(&input, context.deadline(), free_space)
    }
}
#[cfg(test)]
mod tests;

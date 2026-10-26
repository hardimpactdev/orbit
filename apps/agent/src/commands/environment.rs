//! `instances.access-environment`: reads and replaces an Instance's `.env`, and merges Orbit's keys into
//! `.env.testing`, inside its checkout or production home. The walk to the directory follows no link, the file must be
//! the Instance user's regular file, and a write publishes a private candidate with one rename after it checked that
//! neither the directory nor the file changed. This replaces the Python of `RemoteInstanceEnvironmentAccess`, check for
//! check. It runs as root, so it checks the Instance user's ownership itself, writes through the directory's open
//! descriptor, hands the candidate to the user, and runs Git as the user.

#[cfg(target_os = "linux")]
use crate::command::identity;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
#[cfg(target_os = "linux")]
use base64::Engine;
#[cfg(target_os = "linux")]
use rustix::fs::{fstat, openat, statat, AtFlags, Mode, OFlags, CWD};
use serde::{Deserialize, Serialize};
use std::time::Duration;
#[cfg(target_os = "linux")]
use std::{
    collections::BTreeSet,
    fs,
    io::{Read, Write},
    os::fd::{AsFd, OwnedFd},
    process::{Command as Process, Stdio},
};

command_errors! {
    pub enum EnvironmentError {
        Invalid { check: String } => "instances.environment_input_invalid", "The environment request does not pass its checks.";
    }
}

#[derive(schemars::JsonSchema, Deserialize, Clone, Copy, PartialEq, Debug)]
#[serde(rename_all = "kebab-case")]
pub enum Operation {
    ReadCheck,
    Read,
    WriteCheck,
    Write,
    Merge,
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct EnvironmentInput {
    pub mode: Operation,
    /// The checkout or production home that holds the file.
    pub base: String,
    pub user: String,
    pub maximum: u64,
    /// A production home must be the user's home.
    pub require_home: bool,
    #[serde(default)]
    pub required_capacity: u64,
    /// `.env` or `.env.testing`.
    #[serde(default = "default_file")]
    pub file: String,
    /// The keys a merge owns in `.env.testing`.
    #[serde(default)]
    pub managed_keys: Vec<String>,
    /// The new content of a write, or the managed lines of a merge.
    #[serde(default)]
    pub content: Option<String>,
}

fn default_file() -> String {
    ".env".into()
}

/// One outcome, as the Python program printed it.
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum State {
    Ok,
    Missing,
    Tracked,
    TrackingUnknown,
    Changed,
    Unchanged,
    /// A boundary check refused before anything changed.
    Refused,
    /// The work failed; with `installed`, after the replacement was in place.
    Failed,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Outcome {
    pub state: State,
    /// The file's content in base64, for a read.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub content: Option<String>,
    pub installed: bool,
}

fn outcome(state: State) -> Outcome {
    Outcome {
        state,
        content: None,
        installed: false,
    }
}

#[cfg(target_os = "linux")]
#[derive(Debug)]
enum Stop {
    Boundary,
    Missing,
    TrackingUnknown,
    Failed,
}

#[cfg(target_os = "linux")]
impl From<std::io::Error> for Stop {
    fn from(_: std::io::Error) -> Self {
        Stop::Failed
    }
}

#[cfg(target_os = "linux")]
impl From<rustix::io::Errno> for Stop {
    fn from(_: rustix::io::Errno) -> Self {
        Stop::Failed
    }
}

#[cfg(target_os = "linux")]
fn boundary(condition: bool) -> Result<(), Stop> {
    if condition {
        Ok(())
    } else {
        Err(Stop::Boundary)
    }
}

#[cfg(target_os = "linux")]
struct Access<'a> {
    input: &'a EnvironmentInput,
    uid: u32,
    gid: u32,
}

#[cfg(target_os = "linux")]
impl Access<'_> {
    /// The base directory, opened one component at a time without following a link.
    fn open_base(&self) -> Result<OwnedFd, Stop> {
        let flags = OFlags::RDONLY | OFlags::DIRECTORY | OFlags::CLOEXEC;
        let mut fd = openat(CWD, "/", flags, Mode::empty())?;
        for segment in self.input.base[1..].split('/') {
            fd = openat(&fd, segment, flags | OFlags::NOFOLLOW, Mode::empty())
                .map_err(|_| Stop::Boundary)?;
        }
        Ok(fd)
    }

    /// The file's descriptor without opening its content, when it exists: the user's regular file.
    fn destination(
        &self,
        directory: &OwnedFd,
    ) -> Result<Option<(OwnedFd, rustix::fs::Stat)>, Stop> {
        let descriptor = match openat(
            directory,
            self.input.file.as_str(),
            OFlags::PATH | OFlags::NOFOLLOW | OFlags::CLOEXEC,
            Mode::empty(),
        ) {
            Ok(descriptor) => descriptor,
            Err(rustix::io::Errno::NOENT) => return Ok(None),
            Err(_) => return Err(Stop::Failed),
        };
        let metadata = fstat(&descriptor)?;
        boundary(
            rustix::fs::FileType::from_raw_mode(metadata.st_mode)
                == rustix::fs::FileType::RegularFile
                && metadata.st_uid == self.uid,
        )?;
        Ok(Some((descriptor, metadata)))
    }

    /// Reopens a path descriptor for reading, and checks that it is still the same file.
    fn reopen(descriptor: &OwnedFd, identity: (u64, u64)) -> Result<fs::File, Stop> {
        let path = format!(
            "/proc/self/fd/{}",
            std::os::fd::AsRawFd::as_raw_fd(descriptor)
        );
        let file = openat(
            CWD,
            path.as_str(),
            OFlags::RDONLY | OFlags::NONBLOCK | OFlags::CLOEXEC,
            Mode::empty(),
        )?;
        let opened = fstat(&file)?;
        boundary((opened.st_dev, opened.st_ino) == identity)?;
        Ok(fs::File::from(file))
    }

    fn read_bounded(&self, file: fs::File) -> Result<Vec<u8>, Stop> {
        let mut data = Vec::new();
        file.take(self.input.maximum + 1).read_to_end(&mut data)?;
        boundary(data.len() as u64 <= self.input.maximum)?;
        Ok(data)
    }

    /// The directory and the file are still the ones the command opened.
    fn unchanged(
        &self,
        directory: (u64, u64),
        destination: Option<(u64, u64)>,
    ) -> Result<(), Stop> {
        let fresh = self.open_base()?;
        let metadata = fstat(&fresh)?;
        boundary((metadata.st_dev, metadata.st_ino) == directory)?;
        let current = self
            .destination(&fresh)?
            .map(|(_, metadata)| (metadata.st_dev, metadata.st_ino));
        boundary(current == destination)
    }

    /// Whether Git tracks the file, asked as the user.
    fn tracked(&self) -> Result<bool, Stop> {
        let git = |arguments: &[&str]| {
            let mut command = Process::new("git");
            command
                .args([
                    "-c",
                    "core.hooksPath=/dev/null",
                    "-c",
                    "core.fsmonitor=false",
                    "-C",
                    &self.input.base,
                ])
                .args(arguments)
                .env("GIT_OPTIONAL_LOCKS", "0")
                .env("LC_ALL", "C")
                .stdin(Stdio::null());
            identity::drop_to(&mut command, &self.input.user).map_err(|_| Stop::TrackingUnknown)?;
            command.output().map_err(|_| Stop::TrackingUnknown)
        };
        let inside = git(&["rev-parse", "--is-inside-work-tree"])?;
        let reason = inside
            .stderr
            .split(|byte| *byte == b'\n')
            .next()
            .unwrap_or_default()
            .to_ascii_lowercase();
        if inside.status.code() == Some(128)
            && reason.starts_with(b"fatal: not a git repository (or any ")
        {
            return Ok(false);
        }
        if !inside.status.success() || inside.stdout != b"true\n" {
            return Err(Stop::TrackingUnknown);
        }
        let listed = git(&["ls-files", "--error-unmatch", "--", &self.input.file])?;
        match listed.status.code() {
            Some(0) => Ok(true),
            Some(1) => Ok(false),
            _ => Err(Stop::TrackingUnknown),
        }
    }
}

/// A managed key and its whole line.
#[cfg(target_os = "linux")]
type Managed = (Vec<u8>, Vec<u8>);

/// The managed lines of a merge, in their order: complete lines, each managed key once, and at least one.
#[cfg(target_os = "linux")]
fn managed_lines(supplied: &[u8], keys: &BTreeSet<Vec<u8>>) -> Result<Vec<Managed>, Stop> {
    let mut updates: Vec<Managed> = Vec::new();
    for line in supplied.split_inclusive(|byte| *byte == b'\n') {
        let key = line
            .split(|byte| *byte == b'=')
            .next()
            .unwrap_or_default()
            .to_vec();
        let repeated = updates.iter().any(|(seen, _)| *seen == key);
        boundary(line.ends_with(b"\n") && !(keys.contains(&key) && repeated))?;
        if keys.contains(&key) {
            updates.push((key, line.to_vec()));
        }
    }
    boundary(!updates.is_empty())?;
    Ok(updates)
}

/// The key an assignment line sets, as `[ \t]*(export[ \t]+)?KEY[ \t]*=` reads it.
#[cfg(target_os = "linux")]
fn assigned_key(line: &[u8]) -> Option<&[u8]> {
    let mut rest = line;
    let skip = |rest: &[u8]| {
        rest.iter()
            .take_while(|byte| **byte == b' ' || **byte == b'\t')
            .count()
    };
    rest = &rest[skip(rest)..];
    if let Some(after) = rest.strip_prefix(b"export") {
        let blank = skip(after);
        if blank > 0 {
            rest = &after[blank..];
        }
    }
    let first = *rest.first()?;
    if !(first.is_ascii_alphabetic() || first == b'_') {
        return None;
    }
    let length = rest
        .iter()
        .take_while(|byte| byte.is_ascii_alphanumeric() || **byte == b'_' || **byte == b'.')
        .count();
    let after = &rest[length..];
    let after = &after[skip(after)..];
    after.starts_with(b"=").then_some(&rest[..length])
}

/// The existing file with each managed key's line replaced once, its other assignments dropped, and the missing
/// managed lines added at the end.
#[cfg(target_os = "linux")]
fn merged(existing: &[u8], keys: &BTreeSet<Vec<u8>>, updates: &[Managed]) -> Vec<u8> {
    let mut lines: Vec<Vec<u8>> = Vec::new();
    let mut written = BTreeSet::new();
    for line in existing.split_inclusive(|byte| *byte == b'\n') {
        match assigned_key(line) {
            Some(key) if keys.contains(key) => {
                if let Some((_, update)) = updates.iter().find(|(managed, _)| managed == key) {
                    if written.insert(key.to_vec()) {
                        lines.push(update.clone());
                    }
                }
            }
            _ => lines.push(line.to_vec()),
        }
    }
    let missing: Vec<Vec<u8>> = updates
        .iter()
        .filter(|(key, _)| !written.contains(key))
        .map(|(_, line)| line.clone())
        .collect();
    if !missing.is_empty() {
        if let Some(last) = lines.last_mut() {
            if !last.ends_with(b"\n") {
                last.push(b'\n');
            }
        }
    }
    lines.into_iter().chain(missing).flatten().collect()
}

#[cfg(target_os = "linux")]
fn valid_key(key: &str) -> bool {
    let bytes = key.as_bytes();
    !bytes.is_empty()
        && bytes[0].is_ascii_uppercase()
        && bytes
            .iter()
            .all(|byte| byte.is_ascii_uppercase() || byte.is_ascii_digit() || *byte == b'_')
}

#[cfg(not(target_os = "linux"))]
pub fn access(_: &EnvironmentInput) -> Outcome {
    outcome(State::Refused)
}

#[cfg(target_os = "linux")]
pub fn access(input: &EnvironmentInput) -> Outcome {
    let mut installed = false;
    match run(input, &mut installed) {
        Ok(outcome) => outcome,
        Err(Stop::Missing) => outcome(State::Missing),
        Err(Stop::TrackingUnknown) => outcome(State::TrackingUnknown),
        Err(Stop::Boundary) if !installed => outcome(State::Refused),
        Err(_) => Outcome {
            state: State::Failed,
            content: None,
            installed,
        },
    }
}

#[cfg(target_os = "linux")]
fn run(input: &EnvironmentInput, installed: &mut bool) -> Result<Outcome, Stop> {
    let (uid, gid, home) = identity::account(&input.user).map_err(|_| Stop::Boundary)?;
    boundary(!input.require_home || home == input.base)?;
    let normal = input.base.starts_with('/')
        && input.base[1..]
            .split('/')
            .all(|segment| !segment.is_empty() && segment != "." && segment != "..");
    boundary(normal && input.base != "/")?;
    boundary(input.file == ".env" || input.file == ".env.testing")?;
    let keys: BTreeSet<Vec<u8>> = input
        .managed_keys
        .iter()
        .map(|key| key.as_bytes().to_vec())
        .collect();
    if input.mode == Operation::Merge {
        boundary(
            input.file == ".env.testing"
                && !keys.is_empty()
                && input.managed_keys.iter().all(|key| valid_key(key)),
        )?;
    }
    let access = Access { input, uid, gid };
    let directory = access.open_base()?;
    let directory_metadata = fstat(&directory)?;
    let directory_identity = (directory_metadata.st_dev, directory_metadata.st_ino);
    let destination = access.destination(&directory)?;
    let destination_identity = destination
        .as_ref()
        .map(|(_, metadata)| (metadata.st_dev, metadata.st_ino));

    let mut value = None;
    if matches!(input.mode, Operation::ReadCheck | Operation::Read) {
        let Some((descriptor, metadata)) = &destination else {
            return Err(if input.mode == Operation::Read {
                Stop::Missing
            } else {
                Stop::Boundary
            });
        };
        boundary(u64::try_from(metadata.st_size).unwrap_or(u64::MAX) <= input.maximum)?;
        value = Some(Access::reopen(
            descriptor,
            (metadata.st_dev, metadata.st_ino),
        )?);
    }
    if matches!(
        input.mode,
        Operation::WriteCheck | Operation::Write | Operation::Merge
    ) {
        // As the user: a directory the user owns and may write and enter.
        boundary(directory_metadata.st_uid == uid && directory_metadata.st_mode & 0o300 == 0o300)?;
        let filesystem = rustix::fs::fstatvfs(&directory)?;
        boundary(
            !filesystem
                .f_flag
                .contains(rustix::fs::StatVfsMountFlags::RDONLY),
        )?;
        boundary(
            filesystem.f_bavail.saturating_mul(filesystem.f_frsize) >= input.required_capacity,
        )?;
    }

    match input.mode {
        Operation::ReadCheck | Operation::WriteCheck => {
            access.unchanged(directory_identity, destination_identity)?;
            Ok(outcome(State::Ok))
        }
        Operation::Read => {
            let data = access.read_bounded(value.ok_or(Stop::Failed)?)?;
            Ok(Outcome {
                state: State::Ok,
                content: Some(base64::engine::general_purpose::STANDARD.encode(data)),
                installed: false,
            })
        }
        Operation::Write | Operation::Merge => {
            let supplied = input.content.as_deref().unwrap_or_default().as_bytes();
            let payload = if input.mode == Operation::Merge {
                if access.tracked()? {
                    access.unchanged(directory_identity, destination_identity)?;
                    return Ok(outcome(State::Tracked));
                }
                boundary(supplied.len() as u64 <= input.maximum)?;
                let updates = managed_lines(supplied, &keys)?;
                let payload = match &destination {
                    Some((descriptor, metadata)) => {
                        boundary(
                            u64::try_from(metadata.st_size).unwrap_or(u64::MAX) <= input.maximum,
                        )?;
                        let existing = access.read_bounded(Access::reopen(
                            descriptor,
                            (metadata.st_dev, metadata.st_ino),
                        )?)?;
                        merged(&existing, &keys, &updates)
                    }
                    // A new file takes the supplied lines as they are.
                    None => supplied.to_vec(),
                };
                boundary(payload.len() as u64 <= input.maximum)?;
                payload
            } else {
                supplied.to_vec()
            };
            write(
                &access,
                &directory,
                directory_identity,
                destination.as_ref(),
                &payload,
                installed,
            )
        }
    }
}

/// Publishes the payload with one rename, unless the file already holds it with mode 0600.
#[cfg(target_os = "linux")]
fn write(
    access: &Access,
    directory: &OwnedFd,
    directory_identity: (u64, u64),
    destination: Option<&(OwnedFd, rustix::fs::Stat)>,
    payload: &[u8],
    installed: &mut bool,
) -> Result<Outcome, Stop> {
    let name = format!(".env.orbit-{:032x}", rand::random::<u128>());
    let candidate = openat(
        directory,
        name.as_str(),
        OFlags::RDWR | OFlags::CREATE | OFlags::EXCL | OFlags::NOFOLLOW | OFlags::CLOEXEC,
        Mode::from_raw_mode(0o600),
    )?;
    let candidate_metadata = fstat(&candidate)?;
    let candidate_identity = (candidate_metadata.st_dev, candidate_metadata.st_ino);
    let cleanup = || {
        if statat(directory, name.as_str(), AtFlags::SYMLINK_NOFOLLOW)
            .is_ok_and(|metadata| (metadata.st_dev, metadata.st_ino) == candidate_identity)
        {
            let _ = rustix::fs::unlinkat(directory, name.as_str(), AtFlags::empty());
        }
    };
    let result = (|| -> Result<Outcome, Stop> {
        rustix::fs::fchown(
            &candidate,
            Some(rustix::fs::Uid::from_raw(access.uid)),
            Some(rustix::fs::Gid::from_raw(access.gid)),
        )?;
        let mut file = fs::File::from(candidate.as_fd().try_clone_to_owned()?);
        file.write_all(payload)?;
        rustix::fs::fchmod(&candidate, Mode::from_raw_mode(0o600))?;
        file.sync_all()?;
        let same = match destination {
            Some((descriptor, metadata)) if metadata.st_mode & 0o7777 == 0o600 => {
                let mut existing = Vec::new();
                Access::reopen(descriptor, (metadata.st_dev, metadata.st_ino))?
                    .read_to_end(&mut existing)?;
                existing == payload
            }
            _ => false,
        };
        access.unchanged(
            directory_identity,
            destination.map(|(_, metadata)| (metadata.st_dev, metadata.st_ino)),
        )?;
        if same {
            cleanup();
            return Ok(outcome(State::Unchanged));
        }
        rustix::fs::renameat(
            directory,
            name.as_str(),
            directory,
            access.input.file.as_str(),
        )?;
        *installed = true;
        rustix::fs::fsync(directory)?;
        Ok(outcome(State::Changed))
    })();
    if result.is_err() && !*installed {
        cleanup();
    }
    result
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SyncInput {
    pub base: String,
    pub user: String,
    pub maximum: u64,
    pub require_home: bool,
    pub required_capacity: u64,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct SyncInspection {
    pub preflight: Outcome,
    pub existing: Option<Outcome>,
}

fn inspect_sync(input: SyncInput) -> SyncInspection {
    let mut access_input = EnvironmentInput {
        mode: Operation::WriteCheck,
        base: input.base,
        user: input.user,
        maximum: input.maximum,
        require_home: input.require_home,
        required_capacity: input.required_capacity,
        file: default_file(),
        managed_keys: Vec::new(),
        content: None,
    };
    let preflight = access(&access_input);
    let existing = if preflight.state == State::Ok {
        access_input.mode = Operation::Read;
        Some(access(&access_input))
    } else {
        None
    };
    SyncInspection {
        preflight,
        existing,
    }
}

pub struct InspectEnvironmentSync;

impl Command for InspectEnvironmentSync {
    const NAME: &'static str = "instances.inspect-environment-sync";
    const SUMMARY: &'static str =
        "Check that an Instance's .env can be replaced, then read its existing workload keys.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = SyncInput;
    type Output = SyncInspection;
    type Error = EnvironmentError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: SyncInput, _: &Context) -> Result<SyncInspection, EnvironmentError> {
        Ok(inspect_sync(input))
    }
}

pub struct ReadEnvironment;

impl Command for ReadEnvironment {
    const NAME: &'static str = "instances.read-environment";
    const SUMMARY: &'static str =
        "Read an Instance's .env, or check that it can be read or replaced.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = EnvironmentInput;
    type Output = Outcome;
    type Error = EnvironmentError;

    /// Root opens a production home that only its user may enter.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: EnvironmentInput, _: &Context) -> Result<Outcome, EnvironmentError> {
        if !matches!(
            input.mode,
            Operation::ReadCheck | Operation::Read | Operation::WriteCheck
        ) {
            return Err(EnvironmentError::Invalid {
                check: "a read names a read or a check".into(),
            });
        }
        Ok(access(&input))
    }
}

pub struct WriteEnvironment;

impl Command for WriteEnvironment {
    const NAME: &'static str = "instances.write-environment";
    const SUMMARY: &'static str =
        "Replace an Instance's .env, or merge Orbit's keys into .env.testing.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = EnvironmentInput;
    type Output = Outcome;
    type Error = EnvironmentError;

    /// Root writes in a production home that only its user may enter, and Git runs as that user.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &EnvironmentInput) -> Option<String> {
        Some(format!("environment:{}", input.base))
    }

    fn run(input: EnvironmentInput, _: &Context) -> Result<Outcome, EnvironmentError> {
        if !matches!(input.mode, Operation::Write | Operation::Merge) {
            return Err(EnvironmentError::Invalid {
                check: "a write names a write or a merge".into(),
            });
        }
        Ok(access(&input))
    }
}

#[cfg(all(test, target_os = "linux"))]
#[path = "environment_tests.rs"]
mod tests;

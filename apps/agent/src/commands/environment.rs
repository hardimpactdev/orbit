//! Read-only Instance environment observation. Swamp owns all environment writes and merges.

#[cfg(target_os = "linux")]
use crate::command::identity;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
#[cfg(target_os = "linux")]
use base64::Engine;
#[cfg(target_os = "linux")]
use rustix::fs::{fstat, openat, Mode, OFlags, CWD};
use serde::{Deserialize, Serialize};
use std::time::Duration;
#[cfg(target_os = "linux")]
use std::{fs, io::Read, os::fd::OwnedFd};

command_errors! {
    pub enum EnvironmentError {
        Invalid { check: String } => "instance.environment_input_invalid", "The environment request does not pass its checks.";
    }
}

#[derive(schemars::JsonSchema, Deserialize, Clone, Copy, PartialEq, Debug)]
#[serde(rename_all = "kebab-case")]
pub enum Operation {
    ReadCheck,
    Read,
    WriteCheck,
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
}

fn default_file() -> String {
    ".env".into()
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum State {
    Ok,
    Missing,
    Refused,
    Failed,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Outcome {
    pub state: State,
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
}

#[cfg(not(target_os = "linux"))]
pub fn access(_: &EnvironmentInput) -> Outcome {
    outcome(State::Refused)
}

#[cfg(target_os = "linux")]
pub fn access(input: &EnvironmentInput) -> Outcome {
    match read(input) {
        Ok(value) => value,
        Err(Stop::Missing) => outcome(State::Missing),
        Err(Stop::Boundary) => outcome(State::Refused),
        Err(Stop::Failed) => outcome(State::Failed),
    }
}

#[cfg(target_os = "linux")]
fn read(input: &EnvironmentInput) -> Result<Outcome, Stop> {
    let (uid, _, home) = identity::account(&input.user).map_err(|_| Stop::Boundary)?;
    boundary(!input.require_home || home == input.base)?;
    boundary(
        input.base.starts_with('/')
            && input.base != "/"
            && input.base[1..]
                .split('/')
                .all(|part| !part.is_empty() && part != "." && part != ".."),
    )?;
    boundary(input.file == ".env" || input.file == ".env.testing")?;
    let access = Access { input, uid };
    let directory = access.open_base()?;
    let metadata = fstat(&directory)?;
    let directory_identity = (metadata.st_dev, metadata.st_ino);
    let destination = access.destination(&directory)?;
    if input.mode == Operation::WriteCheck {
        boundary(metadata.st_uid == uid && metadata.st_mode & 0o300 == 0o300)?;
        let filesystem = rustix::fs::fstatvfs(&directory)?;
        boundary(
            !filesystem
                .f_flag
                .contains(rustix::fs::StatVfsMountFlags::RDONLY),
        )?;
        boundary(
            filesystem.f_bavail.saturating_mul(filesystem.f_frsize) >= input.required_capacity,
        )?;
        access.unchanged(
            directory_identity,
            destination
                .as_ref()
                .map(|(_, metadata)| (metadata.st_dev, metadata.st_ino)),
        )?;
        return Ok(outcome(State::Ok));
    }
    let Some((descriptor, metadata)) = destination else {
        return Err(if input.mode == Operation::Read {
            Stop::Missing
        } else {
            Stop::Boundary
        });
    };
    boundary(u64::try_from(metadata.st_size).unwrap_or(u64::MAX) <= input.maximum)?;
    let file = Access::reopen(&descriptor, (metadata.st_dev, metadata.st_ino))?;
    if input.mode == Operation::ReadCheck {
        access.unchanged(directory_identity, Some((metadata.st_dev, metadata.st_ino)))?;
        return Ok(outcome(State::Ok));
    }
    let bytes = access.read_bounded(file)?;
    Ok(Outcome {
        state: State::Ok,
        content: Some(base64::engine::general_purpose::STANDARD.encode(bytes)),
        installed: false,
    })
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
    let mut input = EnvironmentInput {
        mode: Operation::WriteCheck,
        base: input.base,
        user: input.user,
        maximum: input.maximum,
        require_home: input.require_home,
        required_capacity: input.required_capacity,
        file: default_file(),
    };
    let preflight = access(&input);
    let existing = if preflight.state == State::Ok {
        input.mode = Operation::Read;
        Some(access(&input))
    } else {
        None
    };
    SyncInspection {
        preflight,
        existing,
    }
}

/// Observes write eligibility and current bytes without creating or replacing a file.
pub struct InspectEnvironmentSync;
impl Command for InspectEnvironmentSync {
    const NAME: &'static str = "instance.inspect-environment-sync";
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
    const NAME: &'static str = "instance.read-environment";
    const SUMMARY: &'static str =
        "Read an Instance's .env, or check that it can be read or replaced.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = EnvironmentInput;
    type Output = Outcome;
    type Error = EnvironmentError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }
    fn run(input: EnvironmentInput, _: &Context) -> Result<Outcome, EnvironmentError> {
        Ok(access(&input))
    }
}

#[cfg(all(test, target_os = "linux"))]
#[path = "environment_tests.rs"]
mod tests;

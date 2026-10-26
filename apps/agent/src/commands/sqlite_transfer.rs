//! SQLite snapshot transport as the managed account. Installation and ownership receipts stay in the seed commands.
use crate::command::{
    binary_output::{DeadlineWriter, Frames},
    Command, Context, Platform, Privilege,
};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    fs::{self, File, OpenOptions},
    io::{Read, Seek, Write},
    os::unix::fs::{MetadataExt, OpenOptionsExt},
    path::{Path, PathBuf},
    time::Duration,
};

const MAX_BYTES: u64 = 1 << 40;
command_errors! {
    pub enum Error {
        Invalid {} => "instances.sqlite_transfer_invalid", "The managed snapshot path, size, or digest is invalid.";
        Refused {} => "instances.sqlite_transfer_refused", "The snapshot is not the expected private SQLite file.";
        Failed {} => "instances.sqlite_transfer_failed", "The SQLite snapshot transfer failed.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReadInput {
    path: PathBuf,
    bytes: u64,
    sha256: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReceiveInput {
    path: PathBuf,
    base: PathBuf,
    bytes: u64,
    sha256: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Output {
    bytes: u64,
    sha256: String,
}
fn hexadecimal(value: &str, length: usize) -> bool {
    value.len() == length
        && value
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}
fn metadata(bytes: u64, sha256: &str) -> Result<(), Error> {
    if !(16..=MAX_BYTES).contains(&bytes) || !hexadecimal(sha256, 64) {
        return Err(Error::Invalid {});
    }
    Ok(())
}
fn source_path(path: &Path) -> bool {
    let Some(path) = path.to_str() else {
        return false;
    };
    path.strip_prefix("/tmp/orbit-sqlite-")
        .and_then(|s| s.strip_suffix(".sqlite"))
        .is_some_and(|s| hexadecimal(s, 64))
        || path
            .strip_prefix("/tmp/orbit-sqlite-clone-")
            .and_then(|s| s.strip_suffix("/snapshot.sqlite"))
            .is_some_and(|s| hexadecimal(s, 32))
}
fn prepared_path(path: &Path) -> bool {
    path.to_str()
        .and_then(|s| s.strip_prefix("/tmp/orbit-sqlite-"))
        .and_then(|s| s.split_once('-'))
        .is_some_and(|(operation, random)| hexadecimal(operation, 16) && hexadecimal(random, 16))
}
fn private(file: &File) -> Result<(), Error> {
    let meta = file.metadata().map_err(|_| Error::Failed {})?;
    if !meta.is_file()
        || meta.uid() != crate::command::identity::effective_uid()
        || meta.mode() & 0o7777 != 0o600
        || meta.nlink() != 1
    {
        return Err(Error::Refused {});
    }
    Ok(())
}
fn validate(file: &mut File, bytes: u64, digest: &str, context: &Context) -> Result<(), Error> {
    if file.metadata().map_err(|_| Error::Failed {})?.len() != bytes {
        return Err(Error::Refused {});
    }
    file.rewind().map_err(|_| Error::Failed {})?;
    let mut header = [0u8; 16];
    file.read_exact(&mut header)
        .map_err(|_| Error::Refused {})?;
    if &header != b"SQLite format 3\0" {
        return Err(Error::Refused {});
    }
    let mut sha = Sha256::new();
    sha.update(header);
    let mut buffer = [0u8; 65536];
    loop {
        if context.remaining().is_zero() {
            return Err(Error::Failed {});
        }
        let count = file.read(&mut buffer).map_err(|_| Error::Failed {})?;
        if count == 0 {
            break;
        }
        sha.update(&buffer[..count]);
    }
    if crate::command::run::hex(&sha.finalize()) != digest {
        return Err(Error::Refused {});
    }
    file.rewind().map_err(|_| Error::Failed {})?;
    Ok(())
}
fn read(input: &ReadInput, context: &Context, output: impl Write) -> Result<Output, Error> {
    metadata(input.bytes, &input.sha256)?;
    if !source_path(&input.path) {
        return Err(Error::Invalid {});
    }
    if fs::canonicalize(&input.path).ok().as_deref() != Some(&input.path) {
        return Err(Error::Refused {});
    }
    let mut file = OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW)
        .open(&input.path)
        .map_err(|_| Error::Refused {})?;
    private(&file)?;
    validate(&mut file, input.bytes, &input.sha256, context)?;
    let mut output = Frames::new(output, input.bytes);
    let mut buffer = [0u8; 65536];
    loop {
        if context.remaining().is_zero() {
            return Err(Error::Failed {});
        }
        let count = file.read(&mut buffer).map_err(|_| Error::Failed {})?;
        if count == 0 {
            break;
        }
        output
            .write_all(&buffer[..count])
            .map_err(|_| Error::Failed {})?;
    }
    if output.bytes() != input.bytes {
        return Err(Error::Refused {});
    }
    Ok(Output {
        bytes: input.bytes,
        sha256: input.sha256.clone(),
    })
}
fn receive(input: &ReceiveInput, context: &Context) -> Result<Output, Error> {
    metadata(input.bytes, &input.sha256)?;
    let prepared = prepared_path(&input.path);
    let development = super::doctor_route::absolute(&input.base)
        && super::doctor_route::absolute(&input.path)
        && input.path.starts_with(&input.base)
        && input.path != input.base
        && input
            .path
            .file_name()
            .and_then(|s| s.to_str())
            .and_then(|s| s.strip_prefix(".orbit-sqlite-"))
            .is_some_and(|s| hexadecimal(s, 32));
    if !prepared && !development {
        return Err(Error::Invalid {});
    }
    let parent = input.path.parent().ok_or(Error::Invalid {})?;
    if fs::canonicalize(parent).ok().as_deref() != Some(parent) {
        return Err(Error::Refused {});
    }
    let mut body = context.take_binary_input().ok_or(Error::Refused {})?;
    validate(&mut body, input.bytes, &input.sha256, context)?;
    // A seed receipt names this inode. Never replace it during upload, including after a partial transfer.
    let mut destination = if !prepared && !input.path.exists() {
        OpenOptions::new()
            .read(true)
            .write(true)
            .create_new(true)
            .mode(0o600)
            .custom_flags(libc::O_NOFOLLOW)
            .open(&input.path)
    } else {
        OpenOptions::new()
            .read(true)
            .write(true)
            .custom_flags(libc::O_NOFOLLOW)
            .open(&input.path)
    }
    .map_err(|_| Error::Refused {})?;
    private(&destination)?;
    if context.remaining().is_zero() {
        return Err(Error::Failed {});
    }
    destination.set_len(0).map_err(|_| Error::Failed {})?;
    let mut buffer = [0u8; 65536];
    loop {
        if context.remaining().is_zero() {
            return Err(Error::Failed {});
        }
        let count = body.read(&mut buffer).map_err(|_| Error::Failed {})?;
        if count == 0 {
            break;
        }
        destination
            .write_all(&buffer[..count])
            .map_err(|_| Error::Failed {})?;
    }
    destination.sync_all().map_err(|_| Error::Failed {})?;
    Ok(Output {
        bytes: input.bytes,
        sha256: input.sha256.clone(),
    })
}
pub struct ReadSnapshot;
impl Command for ReadSnapshot {
    const NAME: &'static str = "instances.read-sqlite-snapshot";
    const SUMMARY: &'static str = "Stream a verified private SQLite snapshot to the Gateway.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(1800);
    const OUTPUT_MAX_BYTES: u64 = MAX_BYTES;
    type Input = ReadInput;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: ReadInput, context: &Context) -> Result<Output, Error> {
        read(
            &input,
            context,
            DeadlineWriter::new(libc::STDOUT_FILENO, context.deadline())
                .map_err(|_| Error::Failed {})?,
        )
    }
}
pub struct ReceiveSnapshot;
impl Command for ReceiveSnapshot {
    const NAME: &'static str = "instances.receive-sqlite-snapshot";
    const SUMMARY: &'static str =
        "Receive a verified SQLite snapshot into its prepared account-owned file.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(1800);
    const INPUT_MAX_BYTES: u64 = MAX_BYTES;
    type Input = ReceiveInput;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn target(input: &ReceiveInput) -> Option<String> {
        Some(format!("sqlite-snapshot:{}", input.path.display()))
    }
    fn run(input: ReceiveInput, context: &Context) -> Result<Output, Error> {
        receive(&input, context)
    }
}
#[cfg(test)]
#[path = "sqlite_transfer_tests.rs"]
mod tests;

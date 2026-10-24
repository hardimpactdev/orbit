//! Pi-specific artifact delivery. Input carries binary bytes, never a path or program source.
use super::recipe::{self, store::Account, Files};
use crate::{
    command::{run::file_sha256, Command, Context, Platform, Privilege},
    command_errors,
};
use base64::{engine::general_purpose::STANDARD, Engine};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::{self, Read, Seek, SeekFrom, Write},
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    time::Duration,
};
const MAX_ARTIFACT: u64 = 268435456;
const MAX_CHUNK: usize = 2 * 1024 * 1024;
pub(super) const CACHE: &str = "/var/cache/orbit/pi";
pub const BINARY: &str = ".local/bin/pi-server";
command_errors! {
    pub enum Error {
        Invalid { reason: String } => "pi.artifact_invalid", "The Pi artifact input is invalid.";
        Failed { reason: String } => "pi.artifact_failed", "The Pi artifact could not be delivered.";
    }
}
fn invalid(reason: impl ToString) -> Error {
    Error::Invalid {
        reason: recipe::diagnostic(&reason.to_string()),
    }
}
fn failed(reason: impl ToString) -> Error {
    Error::Failed {
        reason: recipe::diagnostic(&reason.to_string()),
    }
}
impl From<recipe::Failure> for Error {
    fn from(e: recipe::Failure) -> Self {
        failed(e.reason)
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Chunk {
    pub sha256: String,
    pub offset: u64,
    pub total: u64,
    pub bytes: String,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InstallInput {
    pub user: String,
    pub sha256: String,
}
#[derive(Debug, Serialize)]
pub struct Received {
    pub received_bytes: u64,
    pub verified: bool,
}
#[derive(Debug, Serialize)]
pub struct Installed {
    pub changed: bool,
}
fn paths(files: &Files, sha256: &str) -> Result<(PathBuf, PathBuf), Error> {
    if !recipe::types::valid_digest(sha256, 64) {
        return Err(invalid("Invalid Pi SHA-256"));
    }
    let directory = files.path(CACHE);
    Ok((
        directory.join(format!("{sha256}.partial")),
        directory.join(format!("{sha256}.bin")),
    ))
}
fn owned(path: &Path, uid: u32) -> Result<bool, Error> {
    match fs::symlink_metadata(path) {
        Ok(meta) if meta.is_file() && meta.uid() == uid && meta.mode() & 0o022 == 0 => Ok(true),
        Ok(_) => Err(failed("The Pi artifact is not an owned regular file")),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(false),
        Err(e) => Err(failed(e)),
    }
}
pub fn receive(files: &Files, input: &Chunk) -> Result<Received, Error> {
    let (partial, ready) = paths(files, &input.sha256)?;
    if input.total == 0
        || input.total > MAX_ARTIFACT
        || input.bytes.len() > MAX_CHUNK.div_ceil(3) * 4
    {
        return Err(invalid("Pi artifact or chunk exceeds its size bound"));
    }
    let bytes = STANDARD.decode(&input.bytes).map_err(invalid)?;
    if bytes.is_empty()
        || bytes.len() > MAX_CHUNK
        || input
            .offset
            .checked_add(bytes.len() as u64)
            .is_none_or(|end| end > input.total)
    {
        return Err(invalid("Invalid Pi artifact chunk bounds"));
    }
    if owned(&ready, files.uid)? {
        if fs::metadata(&ready).map_err(failed)?.len() != input.total
            || file_sha256(&ready).map_err(failed)? != input.sha256
        {
            return Err(failed("The cached Pi artifact does not match its pin"));
        }
        return Ok(Received {
            received_bytes: input.total,
            verified: true,
        });
    }
    let exists = owned(&partial, files.uid)?;
    if !exists && input.offset != 0 {
        return Err(invalid("The first Pi chunk must start at offset zero"));
    }
    super::image_recipe::directories(files, ready.parent().unwrap())?;
    fs::set_permissions(ready.parent().unwrap(), fs::Permissions::from_mode(0o700))
        .map_err(failed)?;
    let mut file = fs::OpenOptions::new()
        .read(true)
        .write(true)
        .create(true)
        .truncate(false)
        .mode(0o600)
        .custom_flags(libc::O_NOFOLLOW)
        .open(&partial)
        .map_err(failed)?;
    let length = file.metadata().map_err(failed)?.len();
    let end = input.offset + bytes.len() as u64;
    if length > input.total || input.offset > length {
        return Err(invalid("Pi chunk does not follow the received bytes"));
    }
    file.seek(SeekFrom::Start(input.offset)).map_err(failed)?;
    let overlap = (length - input.offset).min(bytes.len() as u64) as usize;
    if overlap > 0 {
        let mut previous = vec![0; overlap];
        file.read_exact(&mut previous).map_err(failed)?;
        if previous != bytes[..overlap] {
            return Err(invalid("Repeated Pi chunk differs from the received bytes"));
        }
    }
    file.write_all(&bytes[overlap..]).map_err(failed)?;
    file.sync_all().map_err(failed)?;
    let received_bytes = length.max(end);
    if received_bytes == input.total {
        if file_sha256(&partial).map_err(failed)? != input.sha256 {
            fs::remove_file(partial).map_err(failed)?;
            return Err(invalid(
                "The complete Pi artifact differs from its SHA-256 pin",
            ));
        }
        fs::rename(&partial, &ready).map_err(failed)?;
    }
    Ok(Received {
        received_bytes,
        verified: received_bytes == input.total,
    })
}
pub fn install(files: &Files, account: &Account, sha256: &str) -> Result<Installed, Error> {
    let (_, ready) = paths(files, sha256)?;
    if !owned(&ready, files.uid)? || file_sha256(&ready).map_err(failed)? != sha256 {
        return Err(invalid(
            "The verified Pi artifact is missing or does not match its pin",
        ));
    }
    let target = account.home.join(BINARY);
    if owned(&target, account.uid)?
        && file_sha256(&target).map_err(failed)? == sha256
        && fs::metadata(&target).map_err(failed)?.mode() & 0o777 == 0o755
    {
        return Ok(Installed { changed: false });
    }
    for path in [account.home.join(".local"), account.home.join(".local/bin")] {
        files.directory(&path, account.uid, account.gid)?;
    }
    let candidate = target.with_file_name(format!(".pi-server-{:016x}", rand::random::<u64>()));
    let result = (|| -> io::Result<()> {
        let mut source = fs::File::open(&ready)?;
        let mut destination = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&candidate)?;
        io::copy(&mut source, &mut destination)?;
        rustix::fs::chown(
            &candidate,
            Some(rustix::fs::Uid::from_raw(account.uid)),
            Some(rustix::fs::Gid::from_raw(account.gid)),
        )?;
        destination.set_permissions(fs::Permissions::from_mode(0o755))?;
        destination.sync_all()?;
        fs::rename(&candidate, &target)
    })();
    let _ = fs::remove_file(candidate);
    result.map_err(failed)?;
    Ok(Installed { changed: true })
}
pub struct Receive;
impl Command for Receive {
    const NAME: &'static str = "pi.receive-artifact";
    const SUMMARY: &'static str =
        "Receive a bounded Pi binary chunk into its fixed verified artifact cache.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Chunk;
    type Output = Received;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(input: &Chunk) -> Option<String> {
        Some(format!("pi-artifact-{}", input.sha256))
    }
    fn run(input: Chunk, _: &Context) -> Result<Received, Error> {
        receive(&Files::system(), &input)
    }
}
pub struct Install;
impl Command for Install {
    const NAME: &'static str = "pi.install-artifact";
    const SUMMARY: &'static str =
        "Publish the verified Pi binary at the managed account's fixed runtime path.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["pi-runtime"];
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = InstallInput;
    type Output = Installed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(input: &InstallInput) -> Option<String> {
        Some(format!("pi-runtime-{}", input.user))
    }
    fn run(input: InstallInput, _: &Context) -> Result<Installed, Error> {
        install(
            &Files::system(),
            &Account::named(&input.user)?,
            &input.sha256,
        )
    }
}
#[cfg(test)]
mod tests;

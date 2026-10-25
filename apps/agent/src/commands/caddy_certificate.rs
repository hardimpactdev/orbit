//! Shared role/extension certificate publication. Scope and PEM bytes are data; filesystem and service actions are fixed.
use super::caddy;
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::{self, Read, Write},
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

const LIMIT: u64 = 1024 * 1024;
command_errors! {
    pub enum Error {
        Input {} => "caddy.certificate_input_invalid", "The certificate scope, filenames, service, or PEM input is invalid.";
        Invalid {} => "caddy.certificate_invalid", "The key does not belong to the certificate.";
        Unsafe {} => "caddy.certificate_unsafe", "The certificate directory is not an owned Orbit pair.";
        Lock {} => "caddy.publication_lock_failed", "The Caddy publication lock could not be held.";
        Failed { reason: String } => "caddy.certificate_failed", "The certificate operation failed.";
        Recovery { directory: String } => "caddy.certificate_recovery_failed", "The previous certificate or active Caddy service could not be restored.";
    }
}
fn failed(stage: &str) -> Error {
    Error::Failed {
        reason: stage.into(),
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InstallInput {
    directory: PathBuf,
    certificate_file: String,
    key_file: String,
    certificate: String,
    key: String,
    service: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RemoveInput {
    directory: PathBuf,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Installed {
    changed: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Removed {}

fn stem(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 64
        && value.as_bytes()[0].is_ascii_lowercase()
        && value
            .bytes()
            .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || b"_-".contains(&c))
}
fn scope(path: &Path) -> Result<&str, Error> {
    let value = path
        .to_str()
        .and_then(|s| s.strip_prefix("/etc/caddy/orbit-"))
        .and_then(|s| s.strip_suffix("-cert-current"))
        .ok_or(Error::Input {})?;
    if !stem(value) || value.contains('_') {
        return Err(Error::Input {});
    }
    Ok(value)
}
fn names(certificate: &str, key: &str) -> bool {
    certificate
        .strip_suffix(".pem")
        .is_some_and(|s| stem(s) && key == format!("{s}.key"))
}
fn validate(input: &InstallInput) -> Result<(), Error> {
    scope(&input.directory)?;
    if input.service != "caddy"
        || !names(&input.certificate_file, &input.key_file)
        || [&input.certificate, &input.key]
            .iter()
            .any(|s| s.is_empty() || s.len() as u64 > LIMIT || s.contains('\0'))
    {
        return Err(Error::Input {});
    }
    Ok(())
}
struct Files {
    root: PathBuf,
    uid: u32,
    gid: u32,
}
impl Files {
    fn path(&self, path: &Path) -> PathBuf {
        self.root.join(path.strip_prefix("/").unwrap())
    }
    fn parents(&self) -> Result<bool, Error> {
        for path in ["/etc", "/etc/caddy"] {
            let Some(meta) = metadata(&self.path(Path::new(path)))? else {
                return Ok(false);
            };
            if !meta.is_dir() || meta.uid() != self.uid || meta.mode() & 0o022 != 0 {
                return Err(Error::Unsafe {});
            }
        }
        Ok(true)
    }
    fn directory(&self, path: &Path) -> Result<bool, Error> {
        let Some(meta) = metadata(path)? else {
            return Ok(false);
        };
        if !meta.is_dir()
            || meta.uid() != self.uid
            || meta.gid() != self.gid
            || meta.mode() & 0o7777 != 0o750
        {
            return Err(Error::Unsafe {});
        }
        Ok(true)
    }
    fn read(&self, path: &Path) -> Result<Vec<u8>, Error> {
        let meta = metadata(path)?.ok_or(Error::Unsafe {})?;
        if !meta.is_file()
            || meta.uid() != self.uid
            || meta.gid() != self.gid
            || meta.mode() & 0o7777 != 0o640
            || meta.len() > LIMIT
        {
            return Err(Error::Unsafe {});
        }
        let file = fs::OpenOptions::new()
            .read(true)
            .custom_flags(libc::O_NOFOLLOW)
            .open(path)
            .map_err(|_| failed("read"))?;
        let mut bytes = Vec::new();
        file.take(LIMIT + 1)
            .read_to_end(&mut bytes)
            .map_err(|_| failed("read"))?;
        if bytes.len() as u64 > LIMIT {
            return Err(Error::Unsafe {});
        }
        Ok(bytes)
    }
    fn previous(&self, path: &Path) -> Result<Option<Pair>, Error> {
        if !self.directory(path)? {
            return Ok(None);
        }
        let mut files = fs::read_dir(path)
            .map_err(|_| failed("inspect"))?
            .map(|e| e.map(|e| e.file_name()))
            .collect::<Result<Vec<_>, _>>()
            .map_err(|_| failed("inspect"))?;
        files.sort();
        if files.len() != 2 {
            return Err(Error::Unsafe {});
        }
        let key = files[0].to_str().ok_or(Error::Unsafe {})?;
        let certificate = files[1].to_str().ok_or(Error::Unsafe {})?;
        if !names(certificate, key) {
            return Err(Error::Unsafe {});
        }
        Ok(Some(Pair {
            certificate_file: certificate.into(),
            key_file: key.into(),
            certificate: self.read(&path.join(certificate))?,
            key: self.read(&path.join(key))?,
        }))
    }
    fn write(&self, path: &Path, bytes: &[u8]) -> Result<(), Error> {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o640)
            .open(path)
            .map_err(|_| failed("stage"))?;
        file.write_all(bytes).map_err(|_| failed("stage"))?;
        caddy::chown(path, self.uid, self.gid).map_err(|_| failed("stage"))?;
        file.set_permissions(fs::Permissions::from_mode(0o640))
            .map_err(|_| failed("stage"))
    }
}
struct Pair {
    certificate_file: String,
    key_file: String,
    certificate: Vec<u8>,
    key: Vec<u8>,
}
fn metadata(path: &Path) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(meta) => Ok(Some(meta)),
        Err(error) if error.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(failed("inspect")),
    }
}
pub(super) trait Runtime {
    fn valid(&mut self, certificate: &Path, key: &Path) -> bool;
    fn active(&mut self) -> io::Result<bool>;
    fn reload(&mut self) -> io::Result<()>;
}
pub(super) struct Native;
impl Runtime for Native {
    fn valid(&mut self, certificate: &Path, key: &Path) -> bool {
        let public = caddy::public_key("certificate", certificate);
        public.is_some() && public == caddy::public_key("key", key)
    }
    fn active(&mut self) -> io::Result<bool> {
        match systemctl(&["is-active", "--quiet", "caddy"])? {
            Some(0) => Ok(true),
            Some(3 | 4) => Ok(false),
            _ => Err(io::Error::other("Caddy state unavailable")),
        }
    }
    fn reload(&mut self) -> io::Result<()> {
        if systemctl(&["reload-or-restart", "caddy"])? == Some(0) {
            Ok(())
        } else {
            Err(io::Error::other("Caddy activation failed"))
        }
    }
}
fn systemctl(args: &[&str]) -> io::Result<Option<i32>> {
    Ok(Process::new("/usr/bin/systemctl")
        .args(args)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()?
        .code())
}
fn exchange(first: &Path, second: &Path) -> io::Result<()> {
    rustix::fs::renameat_with(
        rustix::fs::CWD,
        first,
        rustix::fs::CWD,
        second,
        rustix::fs::RenameFlags::EXCHANGE,
    )
    .map_err(Into::into)
}

/// Caller holds the shared Caddy lock through validation, publication and activation/recovery.
fn install(
    input: &InstallInput,
    files: &Files,
    runtime: &mut impl Runtime,
) -> Result<Installed, Error> {
    validate(input)?;
    if !files.parents()? {
        return Err(Error::Unsafe {});
    }
    let current = files.path(&input.directory);
    let previous = files.previous(&current)?;
    if previous.as_ref().is_some_and(|p| {
        p.certificate_file != input.certificate_file || p.key_file != input.key_file
    }) {
        return Err(Error::Unsafe {});
    }
    let candidate = current.with_file_name(format!(
        "{}.candidate.{:016x}",
        current.file_name().unwrap().to_str().unwrap(),
        rand::random::<u64>()
    ));
    fs::DirBuilder::new()
        .mode(0o750)
        .create(&candidate)
        .map_err(|_| failed("stage"))?;
    let staged = (|| {
        caddy::chown(&candidate, files.uid, files.gid).map_err(|_| failed("stage"))?;
        fs::set_permissions(&candidate, fs::Permissions::from_mode(0o750))
            .map_err(|_| failed("stage"))?;
        files.write(
            &candidate.join(&input.certificate_file),
            input.certificate.as_bytes(),
        )?;
        files.write(&candidate.join(&input.key_file), input.key.as_bytes())?;
        if !runtime.valid(
            &candidate.join(&input.certificate_file),
            &candidate.join(&input.key_file),
        ) {
            return Err(Error::Invalid {});
        }
        if previous.as_ref().is_some_and(|p| {
            p.certificate == input.certificate.as_bytes() && p.key == input.key.as_bytes()
        }) {
            return Ok(false);
        }
        let active = runtime.active().map_err(|_| failed("service-state"))?;
        if previous.is_some() {
            exchange(&candidate, &current)
        } else {
            fs::rename(&candidate, &current)
        }
        .map_err(|_| failed("publish"))?;
        if active && runtime.reload().is_err() {
            let restored = if previous.is_some() {
                exchange(&candidate, &current)
            } else {
                fs::rename(&current, &candidate)
            };
            if restored.is_err() {
                return Err(Error::Recovery {
                    directory: if previous.is_some() {
                        &candidate
                    } else {
                        &current
                    }
                    .display()
                    .to_string(),
                });
            }
            if runtime.reload().is_err() {
                return Err(Error::Recovery {
                    directory: current.display().to_string(),
                });
            }
            return Err(failed("reload"));
        }
        Ok(true)
    })();
    // After a failed exchange recovery the candidate is the only copy of the previous pair.
    if !matches!(staged, Err(Error::Recovery { .. })) {
        fs::remove_dir_all(&candidate)
            .or_else(|e| {
                if e.kind() == io::ErrorKind::NotFound {
                    Ok(())
                } else {
                    Err(e)
                }
            })
            .map_err(|_| failed("cleanup"))?;
    }
    staged.map(|changed| Installed { changed })
}
fn remove(input: &RemoveInput, files: &Files) -> Result<Removed, Error> {
    scope(&input.directory)?;
    if !files.parents()? {
        return Ok(Removed {});
    }
    let current = files.path(&input.directory);
    if files.previous(&current)?.is_some() {
        fs::remove_dir_all(&current).map_err(|_| failed("remove"))?;
    }
    Ok(Removed {})
}
fn system_files() -> Result<Files, Error> {
    Ok(Files {
        root: "/".into(),
        uid: 0,
        gid: caddy::group_id("caddy").ok_or_else(|| failed("caddy-group"))?,
    })
}

pub struct InstallCertificate;
impl Command for InstallCertificate {
    const NAME: &'static str = "caddy.install-certificate";
    const SUMMARY: &'static str =
        "Atomically publish an owned Caddy certificate pair, restoring it if reload fails.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = InstallInput;
    type Output = Installed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: InstallInput, _: &Context) -> Result<Installed, Error> {
        validate(&input)?;
        let files = system_files()?;
        let _lock = caddy::lock().map_err(|_| Error::Lock {})?;
        install(&input, &files, &mut Native)
    }
}
pub struct RemoveCertificate;
impl Command for RemoveCertificate {
    const NAME: &'static str = "caddy.remove-certificate";
    const SUMMARY: &'static str =
        "Remove only an owned role or extension Caddy certificate pair under its publication lock.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = RemoveInput;
    type Output = Removed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: RemoveInput, _: &Context) -> Result<Removed, Error> {
        scope(&input.directory)?;
        let _lock = caddy::lock().map_err(|_| Error::Lock {})?;
        let root = Files {
            root: "/".into(),
            uid: 0,
            gid: 0,
        };
        if !root.parents()? || metadata(&input.directory)?.is_none() {
            return Ok(Removed {});
        }
        let files = system_files()?;
        remove(&input, &files)
    }
}
#[cfg(test)]
#[path = "caddy_certificate_tests.rs"]
mod tests;

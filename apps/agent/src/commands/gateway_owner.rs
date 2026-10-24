//! Read-only release admission. Laravel connection and configuration policy stay in PHP.
use super::gateway_adoption::{hex, Layout};
use crate::{
    command::{identity, Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "gateway.release_owner_invalid", "Run as the non-root Gateway account with matching owned shared state and cached configuration. No permissions were changed.";
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    checkout: String,
    orbit_home: PathBuf,
    database: PathBuf,
    caller_uid: u32,
    sha: Option<String>,
    runtime_cache: Option<PathBuf>,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Output {
    caches: Vec<PathBuf>,
}
fn invalid<T>(_: T) -> Error {
    Error::Invalid {}
}
fn metadata(path: &Path) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(m) => Ok(Some(m)),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(None),
        Err(e) => Err(invalid(e)),
    }
}
fn owned(path: &Path, uid: u32) -> Result<fs::Metadata, Error> {
    let m = metadata(path)?.ok_or(Error::Invalid {})?;
    if m.is_symlink()
        || m.uid() != uid
        || m.mode() & 0o022 != 0
        || (!m.is_file() && !m.is_dir())
        || fs::File::open(path).is_err()
    {
        return Err(Error::Invalid {});
    }
    Ok(m)
}
fn inspect(input: &Input, account_uid: u32) -> Result<Output, Error> {
    let uid = identity::effective_uid();
    if uid == 0
        || uid != account_uid
        || input.caller_uid != uid
        || !input.orbit_home.is_absolute()
        || !input.database.is_absolute()
        || fs::canonicalize(&input.database).ok().as_ref() != Some(&input.database)
        || input.sha.as_ref().is_some_and(|sha| !hex(sha, 40))
        || input
            .runtime_cache
            .as_ref()
            .is_some_and(|p| !p.is_absolute())
    {
        return Err(Error::Invalid {});
    }
    let layout = Layout::new(&input.checkout).map_err(invalid)?;
    for path in [
        &input.orbit_home,
        &layout.base,
        &layout.shared(),
        &layout.shared().join("gateway-storage"),
        &layout.base.join("releases"),
    ] {
        if !owned(path, uid)?.is_dir() {
            return Err(Error::Invalid {});
        }
    }
    for path in [&input.database, &layout.shared().join("gateway.env")] {
        if !owned(path, uid)?.is_file() {
            return Err(Error::Invalid {});
        }
    }
    for name in [
        "gateway-release.lock",
        "gateway-release-step.lock",
        "backups",
        "recovery",
        "gateway-release.paused",
    ] {
        let path = input.orbit_home.join(name);
        if metadata(&path)?.is_some() {
            let m = owned(&path, uid)?;
            if name.ends_with(".lock") && (!m.is_file() || m.mode() & 0o7777 != 0o600) {
                return Err(Error::Invalid {});
            }
        }
    }
    let mut caches = vec![Path::new(&input.checkout).join("bootstrap/cache/config.php")];
    if let Some(cache) = &input.runtime_cache {
        caches.push(cache.clone());
    }
    if let Some(sha) = &input.sha {
        let target = layout.base.join("releases").join(&sha[..12]);
        if metadata(&target)?.is_some() {
            if !owned(&target, uid)?.is_dir() {
                return Err(Error::Invalid {});
            }
            let directory = target.join("apps/gateway/bootstrap/cache");
            if metadata(&directory)?.is_some() && !owned(&directory, uid)?.is_dir() {
                return Err(Error::Invalid {});
            }
            let cache = directory.join("config.php");
            if metadata(&cache)?.is_some() || metadata(&target.join("REVISION"))?.is_some() {
                caches.push(cache);
            }
        }
    }
    let mut verified = Vec::new();
    for cache in caches {
        if verified.contains(&cache) {
            continue;
        }
        let m = owned(&cache, uid)?;
        if !m.is_file() || m.mode() & 0o077 != 0 {
            return Err(Error::Invalid {});
        }
        verified.push(cache);
    }
    Ok(Output { caches: verified })
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "gateway.inspect-release-owner";
    const SUMMARY: &'static str =
        "Verify the Gateway execution account and private release state without changing it.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        let account = identity::passwd("orbit").ok_or(Error::Invalid {})?;
        inspect(&input, account.uid)
    }
}
#[cfg(test)]
mod tests;

//! Native Composer scope ownership, manifest discovery, and Linux first-use setup.
use super::recipe::{self, store::Account, Files};
use crate::command::{identity, Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::value::RawValue;
use std::{
    collections::BTreeMap,
    fs,
    io::Read,
    os::unix::fs::{MetadataExt, OpenOptionsExt},
    path::Path,
    time::Duration,
};
crate::command_errors! {
    pub enum Error {
        Invalid {} => "tool.composer_account_invalid", "The Composer scope account is invalid.";
        Absent {} => "tool.composer_scope_absent", "The Composer global scope is absent.";
        Conflict {} => "tool.composer_scope_conflict", "The Composer scope conflicts with Orbit ownership.";
        Unreadable {} => "tool.composer_scope_unreadable", "The Composer global manifest cannot be read.";
        Failed {} => "tool.composer_materialize_failed", "The Composer manager could not be materialized.";
    }
}
type Result<T> = std::result::Result<T, Error>;
const SCOPE: &str = "/opt/orbit/composer";
const MAX_MANIFEST: u64 = 1_048_576;
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Output {
    roots: Vec<String>,
}
fn metadata(path: &Path) -> Result<Option<fs::Metadata>> {
    recipe::metadata(path).map_err(|_| Error::Unreadable {})
}
fn check(path: &Path, uid: u32, gid: u32, directory: bool) -> Result<bool> {
    let Some(meta) = metadata(path)? else {
        return Ok(false);
    };
    if (if directory {
        !meta.is_dir()
    } else {
        !meta.is_file()
    }) || meta.uid() != uid
        || meta.gid() != gid
    {
        return Err(Error::Conflict {});
    }
    Ok(true)
}
fn roots(path: &Path, account: &Account) -> Result<Vec<String>> {
    let file = fs::OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW | libc::O_NONBLOCK)
        .open(path)
        .map_err(|_| Error::Unreadable {})?;
    let meta = file.metadata().map_err(|_| Error::Unreadable {})?;
    if !meta.is_file() || meta.uid() != account.uid || meta.gid() != account.gid {
        return Err(Error::Conflict {});
    }
    let mut data = Vec::new();
    file.take(MAX_MANIFEST + 1)
        .read_to_end(&mut data)
        .map_err(|_| Error::Unreadable {})?;
    if data.len() as u64 > MAX_MANIFEST {
        return Err(Error::Unreadable {});
    }
    let manifest: BTreeMap<String, Box<RawValue>> =
        serde_json::from_slice(&data).map_err(|_| Error::Unreadable {})?;
    let Some(require) = manifest.get("require") else {
        return Ok(vec![]);
    };
    let raw = require.get();
    if raw.starts_with('[') {
        let list: Vec<serde::de::IgnoredAny> =
            serde_json::from_str(raw).map_err(|_| Error::Unreadable {})?;
        return if list.is_empty() {
            Ok(vec![])
        } else {
            Err(Error::Unreadable {})
        };
    }
    let packages: BTreeMap<String, serde::de::IgnoredAny> =
        serde_json::from_str(raw).map_err(|_| Error::Unreadable {})?;
    let mut roots = vec![];
    for name in packages.keys() {
        if name.is_empty()
            || name.len() > 255
            || name.bytes().any(|b| b < 32 || b == 127)
            || name
                .parse::<i64>()
                .is_ok_and(|number| number.to_string() == *name)
        {
            return Err(Error::Unreadable {});
        }
        roots.push(name.clone());
    }
    Ok(roots)
}
fn inspect(files: &Files, account: &Account) -> Result<Output> {
    if !fs::metadata(files.path("/usr/bin/composer")).is_ok_and(|m| m.mode() & 0o111 != 0) {
        return Err(Error::Absent {});
    }
    for (path, uid, gid, dir) in [
        (files.path("/opt/orbit"), files.uid, files.gid, true),
        (files.path(SCOPE), account.uid, account.gid, true),
        (
            files.path(SCOPE).join("composer.json"),
            account.uid,
            account.gid,
            false,
        ),
    ] {
        if !check(&path, uid, gid, dir)? {
            return Err(Error::Absent {});
        }
    }
    Ok(Output {
        roots: roots(&files.path(SCOPE).join("composer.json"), account)?,
    })
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "tool.inspect-composer-scope";
    const SUMMARY: &'static str = "Read Composer root requirements from the owned shared scope.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Empty;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(_: Empty, _: &Context) -> Result<Output> {
        let name = identity::user_name(identity::effective_uid()).ok_or(Error::Invalid {})?;
        let account = Account::named(&name).map_err(|_| Error::Invalid {})?;
        inspect(&Files::system(), &account)
    }
}
#[cfg(test)]
mod tests;

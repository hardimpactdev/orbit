//! Native Composer scope ownership, manifest discovery, and Linux first-use setup.
use super::recipe::{self, store::Account, Files, Host, Invocation, WorkDir};
use crate::command::{identity, Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::value::RawValue;
use std::{
    collections::BTreeMap,
    fs,
    io::{self, Read},
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::Path,
    time::Duration,
};
crate::command_errors! {
    pub enum Error {
        Invalid {} => "tools.composer_account_invalid", "The Composer scope account is invalid.";
        Absent {} => "tools.composer_scope_absent", "The Composer global scope is absent.";
        Conflict {} => "tools.composer_scope_conflict", "The Composer scope conflicts with Orbit ownership.";
        Unreadable {} => "tools.composer_scope_unreadable", "The Composer global manifest cannot be read.";
        Failed {} => "tools.composer_materialize_failed", "The Composer manager could not be materialized.";
    }
}
type Result<T> = std::result::Result<T, Error>;
const SCOPE: &str = "/opt/orbit/composer";
const MAX_MANIFEST: u64 = 1_048_576;
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    user: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Output {
    roots: Vec<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Done {}
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
fn directory(files: &Files, path: &Path, uid: u32, gid: u32) -> Result<()> {
    files
        .directory(path, uid, gid)
        .map_err(|_| Error::Failed {})?;
    if fs::metadata(path).map_err(|_| Error::Failed {})?.mode() & 0o7777 != 0o755 {
        fs::set_permissions(path, fs::Permissions::from_mode(0o755))
            .map_err(|_| Error::Failed {})?;
    }
    Ok(())
}
fn materialize(host: &mut impl Host, files: &Files, account: &Account) -> Result<Done> {
    let parent = files.path("/opt/orbit");
    let scope = files.path(SCOPE);
    let manifest = scope.join("composer.json");
    let dirs = [
        (parent.clone(), files.uid, files.gid),
        (scope.clone(), account.uid, account.gid),
        (scope.join("vendor"), account.uid, account.gid),
        (scope.join("vendor/bin"), account.uid, account.gid),
    ];
    for (path, uid, gid) in &dirs {
        check(path, *uid, *gid, true)?;
    }
    check(&manifest, account.uid, account.gid, false)?;
    for args in [
        vec![
            "/usr/bin/apt-get",
            "-o",
            "DPkg::Lock::Timeout=300",
            "update",
        ],
        vec![
            "/usr/bin/apt-get",
            "-o",
            "DPkg::Lock::Timeout=300",
            "install",
            "--yes",
            "--no-install-recommends",
            "--no-remove",
            "--",
            "composer",
            "git",
            "unzip",
        ],
    ] {
        let mut invocation = Invocation::new(&args)
            .env("DEBIAN_FRONTEND", "noninteractive")
            .env("LC_ALL", "C");
        invocation.timeout = Duration::from_secs(900);
        recipe::run(host, &invocation, true).map_err(|_| Error::Failed {})?;
    }
    for (path, uid, gid) in &dirs {
        directory(files, path, *uid, *gid)?;
    }
    if !check(&manifest, account.uid, account.gid, false)? {
        let work = WorkDir::within(&parent).map_err(|_| Error::Failed {})?;
        let temporary = work.0.join("composer.json");
        files
            .publish(
                &temporary,
                b"{\"require\":{}}\n",
                0o644,
                account.uid,
                account.gid,
                false,
            )
            .map_err(|_| Error::Failed {})?;
        match fs::hard_link(&temporary, &manifest) {
            Ok(()) => {}
            Err(error) if error.kind() == io::ErrorKind::AlreadyExists => {
                check(&manifest, account.uid, account.gid, false)?;
            }
            Err(_) => return Err(Error::Failed {}),
        }
    }
    let scope_env = format!("COMPOSER_HOME={}", scope.display());
    let home_env = format!("HOME={}", account.home.display());
    let mut args = vec![];
    if identity::effective_uid() != account.uid {
        args.extend(["/usr/sbin/runuser", "-u", &account.name, "--"]);
    }
    args.extend([
        "/usr/bin/env",
        "-C",
        account.home.to_str().ok_or(Error::Invalid {})?,
        &home_env,
        &scope_env,
        "/usr/bin/composer",
        "--version",
        "--no-ansi",
    ]);
    recipe::run(host, &Invocation::new(&args), true).map_err(|_| Error::Failed {})?;
    Ok(Done {})
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "tools.inspect-composer-scope";
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
pub struct Materialize;
impl Command for Materialize {
    const NAME: &'static str = "tools.materialize-composer";
    const SUMMARY: &'static str =
        "Install Composer prerequisites and prepare the account-owned shared scope.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["packages"];
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = Input;
    type Output = Done;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("tools:composer".into())
    }
    fn run(input: Input, context: &Context) -> Result<Done> {
        let account = Account::named(&input.user).map_err(|_| Error::Invalid {})?;
        materialize(&mut recipe::Native(context), &Files::system(), &account)
    }
}
#[cfg(test)]
mod tests;

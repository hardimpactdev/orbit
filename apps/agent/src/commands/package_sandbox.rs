//! Fixed package-account preparation. This alone never enables package execution.
use crate::{
    command::{identity, Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    os::unix::fs::{DirBuilderExt, MetadataExt},
    path::Path,
    process::{Command as Process, Stdio},
    time::Duration,
};
pub(super) mod runtime;
const USER: &str = "orbit-swamp-package";
const STATE: &str = "/var/lib/orbit-swamp-package";
command_errors! {
    pub enum Error {
        Worker {} => "package.worker_invalid", "The package boundary requires an existing distinct non-root worker account.";
        Account {} => "package.account_invalid", "The existing package account does not match its isolated system-account contract.";
        Workspace {} => "package.workspace_unsafe", "The package workspace parent has unsafe ownership, permissions or a symlink.";
        Failed {} => "package.prepare_failed", "The package system account could not be prepared.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    worker_user: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Output {
    account: String,
    uid: u32,
    gid: u32,
    jobs_directory: String,
    changed: bool,
}
#[derive(Clone)]
struct Account {
    uid: u32,
    gid: u32,
    home: String,
    shell: String,
    group: String,
    groups: Vec<u32>,
}
trait Accounts {
    fn lookup(&self, user: &str) -> Option<Account>;
    fn create(&mut self) -> Result<(), Error>;
}
struct Native;
impl Accounts for Native {
    fn lookup(&self, user: &str) -> Option<Account> {
        let p = identity::passwd(user)?;
        let name = std::ffi::CString::new(user).ok()?;
        Some(Account {
            uid: p.uid,
            gid: p.gid,
            home: p.home,
            shell: p.shell,
            group: identity::group_name(p.gid)?,
            groups: identity::group_list(&name, p.gid)?,
        })
    }
    fn create(&mut self) -> Result<(), Error> {
        let status = Process::new("/usr/sbin/useradd")
            .args([
                "--system",
                "--user-group",
                "--no-create-home",
                "--home-dir",
                "/nonexistent",
                "--shell",
                "/usr/sbin/nologin",
                USER,
            ])
            .env_clear()
            .env("PATH", "/usr/sbin:/usr/bin:/sbin:/bin")
            .env("LANG", "C")
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .map_err(|_| Error::Failed {})?;
        if !status.success() {
            return Err(Error::Failed {});
        }
        Ok(())
    }
}
fn valid_worker(name: &str) -> bool {
    !name.is_empty()
        && name.len() <= 64
        && name != USER
        && name.bytes().enumerate().all(|(i, b)| {
            b.is_ascii_lowercase() || b == b'_' || (i > 0 && (b.is_ascii_digit() || b == b'-'))
        })
}
fn check_account(p: &Account, worker: &Account) -> Result<(), Error> {
    if p.uid == 0
        || p.gid == 0
        || p.uid == worker.uid
        || p.gid == worker.gid
        || p.home != "/nonexistent"
        || p.shell != "/usr/sbin/nologin"
        || p.group != USER
        || p.groups.iter().any(|g| *g != p.gid)
        || !p.groups.contains(&p.gid)
    {
        return Err(Error::Account {});
    }
    Ok(())
}
fn directory(path: &Path, owner: u32, private: bool) -> Result<(), Error> {
    let m = fs::symlink_metadata(path).map_err(|_| Error::Workspace {})?;
    if !m.is_dir()
        || m.uid() != owner
        || m.gid() != owner
        || (private && m.mode() & 0o7777 != 0o700)
        || m.mode() & 0o022 != 0
    {
        return Err(Error::Workspace {});
    }
    Ok(())
}
fn prepare(
    accounts: &mut impl Accounts,
    worker: &str,
    root: &Path,
    owner: u32,
) -> Result<Output, Error> {
    if !valid_worker(worker) {
        return Err(Error::Worker {});
    }
    let worker = accounts
        .lookup(worker)
        .filter(|a| a.uid != 0 && a.gid != 0)
        .ok_or(Error::Worker {})?;
    let state = root.join(STATE.trim_start_matches('/'));
    let state = state.as_path();
    // Refuse unsafe ancestors before creating an account or any workspace.
    for parent in state
        .ancestors()
        .skip(1)
        .take_while(|p| p.starts_with(root))
    {
        directory(parent, owner, false)?;
    }
    let jobs = state.join("jobs");
    for path in [state, jobs.as_path()] {
        if path.try_exists().map_err(|_| Error::Workspace {})? {
            directory(path, owner, true)?;
        }
        // A broken symlink must not be mistaken for an absent path.
        else if fs::symlink_metadata(path).is_ok() {
            return Err(Error::Workspace {});
        }
    }
    let mut changed = false;
    let account = match accounts.lookup(USER) {
        Some(p) => p,
        None => {
            accounts.create()?;
            changed = true;
            accounts.lookup(USER).ok_or(Error::Failed {})?
        }
    };
    check_account(&account, &worker)?;
    if accounts
        .lookup("orbit")
        .is_some_and(|a| a.uid == account.uid || a.gid == account.gid)
    {
        return Err(Error::Account {});
    }
    for path in [state, jobs.as_path()] {
        if !path.exists() {
            fs::DirBuilder::new()
                .mode(0o700)
                .create(path)
                .map_err(|_| Error::Workspace {})?;
            changed = true;
        }
        directory(path, owner, true)?;
    }
    Ok(Output {
        account: USER.into(),
        uid: account.uid,
        gid: account.gid,
        jobs_directory: jobs.display().to_string(),
        changed,
    })
}
pub struct Prepare;
impl Command for Prepare {
    const NAME: &'static str = "package.prepare-account";
    const SUMMARY: &'static str =
        "Prepare the distinct unprivileged package account and private workspace parent.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["package-account"];
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(p: Platform) -> Option<Privilege> {
        super::linux_only(p, Privilege::Root)
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        prepare(&mut Native, &input.worker_user, Path::new("/"), 0)
    }
}
#[cfg(test)]
mod tests;

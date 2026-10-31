//! Homebrew ownership and Linux pinned first-use setup. Package policy stays in the Gateway.
mod macos;
use super::recipe::{self, store::Account, Files, Host, Invocation};
use crate::command::{identity, Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    time::Duration,
};
crate::command_errors! {
    pub enum Error {
        Invalid {} => "tool.homebrew_account_invalid", "The Homebrew scope account is invalid.";
        Absent {} => "tool.homebrew_scope_absent", "The Homebrew prefix is absent for the enrolled account.";
        Conflict {} => "tool.homebrew_scope_conflict", "The Homebrew prefix ownership or origin conflicts with the enrolled account.";
        Unreadable {} => "tool.homebrew_scope_unreadable", "The Homebrew prefix cannot be inspected.";
        Failed {} => "tool.homebrew_materialize_failed", "The Homebrew manager could not be materialized.";
    }
}
type Result<T> = std::result::Result<T, Error>;
const PREFIX: &str = "/home/linuxbrew/.linuxbrew";
const ORIGIN: &str = "https://github.com/Homebrew/brew";
pub(super) const VERSION: &str = "Homebrew 7.0.0";
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Output {
    pub(super) prefix: PathBuf,
    pub(super) home: PathBuf,
}
fn metadata(path: &Path) -> Result<Option<fs::Metadata>> {
    recipe::metadata(path).map_err(|_| Error::Unreadable {})
}
fn directory(path: &Path, uid: u32, gid: u32) -> Result<bool> {
    let Some(meta) = metadata(path)? else {
        return Ok(false);
    };
    if !meta.is_dir() || meta.uid() != uid || meta.gid() != gid {
        return Err(Error::Conflict {});
    }
    Ok(true)
}
fn account_call(account: &Account, args: &[&str]) -> Result<Invocation> {
    let home = account.home.to_str().ok_or(Error::Invalid {})?;
    let home_env = format!("HOME={home}");
    let mut argv = vec![];
    if identity::effective_uid() != account.uid {
        argv.extend(["/usr/sbin/runuser", "-u", &account.name, "--"]);
    }
    argv.extend(["/usr/bin/env", "-C", home, &home_env]);
    argv.extend_from_slice(args);
    let mut invocation = Invocation::new(&argv);
    invocation.timeout = Duration::from_secs(900);
    Ok(invocation)
}
fn git(
    host: &mut impl Host,
    account: &Account,
    repo: &Path,
    args: &[&str],
) -> Result<recipe::Output> {
    let mut argv = vec![
        "/usr/bin/git",
        "-C",
        repo.to_str().ok_or(Error::Invalid {})?,
    ];
    argv.extend_from_slice(args);
    host.execute(&account_call(account, &argv)?)
        .map_err(|_| Error::Unreadable {})
}
fn git_value(
    host: &mut impl Host,
    account: &Account,
    repo: &Path,
    args: &[&str],
) -> Result<String> {
    let output = git(host, account, repo, args)?;
    if output.code != 0 || output.truncated {
        return Err(Error::Conflict {});
    }
    Ok(output.stdout.trim_end_matches(['\n', '\r']).into())
}
fn inspect_at(host: &mut impl Host, prefix: &Path, account: &Account) -> Result<()> {
    if !directory(prefix, account.uid, account.gid)? {
        return Err(Error::Absent {});
    }
    let repo = prefix.join("Homebrew");
    if !directory(&repo, account.uid, account.gid)? {
        return Err(Error::Conflict {});
    }
    if !metadata(&repo.join(".git"))?.is_some_and(|m| m.is_dir()) {
        return Err(Error::Conflict {});
    }
    let brew = prefix.join("bin/brew");
    if !metadata(&brew)?
        .is_some_and(|m| m.is_symlink() && m.uid() == account.uid && m.gid() == account.gid)
        || fs::read_link(&brew).ok().as_deref() != Some(Path::new("../Homebrew/bin/brew"))
    {
        return Err(Error::Conflict {});
    }
    if git_value(
        host,
        account,
        &repo,
        &["config", "--get", "remote.origin.url"],
    )? != ORIGIN
        || !git_value(
            host,
            account,
            &repo,
            &["status", "--porcelain=v1", "--untracked-files=all"],
        )?
        .is_empty()
    {
        return Err(Error::Conflict {});
    }
    Ok(())
}
fn inspect(host: &mut impl Host, files: &Files, account: &Account) -> Result<Output> {
    directory(&files.path("/home/linuxbrew"), files.uid, files.gid)?;
    let prefix = files.path(PREFIX);
    inspect_at(host, &prefix, account)?;
    Ok(Output {
        prefix,
        home: account.home.clone(),
    })
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "tool.inspect-homebrew-scope";
    const SUMMARY: &'static str =
        "Inspect the enrolled account's Homebrew prefix without changing it.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Empty;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        match platform {
            Platform::Linux | Platform::Macos => Some(Privilege::Account),
        }
    }
    fn run(_: Empty, context: &Context) -> Result<Output> {
        let name = identity::user_name(identity::effective_uid()).ok_or(Error::Invalid {})?;
        let account = Account::named(&name).map_err(|_| Error::Invalid {})?;
        match context.platform {
            Platform::Linux => inspect(&mut recipe::Native(context), &Files::system(), &account),
            Platform::Macos => {
                macos::inspect(&mut recipe::Native(context), &Files::system(), &account)
            }
        }
    }
}
#[cfg(test)]
mod tests;

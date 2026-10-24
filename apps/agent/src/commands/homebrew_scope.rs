//! Linux Homebrew ownership and pinned first-use setup. Package policy stays in the Gateway.
use super::recipe::{self, store::Account, Files, Host, Invocation, WorkDir};
use crate::command::{identity, Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{symlink, MetadataExt, PermissionsExt},
    path::{Path, PathBuf},
    time::Duration,
};
crate::command_errors! {
    pub enum Error {
        Invalid {} => "tools.homebrew_account_invalid", "The Homebrew scope account is invalid.";
        Absent {} => "tools.homebrew_scope_absent", "The Homebrew prefix is absent for the enrolled account.";
        Conflict {} => "tools.homebrew_scope_conflict", "The Homebrew prefix ownership or origin conflicts with the enrolled account.";
        Unreadable {} => "tools.homebrew_scope_unreadable", "The Homebrew prefix cannot be inspected.";
        Failed {} => "tools.homebrew_materialize_failed", "The Homebrew manager could not be materialized.";
    }
}
type Result<T> = std::result::Result<T, Error>;
const PREFIX: &str = "/home/linuxbrew/.linuxbrew";
const ORIGIN: &str = "https://github.com/Homebrew/brew";
const REVISION: &str = "d79ef822ab8136e393ed5f86e2b56afc68d04874";
const TAG: &str = "7.0.0";
const VERSION: &str = "Homebrew 7.0.0";
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    user: String,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Output {
    prefix: PathBuf,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Done {}
fn metadata(path: &Path) -> Result<Option<fs::Metadata>> {
    match fs::symlink_metadata(path) {
        Ok(meta) => Ok(Some(meta)),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(Error::Unreadable {}),
    }
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
fn successful(output: recipe::Output) -> Result<String> {
    if output.code != 0 || output.truncated {
        return Err(Error::Failed {});
    }
    Ok(output.stdout)
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
    Ok(Output { prefix })
}
fn brew_call(account: &Account, prefix: &Path, args: &[&str]) -> Result<Invocation> {
    let path = format!("PATH={}/bin:/usr/bin:/bin", prefix.display());
    let binary = prefix.join("bin/brew");
    let mut argv = vec![
        "/usr/bin/env",
        "HOMEBREW_NO_AUTO_UPDATE=1",
        "HOMEBREW_NO_ANALYTICS=1",
        "HOMEBREW_NO_ENV_HINTS=1",
        "HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1",
        "HOMEBREW_NO_INSTALL_CLEANUP=1",
        &path,
        binary.to_str().ok_or(Error::Invalid {})?,
    ];
    argv.extend_from_slice(args);
    account_call(account, &argv)
}
fn pin(host: &mut impl Host, prefix: &Path, account: &Account) -> Result<()> {
    let repo = prefix.join("Homebrew");
    if git_value(host, account, &repo, &["rev-parse", "HEAD"])? != REVISION {
        successful(git(
            host,
            account,
            &repo,
            &["fetch", "--filter=blob:none", "origin", REVISION],
        )?)?;
        successful(git(
            host,
            account,
            &repo,
            &[
                "-c",
                "advice.detachedHead=false",
                "checkout",
                "--detach",
                REVISION,
            ],
        )?)?;
    }
    inspect_at(host, prefix, account)?;
    if git_value(host, account, &repo, &["rev-parse", "HEAD"])? != REVISION {
        return Err(Error::Failed {});
    }
    let tag = git(
        host,
        account,
        &repo,
        &["show-ref", "--verify", "--quiet", "refs/tags/7.0.0"],
    )?;
    if tag.truncated {
        return Err(Error::Failed {});
    }
    if tag.code != 0 {
        successful(git(
            host,
            account,
            &repo,
            &["fetch", "--filter=blob:none", "origin", "tag", TAG],
        )?)?;
        let cache = repo.join(".git/describe-cache");
        if metadata(&cache)?.is_some() {
            directory(&cache, account.uid, account.gid)?;
            fs::remove_dir_all(cache).map_err(|_| Error::Failed {})?;
        }
    }
    if git_value(
        host,
        account,
        &repo,
        &["rev-parse", "--verify", "7.0.0^{commit}"],
    )? != REVISION
    {
        return Err(Error::Failed {});
    }
    let output = successful(
        host.execute(&brew_call(account, prefix, &["--version"])?)
            .map_err(|_| Error::Failed {})?,
    )?;
    if super::tool_output::lines(&output).next() != Some(VERSION) {
        return Err(Error::Failed {});
    }
    successful(
        host.execute(&brew_call(account, prefix, &["config"])?)
            .map_err(|_| Error::Failed {})?,
    )?;
    Ok(())
}
fn materialize(host: &mut impl Host, files: &Files, account: &Account) -> Result<Done> {
    let parent = files.path("/home/linuxbrew");
    let prefix = files.path(PREFIX);
    directory(&parent, files.uid, files.gid)?;
    let exists = metadata(&prefix)?.is_some();
    if exists {
        inspect_at(host, &prefix, account)?;
    }
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
            "build-essential",
            "procps",
            "curl",
            "file",
            "git",
            "ca-certificates",
        ],
    ] {
        let mut invocation = Invocation::new(&args)
            .env("DEBIAN_FRONTEND", "noninteractive")
            .env("LC_ALL", "C");
        invocation.timeout = Duration::from_secs(900);
        recipe::run(host, &invocation, true).map_err(|_| Error::Failed {})?;
    }
    files
        .directory(&parent, files.uid, files.gid)
        .map_err(|_| Error::Failed {})?;
    if !exists {
        let work = WorkDir::within(&parent).map_err(|_| Error::Failed {})?;
        rustix::fs::chown(
            &work.0,
            Some(rustix::fs::Uid::from_raw(account.uid)),
            Some(rustix::fs::Gid::from_raw(account.gid)),
        )
        .map_err(|_| Error::Failed {})?;
        fs::set_permissions(&work.0, fs::Permissions::from_mode(0o755))
            .map_err(|_| Error::Failed {})?;
        let repo = work.0.join("Homebrew");
        successful(
            host.execute(&account_call(
                account,
                &[
                    "/usr/bin/git",
                    "clone",
                    "--filter=blob:none",
                    "--no-checkout",
                    ORIGIN,
                    repo.to_str().ok_or(Error::Invalid {})?,
                ],
            )?)
            .map_err(|_| Error::Failed {})?,
        )?;
        successful(git(
            host,
            account,
            &repo,
            &[
                "-c",
                "advice.detachedHead=false",
                "checkout",
                "--detach",
                REVISION,
            ],
        )?)?;
        for path in [
            "bin",
            "Cellar",
            "Caskroom",
            "Frameworks",
            "etc",
            "include",
            "lib",
            "opt",
            "sbin",
            "share",
            "var",
            "var/homebrew",
            "var/homebrew/locks",
        ] {
            let path = work.0.join(path);
            files
                .directory(&path, account.uid, account.gid)
                .map_err(|_| Error::Failed {})?;
            fs::set_permissions(&path, fs::Permissions::from_mode(0o775))
                .map_err(|_| Error::Failed {})?;
        }
        let link = work.0.join("bin/brew");
        symlink("../Homebrew/bin/brew", &link).map_err(|_| Error::Failed {})?;
        rustix::fs::chownat(
            rustix::fs::CWD,
            &link,
            Some(rustix::fs::Uid::from_raw(account.uid)),
            Some(rustix::fs::Gid::from_raw(account.gid)),
            rustix::fs::AtFlags::SYMLINK_NOFOLLOW,
        )
        .map_err(|_| Error::Failed {})?;
        inspect_at(host, &work.0, account)?;
        if metadata(&prefix)?.is_some() {
            return Err(Error::Conflict {});
        }
        fs::rename(&work.0, &prefix).map_err(|_| Error::Failed {})?;
    }
    pin(host, &prefix, account)?;
    Ok(Done {})
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "tools.inspect-homebrew-scope";
    const SUMMARY: &'static str =
        "Inspect the enrolled account's Linux Homebrew prefix without changing it.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Empty;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(_: Empty, context: &Context) -> Result<Output> {
        let name = identity::user_name(identity::effective_uid()).ok_or(Error::Invalid {})?;
        let account = Account::named(&name).map_err(|_| Error::Invalid {})?;
        inspect(&mut recipe::Native(context), &Files::system(), &account)
    }
}
pub struct Materialize;
impl Command for Materialize {
    const NAME: &'static str = "tools.materialize-homebrew";
    const SUMMARY: &'static str =
        "Prepare the owned Linux Homebrew prefix and verify its pinned release.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = Input;
    type Output = Done;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("tools:brew".into())
    }
    fn run(input: Input, context: &Context) -> Result<Done> {
        let account = Account::named(&input.user).map_err(|_| Error::Invalid {})?;
        materialize(&mut recipe::Native(context), &Files::system(), &account)
    }
}
#[cfg(test)]
mod tests;

//! Owner-only immutable deployment runtimes, sharing recipe publication rules and its store lock.
mod downloads;
#[cfg(test)]
mod tests;

use super::recipe::{store, store::Account, Failure};
use crate::command::{Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::{
    fs, io,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    time::Duration,
};

crate::command_errors! {
    pub enum Error {
        Invalid {} => "tool.store_invalid", "The runtime requirements or store ownership are invalid.";
        Failed {} => "tool.store_failed", "The runtime store operation failed; check ownership, registry access, and checksums.";
        Busy {} => "tool.store_lock_timeout", "The runtime store lock timed out.";
    }
}
type Result<T> = std::result::Result<T, Error>;
impl From<io::Error> for Error {
    fn from(_: io::Error) -> Self {
        Self::Failed {}
    }
}
impl From<serde_json::Error> for Error {
    fn from(_: serde_json::Error) -> Self {
        Self::Failed {}
    }
}
impl From<Failure> for Error {
    fn from(error: Failure) -> Self {
        if error.stage == "store_lock_timeout" {
            Self::Busy {}
        } else {
            Self::Failed {}
        }
    }
}

#[derive(schemars::JsonSchema, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Environment {
    pub store: PathBuf,
    pub node_version: String,
    pub package_manager: String,
    pub package_manager_version: String,
    #[serde(default)]
    pub package_manager_hash: Option<String>,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectInput {
    pub environments: Vec<Environment>,
}
#[derive(schemars::JsonSchema, Serialize)]
pub struct Inspected {
    pub installed: Vec<bool>,
}
fn account() -> Result<Account> {
    let uid = rustix::process::geteuid().as_raw();
    if uid == 0 {
        return Err(Error::Invalid {});
    }
    Ok(Account {
        name: crate::command::identity::user_name(uid).ok_or(Error::Invalid {})?,
        uid,
        gid: rustix::process::getegid().as_raw(),
        home: PathBuf::new(),
    })
}
fn home(path: &Path, account: &Account) -> Result<()> {
    let text = path.to_str().ok_or(Error::Invalid {})?;
    if !path.is_absolute()
        || text.contains('\0')
        || text.contains("//")
        || text.split('/').any(|part| part == "." || part == "..")
    {
        return Err(Error::Invalid {});
    }
    store::check_home(path, account, false).map_err(|_| Error::Invalid {})
}
impl Environment {
    fn paths(&self, account: &Account) -> Result<(PathBuf, PathBuf)> {
        home(&self.store, account)?;
        if !store::version(&self.node_version)
            || !store::version(&self.package_manager_version)
            || !matches!(self.package_manager.as_str(), "npm" | "pnpm")
            || self
                .package_manager_hash
                .as_ref()
                .is_some_and(|pin| !downloads::valid_pin(pin))
        {
            return Err(Error::Invalid {});
        }
        let node = self.store.join("js_runtime/node").join(&self.node_version);
        let manager = self
            .store
            .join("package_manager")
            .join(&self.package_manager)
            .join(&self.package_manager_version);
        for path in [&node, &manager] {
            store::check_path(&self.store, path, account).map_err(|_| Error::Invalid {})?;
        }
        Ok((node, manager))
    }
}
fn executable(path: &Path) -> bool {
    fs::metadata(path).is_ok_and(|meta| meta.is_file() && meta.mode() & 0o111 != 0)
}
fn installed(input: &Environment, account: &Account) -> Result<bool> {
    let (node, manager) = input.paths(account)?;
    if !executable(&node.join("bin/node")) {
        return Ok(false);
    }
    let package = manager.join(&input.package_manager);
    let manifest = match fs::read(package.join("package.json")) {
        Ok(bytes) => serde_json::from_slice::<Value>(&bytes).ok(),
        Err(e) if e.kind() == io::ErrorKind::NotFound => None,
        Err(e) => return Err(e.into()),
    };
    Ok(manifest.is_some_and(|manifest| {
        manifest["name"] == input.package_manager
            && manifest["version"] == input.package_manager_version
            && executable(&package.join("bin").join(&input.package_manager))
    }))
}
fn inspect(input: &InspectInput, account: &Account) -> Result<Inspected> {
    Ok(Inspected {
        installed: input
            .environments
            .iter()
            .map(|env| installed(env, account))
            .collect::<Result<_>>()?,
    })
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "tool.inspect-vp-store";
    const SUMMARY: &'static str =
        "Inspect exact immutable deployment runtimes without locks or network access.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = InspectInput;
    type Output = Inspected;
    type Error = Error;
    fn privilege(_: Platform) -> Option<Privilege> {
        Some(Privilege::Account)
    }
    fn run(input: InspectInput, _: &Context) -> Result<Inspected> {
        inspect(&input, &account()?)
    }
}

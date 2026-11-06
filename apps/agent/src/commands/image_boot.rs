//! `image.owner`: the image builder's `orbit` account has the pinned UID and GID.
use crate::{
    command::{identity, Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    path::{Path, PathBuf},
    time::Duration,
};
command_errors! {
    pub enum Error {
        Failed { stage: String, reason: String } => "task_vm.image_check_failed", "The image check or boot preparation failed.";
    }
}
fn failed(stage: &str, reason: impl ToString) -> Error {
    Error::Failed {
        stage: stage.into(),
        reason: reason.to_string(),
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Ready {
    ready: bool,
}
trait Host {
    fn account(&self) -> Result<(u32, u32, String), String>;
}
struct Native;
impl Host for Native {
    fn account(&self) -> Result<(u32, u32, String), String> {
        identity::account("orbit")
    }
}
fn owner(host: &impl Host) -> Result<PathBuf, Error> {
    let (uid, gid, home) = host.account().map_err(|e| failed("owner", e))?;
    if uid != 1000 || gid != 1000 || !Path::new(&home).is_absolute() {
        return Err(failed(
            "owner",
            "The orbit account must have UID and GID 1000 and an absolute home.",
        ));
    }
    Ok(PathBuf::from(home))
}
pub struct CheckOwner;
impl Command for CheckOwner {
    const NAME: &'static str = "image.owner";
    const SUMMARY: &'static str = "Check the image builder's pinned orbit UID and GID.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = Empty;
    type Output = Ready;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(_: Empty, _: &Context) -> Result<Ready, Error> {
        owner(&Native)?;
        Ok(Ready { ready: true })
    }
}
#[cfg(test)]
mod tests;

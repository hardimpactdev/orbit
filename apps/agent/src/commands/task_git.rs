//! Local checkout facts for the Gateway's credential-free Git pack transport.
use super::recipe::{self, Host, Invocation};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{collections::HashSet, path::PathBuf, time::Duration};

const MAX_HAVES: usize = 1000;

command_errors! {
    pub enum Error {
        Invalid {} => "task_vm.git_input_invalid", "The checkout must be an absolute path.";
        Unreadable {} => "task_vm.git_unreadable", "The checkout's Git refs could not be read.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    checkout: PathBuf,
}

#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct Refs {
    haves: Vec<String>,
    baseline: Option<String>,
    branch: Option<String>,
    head: Option<String>,
}

pub(super) fn object_id(value: &str) -> bool {
    matches!(value.len(), 40 | 64)
        && value
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}

fn read(input: &Input, host: &mut impl Host) -> Result<Refs, Error> {
    if !super::doctor_route::absolute(&input.checkout) {
        return Err(Error::Invalid {});
    }
    let checkout = input.checkout.to_str().ok_or(Error::Invalid {})?;
    let mut git = |args: &[&str], optional: bool| -> Result<Option<String>, Error> {
        let mut call = Invocation::new(&[
            "/usr/bin/git",
            "-c",
            "core.hooksPath=/dev/null",
            "-c",
            "core.fsmonitor=false",
            "-C",
            checkout,
        ])
        .env("GIT_OPTIONAL_LOCKS", "0");
        call.argv.extend(args.iter().map(|arg| (*arg).to_owned()));
        let output = recipe::run(host, &call, false).map_err(|_| Error::Unreadable {})?;
        match output.code {
            0 => Ok(Some(output.stdout.trim_end_matches('\n').to_owned())),
            1 if optional => Ok(None),
            _ => Err(Error::Unreadable {}),
        }
    };
    // Bound Git's output too: more refs only reduce packing efficiency, never correctness.
    let count = format!("--count={MAX_HAVES}");
    let refs = git(&["for-each-ref", &count, "--format=%(objectname)"], false)?
        .ok_or(Error::Unreadable {})?;
    let mut seen = HashSet::new();
    let haves = refs
        .lines()
        .filter(|id| object_id(id) && seen.insert((*id).to_owned()))
        .take(MAX_HAVES)
        .map(str::to_owned)
        .collect();
    let baseline = git(
        &[
            "rev-parse",
            "--verify",
            "--quiet",
            "refs/worktree/orbit/ci-baseline",
        ],
        true,
    )?;
    let branch = git(&["symbolic-ref", "--quiet", "--short", "HEAD"], true)?;
    let head = git(&["rev-parse", "--verify", "--quiet", "HEAD"], true)?;
    if baseline.iter().chain(head.iter()).any(|id| !object_id(id)) {
        return Err(Error::Unreadable {});
    }
    Ok(Refs {
        haves,
        baseline,
        branch,
        head,
    })
}

pub struct ReadRefs;
impl Command for ReadRefs {
    const NAME: &'static str = "task-vm.read-git-refs";
    const SUMMARY: &'static str =
        "Read checkout refs and its worktree-private CI baseline for pack transfer.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Refs;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Input, context: &Context) -> Result<Refs, Error> {
        read(&input, &mut recipe::Native(context))
    }
}

#[cfg(test)]
#[path = "task_git_tests.rs"]
mod tests;

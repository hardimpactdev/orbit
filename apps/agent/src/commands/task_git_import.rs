//! Import the Gateway mirror's pack and approved refs as the checkout account, without network Git.
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{collections::HashSet, path::PathBuf, process::Command as Process, time::Duration};

command_errors! {
    pub enum Error {
        Invalid {} => "task_vm.git_import_invalid", "The checkout, ref updates, or default branch are invalid.";
        Pack {} => "task_vm.git_pack_invalid", "The Git pack could not be indexed.";
        Failed {} => "task_vm.git_import_failed", "The checkout's approved refs could not be updated.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Update {
    reference: String,
    commit: Option<String>,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    checkout: PathBuf,
    default_branch: Option<String>,
    updates: Vec<Update>,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct Output {
    imported: bool,
}
fn git(input: &Input, context: &Context, arguments: &[&str]) -> Process {
    let mut command = Process::new("/usr/bin/timeout");
    command.args([
        "--signal=TERM",
        "--kill-after=1",
        &format!("{:.6}s", context.remaining().as_secs_f64().max(0.001)),
        "/usr/bin/git",
    ]);
    super::git_read::GitRead::default().configure(&mut command);
    command.args(super::git_read::GitRead::default().fetch_options(&[]));
    command
        .args(["-c", "gc.auto=0", "-c", "maintenance.auto=false", "-C"])
        .arg(&input.checkout)
        .args(arguments)
        .env("GIT_NO_LAZY_FETCH", "1");
    command
}
fn call(
    input: &Input,
    context: &Context,
    arguments: &[&str],
    data: Option<&str>,
) -> Result<super::incus::Output, Error> {
    if context.remaining().is_zero() {
        return Err(Error::Failed {});
    }
    let output = super::incus::capture_input(&mut git(input, context, arguments), data)
        .map_err(|_| Error::Failed {})?;
    if output.truncated {
        return Err(Error::Failed {});
    }
    Ok(output)
}
fn valid_ref(value: &str) -> bool {
    (value.starts_with("refs/remotes/origin/") && value != "refs/remotes/origin/HEAD")
        || value
            .strip_prefix("refs/orbit/ci-cache/")
            .is_some_and(super::task_git::object_id)
        || value == "refs/worktree/orbit/ci-baseline"
}
fn import(input: &Input, context: &Context) -> Result<Output, Error> {
    let mut seen = HashSet::new();
    if !super::doctor_route::absolute(&input.checkout)
        || input.updates.len() > 1000
        || input.updates.iter().any(|update| {
            !valid_ref(&update.reference)
                || !seen.insert(&update.reference)
                || update
                    .commit
                    .as_ref()
                    .is_some_and(|id| !super::task_git::object_id(id))
        })
    {
        return Err(Error::Invalid {});
    }
    let default = input
        .default_branch
        .as_ref()
        .map(|branch| format!("refs/remotes/origin/{branch}"));
    for reference in input
        .updates
        .iter()
        .map(|update| &update.reference)
        .chain(default.iter())
    {
        if reference.bytes().any(|byte| byte.is_ascii_whitespace())
            || call(input, context, &["check-ref-format", reference], None)?.code != 0
        {
            return Err(Error::Invalid {});
        }
    }
    if input
        .default_branch
        .as_ref()
        .is_some_and(|branch| branch.is_empty() || branch == "HEAD" || branch.starts_with('-'))
    {
        return Err(Error::Invalid {});
    }
    let pack = context.take_binary_input().ok_or(Error::Pack {})?;
    let output = super::incus::capture_file(
        &mut git(
            input,
            context,
            &[
                "index-pack",
                "--stdin",
                "--fix-thin",
                "--max-input-size=2147483648",
            ],
        ),
        pack,
    )
    .map_err(|_| Error::Pack {})?;
    if output.code != 0 || output.truncated {
        return Err(Error::Pack {});
    }
    // Validate every target before starting one ref transaction. A missing target cannot update earlier refs.
    for commit in input
        .updates
        .iter()
        .filter_map(|update| update.commit.as_ref())
    {
        let target = call(input, context, &["cat-file", "-t", commit], None)?;
        if target.code != 0 || target.stdout.trim() != "commit" {
            return Err(Error::Failed {});
        }
    }
    if !input.updates.is_empty() {
        let mut transaction = "start\noption no-deref\n".to_owned();
        for update in &input.updates {
            transaction.push_str(&match &update.commit {
                Some(commit) => format!("update {} {commit}\n", update.reference),
                None => format!("delete {}\n", update.reference),
            });
        }
        transaction.push_str("prepare\ncommit\n");
        if call(
            input,
            context,
            &["update-ref", "--stdin"],
            Some(&transaction),
        )?
        .code
            != 0
        {
            return Err(Error::Failed {});
        }
    }
    if let Some(default) = default {
        if call(
            input,
            context,
            &["rev-parse", "--verify", "--quiet", &default],
            None,
        )?
        .code
            == 0
            && call(
                input,
                context,
                &["symbolic-ref", "refs/remotes/origin/HEAD", &default],
                None,
            )?
            .code
                != 0
        {
            return Err(Error::Failed {});
        }
    }
    Ok(Output { imported: true })
}
pub struct ImportPack;
impl Command for ImportPack {
    const NAME: &'static str = "task-vm.import-git-pack";
    const SUMMARY: &'static str =
        "Import a mirror pack and set approved checkout refs without network access.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(1800);
    const INPUT_MAX_BYTES: u64 = 2 * 1024 * 1024 * 1024;
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Input, context: &Context) -> Result<Output, Error> {
        import(&input, context)
    }
}
#[cfg(test)]
#[path = "task_git_import_tests.rs"]
mod tests;

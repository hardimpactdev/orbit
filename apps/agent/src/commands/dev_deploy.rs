//! `instance.run-lifecycle-step`: a development default runs its setup or teardown step in its own checkout, as the managed account.
//! A setup or teardown step holds an exclusive `flock` on the checkout directory while it runs, so the checkout and
//! each step take the same lock without waiting. These replace the Bash of `DevelopmentCheckoutProgram`, check for
//! check.

use super::deploy_step::{
    run_program, Chunk, DeployStepError, Program, RunDeployStepOutput, StepCommand,
};
use super::source::{canonical, is_symlink};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::Deserialize;
use std::{
    collections::BTreeMap,
    fs,
    io::Write,
    os::unix::fs::{MetadataExt, OpenOptionsExt},
    path::{Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum DevDeployError {
        Invalid { check: String } => "instance.development_checkout_invalid", "The checkout does not pass its checks.";
        Dirty { changes: String } => "instance.checkout_dirty", "The checkout has uncommitted changes to tracked files.";
        Diverged {} => "instance.branch_diverged", "The local branch has commits that the target commit does not contain.";
        NotOnBranch {} => "instance.commit_not_on_branch", "The branch does not contain the commit.";
        Busy {} => "instance.lifecycle_busy", "A setup or teardown step is running in the checkout.";
        StepFailed { step: String } => "instance.development_step_failed", "A checkout step failed.";
    }
}

fn check(condition: bool, what: &str) -> Result<(), DevDeployError> {
    if condition {
        Ok(())
    } else {
        Err(DevDeployError::Invalid { check: what.into() })
    }
}

fn failed(step: &str) -> DevDeployError {
    DevDeployError::StepFailed { step: step.into() }
}

/// The lifecycle lock of a checkout: an exclusive `flock` on the directory itself, so it leaves no file behind.
fn lifecycle_lock(home: &Path) -> Result<fs::File, DevDeployError> {
    let directory = fs::File::open(home).map_err(|_| failed("open the checkout"))?;
    check(!is_symlink(home), "the checkout is a directory")?;
    let opened = fs::metadata(format!(
        "/proc/self/fd/{}",
        std::os::fd::AsRawFd::as_raw_fd(&directory)
    ));
    let named = fs::symlink_metadata(home);
    check(
        matches!((opened, named), (Ok(opened), Ok(named)) if opened.dev() == named.dev() && opened.ino() == named.ino()),
        "the lock holds the checkout",
    )?;
    rustix::fs::flock(
        &directory,
        rustix::fs::FlockOperation::NonBlockingLockExclusive,
    )
    .map_err(|_| DevDeployError::Busy {})?;
    Ok(directory)
}

// ---- a setup or teardown step ------------------------------------------------------------------------------------

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LifecycleInput {
    /// Any Instance checkout of this account, by its canonical path.
    pub checkout: PathBuf,
    pub program: StepProgram,
    // Accepted for the pinned Gateway input; the runner no longer names the step.
    #[allow(dead_code)]
    pub step: String,
    pub timeout_seconds: u64,
    /// Variables the step sees beside the account's own, such as `VP_HOME` and the seed.
    #[serde(default)]
    pub environment: BTreeMap<String, String>,
}

/// A Project shell command, or a built-in operation implemented in Rust.
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(rename_all = "snake_case", deny_unknown_fields)]
pub enum StepProgram {
    Command(String),
    /// `@orbit/ci-caches`: imports the Pint and PHPStan caches of the recorded code baseline.
    ImportCiCaches,
}

/// Select only the recorded baseline, or the checkout commit when it is on the default branch.
fn ci_cache_baseline(checkout: &Path) -> Option<String> {
    let repository = git2::Repository::open(checkout).ok()?;
    let head = repository.head().ok()?;
    let commit = head.peel_to_commit().ok()?.id();
    let default = repository
        .find_reference("refs/remotes/origin/HEAD")
        .ok()
        .and_then(|reference| reference.resolve().ok());
    let is_default = default
        .as_ref()
        .and_then(|reference| reference.name())
        .and_then(|name| name.strip_prefix("refs/remotes/origin/"))
        .is_some_and(|name| head.name() == Some(format!("refs/heads/{name}").as_str()));
    if is_default {
        return Some(commit.to_string());
    }
    if let Ok(baseline) = repository
        .revparse_single("refs/worktree/orbit/ci-baseline")
        .and_then(|object| object.peel_to_commit())
    {
        return Some(baseline.id().to_string());
    }
    let tip = default?.peel_to_commit().ok()?.id();
    (tip == commit || repository.graph_descendant_of(tip, commit).unwrap_or(false))
        .then(|| commit.to_string())
}

fn ci_caches(input: &LifecycleInput) -> Result<RunDeployStepOutput, DeployStepError> {
    let report = |exit_code, stream, value: &str| RunDeployStepOutput {
        exit_code,
        timed_out: false,
        output: vec![Chunk {
            stream,
            value: value.into(),
        }],
        truncated: false,
    };
    if !input.checkout.join("bin/ci-cache-ref").is_file() {
        return Ok(report(
            1,
            "stderr",
            "CI cache import tooling is missing from this checkout.\n",
        ));
    }
    let Some(commit) = ci_cache_baseline(&input.checkout) else {
        return Ok(report(
            0,
            "stdout",
            "CI caches cold: no recorded code baseline.\n",
        ));
    };
    run_program(&Program {
        user: None,
        directory: &input.checkout,
        command: StepCommand::Fixed {
            executable: "/usr/bin/python3",
            arguments: &[
                "bin/ci-cache-ref".as_ref(),
                "import".as_ref(),
                "--commit".as_ref(),
                commit.as_ref(),
            ],
        },
        secrets: &[],
        environment: &input.environment,
        timeout: Duration::from_secs(input.timeout_seconds),
    })
}

/// Runs one Project setup or teardown step with the lifecycle lock held and not inherited. Another step in the checkout refuses it at once with `instance.lifecycle_busy`.
pub fn lifecycle_step(input: &LifecycleInput) -> Result<RunDeployStepOutput, DevDeployError> {
    check(
        input.checkout.is_absolute() && canonical(&input.checkout),
        "the checkout is a canonical path",
    )?;
    let _lock = lifecycle_lock(&input.checkout)?;
    let StepProgram::Command(script) = &input.program else {
        return ci_caches(input).map_err(lifecycle_error);
    };
    let file = std::env::temp_dir().join(format!("orbit-lifecycle-{:016x}", rand::random::<u64>()));
    fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&file)
        .and_then(|mut handle| handle.write_all(script.as_bytes()))
        .map_err(|_| failed("write the step"))?;
    let result = run_program(&Program {
        user: None,
        directory: &input.checkout,
        command: StepCommand::Script(&file),
        secrets: &[],
        environment: &input.environment,
        timeout: Duration::from_secs(input.timeout_seconds),
    });
    let _ = fs::remove_file(&file);
    result.map_err(lifecycle_error)
}

fn lifecycle_error(error: DeployStepError) -> DevDeployError {
    match error {
        DeployStepError::StartFailed { reason } => DevDeployError::StepFailed { step: reason },
        DeployStepError::Invalid { check } => DevDeployError::Invalid { check },
    }
}

pub struct RunLifecycleStep;

impl Command for RunLifecycleStep {
    const NAME: &'static str = "instance.run-lifecycle-step";
    const SUMMARY: &'static str = "Run a Project setup or teardown step in an Instance checkout.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(3600);
    type Input = LifecycleInput;
    type Output = RunDeployStepOutput;
    type Error = DevDeployError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: LifecycleInput, _: &Context) -> Result<RunDeployStepOutput, DevDeployError> {
        lifecycle_step(&input)
    }
}

#[cfg(test)]
#[path = "dev_deploy_tests.rs"]
mod tests;

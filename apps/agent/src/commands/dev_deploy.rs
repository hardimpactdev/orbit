//! `instance.*-development-*` deploys: a development default deploys in its own checkout, as the managed account.
//! A setup or teardown step holds an exclusive `flock` on the checkout directory while it runs, so the checkout and
//! each step take the same lock without waiting. These replace the Bash of `DevelopmentCheckoutProgram`, check for
//! check.

use super::deploy_step::{
    run_program, with_live, Chunk, DeployStepError, Program, RunDeployStepOutput, StepCommand,
};
use super::git_read::GitRead;
use super::source::{
    canonical, exists, git, git_in, git_output, is_real_directory, is_symlink, origin_url,
};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    ffi::OsStr,
    fs,
    io::Write,
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
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

fn full_commit(commit: &str) -> bool {
    (commit.len() == 40 || commit.len() == 64)
        && commit
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}

/// The default Instance's checkout, its origin, and the Instance.
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Checkout {
    pub home: PathBuf,
    pub repository: String,
    pub instance: u64,
}

impl Checkout {
    /// A canonical repository root that this account owns, whose origin is the repository and that is no worktree.
    fn guard(&self) -> Result<(), DevDeployError> {
        let git_directory = self.home.join(".git");
        check(
            is_real_directory(&self.home) && canonical(&self.home),
            "the checkout is a canonical directory",
        )?;
        check(
            is_real_directory(&git_directory),
            "the checkout has its own Git directory",
        )?;
        let euid = rustix::process::geteuid().as_raw();
        check(
            [&self.home, &git_directory]
                .iter()
                .all(|path| fs::metadata(path).is_ok_and(|metadata| metadata.uid() == euid)),
            "this account owns the checkout",
        )?;
        check(
            origin_url(&self.home).as_deref() == Some(self.repository.as_str()),
            "the checkout's origin is the repository",
        )?;
        check(
            git_output(&self.home, &["rev-parse", "--git-common-dir"], true).as_deref()
                == Some(b".git".as_slice()),
            "the checkout is no worktree",
        )
    }

    /// The lifecycle lock, held until the value drops. Setup and teardown steps hold it while they run.
    fn lock(&self) -> Result<fs::File, DevDeployError> {
        lifecycle_lock(&self.home)
    }

    fn git(&self, arguments: &[&str]) -> Option<String> {
        let mut all = vec!["--literal-pathspecs"];
        all.extend(arguments);
        git_output(&self.home, &all, true)
            .map(|output| String::from_utf8_lossy(&output).into_owned())
    }
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

// ---- target ------------------------------------------------------------------------------------------------------

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TargetInput {
    #[serde(flatten)]
    pub checkout: Checkout,
    pub branch: String,
    /// A commit the branch must contain. Without one, the target is the branch's commit.
    #[serde(default)]
    pub commit: Option<String>,
    /// The transports the fetch may use; every other one is refused.
    pub protocols: Vec<String>,
    #[serde(default)]
    pub git_read: GitRead,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Target {
    pub commit: String,
}

/// Fetches the branch and names its commit, or the given commit once the fetched branch contains it.
pub fn target(input: &TargetInput) -> Result<Target, DevDeployError> {
    let checkout = &input.checkout;
    checkout.guard()?;
    let tracking = format!("refs/remotes/origin/{}", input.branch);
    let refspec = format!("+refs/heads/{}:{tracking}", input.branch);
    let options = input.git_read.fetch_options(&input.protocols);
    let mut arguments: Vec<&OsStr> = options.iter().map(OsStr::new).collect();
    arguments.extend(
        ["--literal-pathspecs", "-C"]
            .map(OsStr::new)
            .into_iter()
            .chain([checkout.home.as_os_str()])
            .chain(
                [
                    "fetch",
                    "--quiet",
                    "--no-tags",
                    "--",
                    &checkout.repository,
                    &refspec,
                ]
                .map(OsStr::new),
            ),
    );
    check(
        git(&arguments, &input.git_read, true).status.success(),
        "the branch can be fetched",
    )?;
    let Some(commit) = &input.commit else {
        let commit = checkout
            .git(&["rev-parse", "--verify", &format!("{tracking}^{{commit}}")])
            .ok_or_else(|| failed("read the branch"))?;
        check(full_commit(&commit), "the branch names a commit")?;
        return Ok(Target { commit });
    };
    check(full_commit(commit), "the commit is a full hash")?;
    checkout
        .git(&["merge-base", "--is-ancestor", commit, &tracking])
        .ok_or(DevDeployError::NotOnBranch {})?;
    Ok(Target {
        commit: commit.clone(),
    })
}

pub struct ResolveTarget;

impl Command for ResolveTarget {
    const NAME: &'static str = "instance.resolve-development-target";
    const SUMMARY: &'static str =
        "Fetch a development default's branch and name the commit a deploy checks out.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = TargetInput;
    type Output = Target;
    type Error = DevDeployError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn target(input: &TargetInput) -> Option<String> {
        Some(format!("source:{}", input.checkout.home.display()))
    }

    fn run(input: TargetInput, _: &Context) -> Result<Target, DevDeployError> {
        target(&input)
    }
}

// ---- checkout ----------------------------------------------------------------------------------------------------

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CheckoutInput {
    #[serde(flatten)]
    pub checkout: Checkout,
    pub branch: String,
    pub commit: String,
}

/// Checkout leaves untracked and ignored files in a directory that the commit removes, such as the dependencies of a
/// removed package. Removes them first, so a later copy of the checkout as a seed finds only the commit's directories.
fn drop_removed_directories(checkout: &Checkout, commit: &str) -> Result<(), DevDeployError> {
    let listed = git_in(
        &checkout.home,
        &["ls-files", "-z", "--others", "--directory"],
        true,
    );
    check(listed.status.success(), "the untracked files can be listed")?;
    for entry in listed
        .stdout
        .split(|byte| *byte == 0)
        .filter(|entry| !entry.is_empty())
    {
        let entry = String::from_utf8_lossy(entry);
        let entry = entry.trim_end_matches('/');
        let Some((parent, _)) = entry.rsplit_once('/') else {
            continue;
        };
        let tree = checkout
            .git(&["ls-tree", "-d", commit, "--", parent])
            .ok_or_else(|| failed("read the commit's tree"))?;
        if !tree.is_empty() {
            continue;
        }
        let path = checkout.home.join(entry);
        if is_real_directory(&path) {
            // `chmod -R u+w`: a read-only directory, such as one a package manager wrote, can be emptied.
            for directory in walkdir(&path) {
                if let Ok(metadata) = fs::symlink_metadata(&directory) {
                    let _ = fs::set_permissions(
                        &directory,
                        fs::Permissions::from_mode(metadata.mode() | 0o200),
                    );
                }
            }
            fs::remove_dir_all(&path)
                .map_err(|_| failed("remove a directory the commit removed"))?;
        } else if exists(&path) {
            fs::remove_file(&path).map_err(|_| failed("remove a file the commit removed"))?;
        }
    }
    Ok(())
}

/// The directories below a directory, links not followed.
fn walkdir(root: &Path) -> Vec<PathBuf> {
    let mut found = Vec::new();
    let mut pending = vec![root.to_path_buf()];
    while let Some(path) = pending.pop() {
        if is_real_directory(&path) {
            if let Ok(entries) = fs::read_dir(&path) {
                pending.extend(entries.flatten().map(|entry| entry.path()));
            }
            found.push(path);
        }
    }
    found
}

/// Checks out the commit on the branch in place. It refuses a checkout with tracked changes, and a local branch with
/// commits that the target does not contain, which `checkout -B` would drop.
pub fn checkout(input: &CheckoutInput) -> Result<Target, DevDeployError> {
    let checkout = &input.checkout;
    checkout.guard()?;
    check(full_commit(&input.commit), "the commit is a full hash")?;
    let _lock = checkout.lock()?;
    let changes = checkout
        .git(&["status", "--porcelain", "--untracked-files=no"])
        .ok_or_else(|| failed("read the checkout's status"))?;
    if !changes.is_empty() {
        return Err(DevDeployError::Dirty {
            changes: changes.lines().take(20).collect::<Vec<_>>().join("\n"),
        });
    }
    let local = format!("refs/heads/{}", input.branch);
    if checkout
        .git(&[
            "rev-parse",
            "--verify",
            "--quiet",
            &format!("{local}^{{commit}}"),
        ])
        .is_some()
        && checkout
            .git(&["merge-base", "--is-ancestor", &local, &input.commit])
            .is_none()
    {
        return Err(DevDeployError::Diverged {});
    }
    drop_removed_directories(checkout, &input.commit)?;
    checkout
        .git(&[
            "checkout",
            "--quiet",
            "--no-track",
            "-B",
            &input.branch,
            &input.commit,
        ])
        .ok_or_else(|| failed("check out the commit"))?;
    check(
        checkout
            .git(&["symbolic-ref", "--short", "HEAD"])
            .as_deref()
            == Some(input.branch.as_str()),
        "the checkout is on the branch",
    )?;
    let commit = checkout
        .git(&["rev-parse", "--verify", "HEAD"])
        .ok_or_else(|| failed("read the commit"))?;
    Ok(Target { commit })
}

pub struct CheckoutDefault;

impl Command for CheckoutDefault {
    const NAME: &'static str = "instance.checkout-development-default";
    const SUMMARY: &'static str = "Check out a commit on a development default's branch in place.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = CheckoutInput;
    type Output = Target;
    type Error = DevDeployError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn target(input: &CheckoutInput) -> Option<String> {
        Some(format!("source:{}", input.checkout.home.display()))
    }

    fn run(input: CheckoutInput, _: &Context) -> Result<Target, DevDeployError> {
        checkout(&input)
    }
}

// ---- a step ------------------------------------------------------------------------------------------------------

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct StepInput {
    #[serde(flatten)]
    pub checkout: Checkout,
    /// The step's program, which runs with `bash -eu` in the checkout.
    pub script: String,
    #[serde(default)]
    pub vp_environment: Option<super::vp_store::Environment>,
    pub step: String,
    /// The step's own bound. At its end the step's process group stops, and the step fails with its output.
    pub timeout_seconds: u64,
    #[serde(default)]
    pub operation: Option<u64>,
    #[serde(default)]
    pub secrets: Vec<String>,
}

/// Runs one Project step in the checkout, as this account, with the lifecycle lock held and not inherited, so a
/// process the step leaves behind cannot refuse the next step.
pub fn step(
    input: &StepInput,
    live: Option<tokio::sync::mpsc::UnboundedSender<serde_json::Value>>,
) -> Result<RunDeployStepOutput, DevDeployError> {
    let checkout = &input.checkout;
    checkout.guard()?;
    let _lock = checkout.lock()?;
    let vp = super::deploy_step::prepare_vp(input.vp_environment.as_ref()).map_err(|_| {
        DevDeployError::Invalid {
            check: "the Vite+ environment is installed in its owner's store".into(),
        }
    })?;
    let file = checkout.home.join(format!(
        ".git/orbit-deploy-step.{:016x}",
        rand::random::<u64>()
    ));
    fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&file)
        .and_then(|mut handle| handle.write_all(input.script.as_bytes()))
        .map_err(|_| failed("write the step"))?;
    let result = run_program(
        &Program {
            user: None,
            directory: &checkout.home,
            command: StepCommand::script(&file, vp.as_ref()),
            step: &input.step,
            secrets: &input.secrets,
            environment: &BTreeMap::new(),
            timeout: Duration::from_secs(input.timeout_seconds),
        },
        live,
    );
    let _ = fs::remove_file(&file);
    result.map_err(|error| match error {
        DeployStepError::StartFailed { reason } => DevDeployError::StepFailed { step: reason },
        DeployStepError::Invalid { check } => DevDeployError::Invalid { check },
    })
}

pub struct RunStep;

impl Command for RunStep {
    const NAME: &'static str = "instance.run-development-step";
    const SUMMARY: &'static str =
        "Run a development deploy step in a development default's checkout, live.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = StepInput;
    type Output = RunDeployStepOutput;
    type Error = DevDeployError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn target(input: &StepInput) -> Option<String> {
        Some(format!("source:{}", input.checkout.home.display()))
    }

    fn run(input: StepInput, _: &Context) -> Result<RunDeployStepOutput, DevDeployError> {
        with_live(input.operation, |live| step(&input, live))
    }
}

// ---- a setup or teardown step ------------------------------------------------------------------------------------

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LifecycleInput {
    /// Any Instance checkout of this account, by its canonical path.
    pub checkout: PathBuf,
    pub program: StepProgram,
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
    run_program(
        &Program {
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
            step: &input.step,
            secrets: &[],
            environment: &input.environment,
            timeout: Duration::from_secs(input.timeout_seconds),
        },
        None,
    )
}

/// Runs one Project setup or teardown step with the lifecycle lock held and not inherited, as `step` runs a deploy
/// step. Another step in the checkout refuses it at once with `instance.lifecycle_busy`.
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
    let result = run_program(
        &Program {
            user: None,
            directory: &input.checkout,
            command: StepCommand::Script(&file),
            step: &input.step,
            secrets: &[],
            environment: &input.environment,
            timeout: Duration::from_secs(input.timeout_seconds),
        },
        None,
    );
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

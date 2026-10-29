//! `instance.*-development-source`: create, inspect, and resolve the checkout of a development Instance.
//! These replace the Bash that `RemoteDevelopmentInstanceSourceLifecycle` sent: each check is the same, and every
//! Git command runs without hooks or fsmonitor. A fetch also pins each transport on the command line, so Git
//! configuration planted in the checkout cannot reach a command or another protocol.

use super::source::*;
use crate::command::{Command, Context, Platform, Privilege};
use crate::{command_errors, git_args};
use serde::{Deserialize, Serialize};
use std::{
    ffi::OsStr,
    fs,
    io::Write,
    os::unix::fs::OpenOptionsExt,
    path::{Path, PathBuf},
    time::Duration,
};

/// The file in the Git directory that ties a prepared checkout to the attempt that created it.
const PREPARE_RECEIPT: &str = "orbit-source-prepare";
command_errors! {
    pub enum DevSourceError {
        Invalid { check: String } => "instance.source_invalid", "The checkout is not the prepared source.";
        Busy {} => "instance.source_busy", "Another lifecycle operation holds the checkout.";
        GitFailed { step: String } => "instance.git_failed", "A Git step failed.";
    }
}

fn invalid(check: &str) -> impl FnOnce() -> DevSourceError + '_ {
    move || DevSourceError::Invalid {
        check: check.into(),
    }
}

fn check(condition: bool, what: &str) -> Result<(), DevSourceError> {
    if condition {
        Ok(())
    } else {
        Err(invalid(what)())
    }
}

fn git_failed(step: &str) -> DevSourceError {
    DevSourceError::GitFailed { step: step.into() }
}

/// What every command knows about the checkout it works on.
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Source {
    /// The Project repository, as the checkout's `origin` must name it.
    pub repository: String,
    pub checkout: PathBuf,
    /// The apps root that the checkout's parents must stay inside.
    pub root: PathBuf,
    pub user: String,
    pub group: String,
    /// The attempt that prepared the checkout, when it recorded one.
    #[serde(default)]
    pub prepare_id: Option<String>,
    /// The repository of a linked worktree's seed, when the checkout is one.
    #[serde(default)]
    pub seed_repository: Option<PathBuf>,
}

impl Source {
    fn account(&self) -> String {
        format!("{}:{}", self.user, self.group)
    }

    /// Every existing component of the checkout's parent is a real directory under the apps root.
    fn guard_parent_chain(&self) -> Result<(), DevSourceError> {
        let parent = dirname(&self.checkout);
        check(
            parent == self.root || starts_inside(&parent, &self.root),
            "the checkout is inside the apps root",
        )?;
        let mut current = PathBuf::from("/");
        for segment in parent.components().skip(1) {
            current.push(segment);
            if exists(&current) {
                check(
                    is_real_directory(&current) && canonical(&current),
                    "each parent is a real directory",
                )?;
            }
        }
        Ok(())
    }

    /// The checkout is the prepared repository: its own directories, owned by the account, the recorded layout, and
    /// with `verify_origin`, the Project origin. With a prepare ID, the receipt names this attempt and this directory.
    fn inspect(&self, verify_origin: bool) -> Result<PathBuf, DevSourceError> {
        let checkout = &self.checkout;
        let account = self.account();
        check(
            is_real_directory(checkout) && canonical(checkout),
            "the checkout is a real directory",
        )?;
        check(owner(checkout) == account, "the account owns the checkout")?;
        let dot_git = checkout.join(".git");
        check(!is_symlink(&dot_git), "the Git link is not a symlink")?;
        let top = git_output(checkout, &["rev-parse", "--show-toplevel"], true);
        check(
            top.as_deref() == Some(bytes(checkout)),
            "Git sees the checkout",
        )?;
        let git_directory = path_from(
            git_output(checkout, &["rev-parse", "--absolute-git-dir"], true)
                .ok_or_else(invalid("the Git directory"))?,
        );
        let common = path_from(
            git_output(
                checkout,
                &["rev-parse", "--path-format=absolute", "--git-common-dir"],
                true,
            )
            .ok_or_else(invalid("the common Git directory"))?,
        );
        for directory in [&git_directory, &common] {
            check(is_real_directory(directory), "the Git directories are real")?;
            check(
                owner(directory) == account,
                "the account owns the Git directories",
            )?;
        }
        let expected_common = self.seed_repository.as_ref().map(|seed| seed.join(".git"));
        if let Some(expected) = &expected_common {
            check(&common == expected, "the worktree belongs to its seed")?;
        }
        if dot_git.is_file() {
            check(
                expected_common.is_some()
                    && starts_inside(&git_directory, &common.join("worktrees")),
                "a linked worktree has a seed",
            )?;
            check(
                holds(&git_directory.join("gitdir"), bytes(&dot_git)),
                "the worktree points back at the checkout",
            )?;
        } else {
            check(
                git_directory == dot_git && common == dot_git,
                "the checkout owns its Git directory",
            )?;
        }
        if verify_origin {
            check(
                origin_url(checkout).as_deref() == Some(self.repository.as_str()),
                "the origin is the Project repository",
            )?;
        }
        if let Some(prepare_id) = &self.prepare_id {
            let receipt = git_directory.join(PREPARE_RECEIPT);
            check(
                is_regular_file(&receipt) && owner(&receipt) == account,
                "the prepare receipt",
            )?;
            check(
                holds(
                    &receipt,
                    format!("{prepare_id}:{}", identity(checkout)).as_bytes(),
                ),
                "the prepare receipt names this attempt",
            )?;
        }
        Ok(git_directory)
    }
}

/// An existing directory must be a real one; a missing one is created with mode 0755, parents included.
fn create_directory(path: &Path) -> Result<(), DevSourceError> {
    if exists(path) {
        return check(is_real_directory(path), "the directory is real");
    }
    std::os::unix::fs::DirBuilderExt::mode(fs::DirBuilder::new().recursive(true), 0o755)
        .create(path)
        .map_err(|_| git_failed("create the directory"))
}

fn run_git(arguments: &[&OsStr], environment: &GitRead, step: &str) -> Result<(), DevSourceError> {
    git(arguments, environment, false)
        .status
        .success()
        .then_some(())
        .ok_or_else(|| git_failed(step))
}

// ---- prepare ----------------------------------------------------------------------------------------------------

/// Creates the checkout of a development Instance: a clone, a linked worktree of its seed, or an empty repository
/// whose branches the Gateway sends to a task VM. An existing checkout must already be the prepared one.
pub struct PrepareDevelopmentSource;

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PrepareInput {
    #[serde(flatten)]
    pub source: Source,
    pub allow_existing: bool,
    #[serde(default)]
    pub seed_commit: Option<String>,
    /// Whether the Node may fetch the origin; a task VM may not.
    pub network: bool,
    #[serde(default)]
    pub git_read: GitRead,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct PrepareOutput {
    /// Whether this call created the checkout.
    pub created: bool,
}

impl Command for PrepareDevelopmentSource {
    const NAME: &'static str = "instance.prepare-development-source";
    const SUMMARY: &'static str =
        "Create a development Instance's checkout, or check the one an earlier attempt made.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = PrepareInput;
    type Output = PrepareOutput;
    type Error = DevSourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn target(input: &PrepareInput) -> Option<String> {
        Some(format!("source:{}", input.source.checkout.display()))
    }

    fn run(input: PrepareInput, _: &Context) -> Result<PrepareOutput, DevSourceError> {
        let source = &input.source;
        let checkout = &source.checkout;
        source.guard_parent_chain()?;
        create_directory(&source.root)?;
        create_directory(&dirname(checkout))?;
        if exists(checkout) {
            check(input.allow_existing, "an existing checkout is allowed")?;
            source.inspect(true)?;
            return Ok(PrepareOutput { created: false });
        }
        std::os::unix::fs::DirBuilderExt::mode(&mut fs::DirBuilder::new(), 0o755)
            .create(checkout)
            .map_err(|_| git_failed("create the checkout"))?;
        let none = GitRead::default();
        match (&source.seed_repository, &input.seed_commit) {
            (Some(seed), Some(commit)) => {
                check(!is_symlink(seed), "the seed is not a symlink")?;
                check(
                    origin_url(seed).as_deref() == Some(source.repository.as_str()),
                    "the seed's origin is the Project repository",
                )?;
                run_git(
                    git_args![
                        "-C",
                        seed,
                        "worktree",
                        "add",
                        "--no-checkout",
                        "--detach",
                        "--",
                        checkout,
                        commit
                    ],
                    &none,
                    "add the worktree",
                )?;
            }
            (Some(_), None) | (None, Some(_)) => {
                return Err(invalid("a seed has both its repository and commit")())
            }
            (None, None) if input.network => run_git(
                git_args![
                    "clone",
                    "--no-checkout",
                    "--origin",
                    "origin",
                    "--",
                    &source.repository,
                    checkout
                ],
                &input.git_read,
                "clone the origin",
            )?,
            (None, None) => {
                // A task VM gets no token. Resolve sends its branches from the Gateway's mirror.
                run_git(
                    git_args!["init", "--quiet", "--", checkout],
                    &none,
                    "create the repository",
                )?;
                run_git(
                    git_args![
                        "-C",
                        checkout,
                        "remote",
                        "add",
                        "origin",
                        &source.repository
                    ],
                    &none,
                    "name the origin",
                )?;
            }
        }
        if let Some(prepare_id) = &source.prepare_id {
            let git_directory = path_from(
                git_output(checkout, &["rev-parse", "--absolute-git-dir"], true)
                    .ok_or_else(|| git_failed("find the Git directory"))?,
            );
            let mut receipt = fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .open(git_directory.join(PREPARE_RECEIPT))
                .map_err(|_| git_failed("write the prepare receipt"))?;
            writeln!(receipt, "{prepare_id}:{}", identity(checkout))
                .map_err(|_| git_failed("write the prepare receipt"))?;
        }
        source.inspect(true)?;
        Ok(PrepareOutput { created: true })
    }
}

// ---- inspect ----------------------------------------------------------------------------------------------------

/// Reads a prepared checkout: its origin, the branch at `HEAD`, and the commit there.
pub struct InspectDevelopmentSource;

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectInput {
    #[serde(flatten)]
    pub source: Source,
    /// Whether the origin must be the Project repository. A rename reads the origin to check its identity instead.
    pub verify_origin: bool,
    /// Refuse with `instance.source_busy` while another lifecycle operation holds the checkout.
    #[serde(default)]
    pub require_idle: bool,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct InspectOutput {
    pub origin: String,
    /// The branch at `HEAD`, or `None` when it is detached.
    pub branch: Option<String>,
    /// The commit at `HEAD`, or `None` before the first one.
    pub commit: Option<String>,
}

impl Command for InspectDevelopmentSource {
    const NAME: &'static str = "instance.inspect-development-source";
    const SUMMARY: &'static str =
        "Check a development Instance's prepared checkout and read its origin, branch, and commit.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = InspectInput;
    type Output = InspectOutput;
    type Error = DevSourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: InspectInput, _: &Context) -> Result<InspectOutput, DevSourceError> {
        let source = &input.source;
        source.guard_parent_chain()?;
        source.inspect(input.verify_origin)?;
        if input.require_idle {
            let directory =
                fs::File::open(&source.checkout).map_err(|_| invalid("the checkout opens")())?;
            rustix::fs::flock(
                &directory,
                rustix::fs::FlockOperation::NonBlockingLockExclusive,
            )
            .map_err(|_| DevSourceError::Busy {})?;
        }
        let text = |output: Option<Vec<u8>>| {
            output.map(|value| String::from_utf8_lossy(&value).into_owned())
        };
        Ok(InspectOutput {
            origin: origin_url(&source.checkout)
                .ok_or_else(invalid("the checkout has an origin"))?,
            branch: text(git_output(
                &source.checkout,
                &["symbolic-ref", "--quiet", "--short", "HEAD"],
                true,
            ))
            .filter(|branch| !branch.is_empty()),
            commit: text(git_output(
                &source.checkout,
                &["rev-parse", "--verify", "--quiet", "HEAD^{commit}"],
                true,
            )),
        })
    }
}

// ---- resolve ----------------------------------------------------------------------------------------------------

/// Fetches the origin, when the Node may, and checks out the Instance's branch from the first source that has it:
/// the seed commit, the default branch for the default Instance, the origin's branch, a local branch, and last the
/// origin's default branch.
pub struct ResolveDevelopmentSource;

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ResolveInput {
    #[serde(flatten)]
    pub source: Source,
    pub instance_name: String,
    pub default_branch: String,
    #[serde(default)]
    pub branch_override: Option<String>,
    #[serde(default)]
    pub seed_commit: Option<String>,
    pub network: bool,
    /// The transports the fetch may use; every other one is refused.
    pub protocols: Vec<String>,
    #[serde(default)]
    pub git_read: GitRead,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct ResolveOutput {
    pub branch: String,
    pub commit: String,
}

impl Command for ResolveDevelopmentSource {
    const NAME: &'static str = "instance.resolve-development-source";
    const SUMMARY: &'static str = "Fetch a development Instance's origin and check out its branch.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = ResolveInput;
    type Output = ResolveOutput;
    type Error = DevSourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn target(input: &ResolveInput) -> Option<String> {
        Some(format!("source:{}", input.source.checkout.display()))
    }

    fn run(input: ResolveInput, _: &Context) -> Result<ResolveOutput, DevSourceError> {
        let source = &input.source;
        let checkout = &source.checkout;
        source.guard_parent_chain()?;
        source.inspect(true)?;
        if input.network {
            let options = input.git_read.fetch_options(&input.protocols);
            let mut arguments: Vec<&OsStr> = options.iter().map(OsStr::new).collect();
            arguments.extend(git_args![
                "-C",
                checkout,
                "fetch",
                "--quiet",
                "--no-tags",
                "--prune",
                "--",
                &source.repository,
                "+refs/heads/*:refs/remotes/origin/*"
            ]);
            run_git(&arguments, &input.git_read, "fetch the origin")?;
        }
        let has = |reference: &str| {
            git_in(
                checkout,
                &["show-ref", "--verify", "--quiet", reference],
                true,
            )
            .status
            .success()
        };
        let origin = |branch: &str| format!("refs/remotes/origin/{branch}");
        let mut branch = input
            .branch_override
            .clone()
            .unwrap_or_else(|| input.instance_name.clone());
        let reference = if let Some(commit) = &input.seed_commit {
            commit.clone()
        } else if input.instance_name == "default" && input.branch_override.is_none() {
            branch = input.default_branch.clone();
            origin(&branch)
        } else if has(&origin(&branch)) {
            origin(&branch)
        } else if has(&format!("refs/heads/{branch}")) {
            format!("refs/heads/{branch}")
        } else {
            origin(&input.default_branch)
        };
        if input.seed_commit.is_none() {
            require(
                has(&reference),
                || git_failed("find the source branch"),
                "the source branch exists",
            )?;
        }
        let current = git_output(
            checkout,
            &["symbolic-ref", "--quiet", "--short", "HEAD"],
            true,
        );
        let worktrees = git_in(checkout, &["worktree", "list", "--porcelain", "-z"], true);
        require(
            worktrees.status.success(),
            || git_failed("read the worktree branches"),
            "the worktree inventory is readable",
        )?;
        let occupied = |branch: &str| {
            current.as_deref() != Some(branch.as_bytes())
                && worktrees
                    .stdout
                    .split(|byte| *byte == 0)
                    .any(|field| field == format!("branch refs/heads/{branch}").as_bytes())
        };
        if occupied(&branch) {
            let attempt = source
                .prepare_id
                .as_deref()
                .filter(|attempt| !attempt.is_empty())
                .ok_or_else(|| git_failed("select a private worktree branch"))?;
            branch = format!("orbit/instance/{attempt}");
            check(
                !occupied(&branch),
                "the private branch is not checked out elsewhere",
            )?;
        }
        run_git(
            git_args![
                "-C",
                checkout,
                "checkout",
                "--quiet",
                "--force",
                "--no-track",
                "-B",
                &branch,
                &reference
            ],
            &GitRead::default(),
            "check out the branch",
        )?;
        let head = git_output(checkout, &["symbolic-ref", "--short", "HEAD"], true);
        require(
            head.as_deref() == Some(branch.as_bytes()),
            || git_failed("check out the branch"),
            "HEAD is the branch",
        )?;
        let commit = git_output(checkout, &["rev-parse", "--verify", "HEAD^{commit}"], true)
            .ok_or_else(|| git_failed("read the commit"))?;
        Ok(ResolveOutput {
            branch,
            commit: String::from_utf8_lossy(&commit).into_owned(),
        })
    }
}

#[cfg(test)]
#[path = "dev_source_tests.rs"]
mod tests;

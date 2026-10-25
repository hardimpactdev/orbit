//! `instances.inspect-clone-candidate`: checks that a candidate Instance's selected source is a clean Git checkout on
//! its configured branch, and that the repository still holds its commit and the target branch. Git runs as the
//! Instance user without hooks; the repository is fetched into a private scratch directory that the command removes.

use super::git_read::GitRead;
use super::source::{realpath, substitution};
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    ffi::{OsStr, OsString},
    fs,
    os::unix::ffi::OsStringExt,
    path::{Path, PathBuf},
    process::{Command as Process, Output, Stdio},
    time::Duration,
};

command_errors! {
    pub enum CandidateError {
        ReleaseMissing {} => "instances.clone_candidate_release_missing", "The production candidate has no selected release.";
        SourceInvalid {} => "instances.clone_candidate_source_invalid", "The candidate source is not a Git checkout.";
        ReleaseChanged {} => "instances.clone_candidate_release_changed", "The candidate selected another source meanwhile.";
        BranchInvalid {} => "instances.clone_candidate_branch_invalid", "The candidate is not on its configured branch.";
        Dirty {} => "instances.clone_candidate_dirty", "The candidate source has changes.";
        RepositoryUnavailable { stderr: String } => "instances.clone_candidate_repository_unavailable", "The repository cannot be fetched.";
        CommitUnavailable {} => "instances.clone_candidate_commit_unavailable", "The repository does not hold the candidate commit.";
        TargetBranchMissing {} => "instances.clone_target_branch_missing", "The repository has no target branch.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CandidateInput {
    /// `development` reads the checkout at `base`; `production` reads the release that `base/current` selects.
    pub environment: String,
    pub base: PathBuf,
    /// The Instance user, who owns the source and runs Git.
    pub user: String,
    pub repository: String,
    pub configured_branch: String,
    pub target_branch: String,
    /// The source the Gateway recorded; another one refuses.
    pub expected_source: PathBuf,
    /// The GitHub credential; only the fetch sees it.
    pub git_read: GitRead,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Candidate {
    pub source: PathBuf,
    pub commit: String,
}

/// Runs Git as `user` from `/`, without hooks or fsmonitor, with standard input closed.
fn git(user: &str, arguments: &[&OsStr], environment: &GitRead) -> Option<Output> {
    let mut command = Process::new("git");
    environment.configure(&mut command);
    command
        .args(arguments)
        .current_dir("/")
        .stdin(Stdio::null());
    identity::drop_to(&mut command, user).ok()?;
    command.output().ok()
}

/// Git's output in `directory` when it succeeds.
fn read(user: &str, directory: &Path, arguments: &[&str]) -> Option<Vec<u8>> {
    read_raw(user, directory, arguments).map(|bytes| substitution(&bytes))
}

/// Preserve NUL delimiters and path bytes in Git's machine-readable output.
fn read_raw(user: &str, directory: &Path, arguments: &[&str]) -> Option<Vec<u8>> {
    let mut all: Vec<&OsStr> = vec!["-C".as_ref(), directory.as_os_str()];
    all.extend(arguments.iter().map(OsStr::new));
    git(user, &all, &GitRead::default())
        .filter(|output| output.status.success())
        .map(|output| output.stdout)
}

fn source(input: &CandidateInput) -> Result<PathBuf, CandidateError> {
    match input.environment.as_str() {
        "development" => Ok(input.base.clone()),
        "production" => {
            let current = input.base.join("current");
            if !fs::symlink_metadata(&current).is_ok_and(|metadata| metadata.is_symlink()) {
                return Err(CandidateError::ReleaseMissing {});
            }
            let source = realpath(&current).ok_or(CandidateError::ReleaseMissing {})?;
            if !source.starts_with(input.base.join("releases"))
                || source == input.base.join("releases")
            {
                return Err(CandidateError::SourceInvalid {});
            }
            Ok(source)
        }
        _ => Err(CandidateError::SourceInvalid {}),
    }
}

/// The checks of the source itself: a real Git top level on its branch, clean, with clean submodules.
fn checkout(input: &CandidateInput, source: &Path) -> Result<(PathBuf, String), CandidateError> {
    let user = input.user.as_str();
    if !fs::symlink_metadata(source).is_ok_and(|metadata| metadata.is_dir()) {
        return Err(CandidateError::SourceInvalid {});
    }
    let top = read(user, source, &["rev-parse", "--show-toplevel"])
        .ok_or(CandidateError::SourceInvalid {})?;
    let real = realpath(source).ok_or(CandidateError::SourceInvalid {})?;
    if top != real.as_os_str().as_encoded_bytes() {
        return Err(CandidateError::SourceInvalid {});
    }
    match read(
        user,
        source,
        &["symbolic-ref", "--quiet", "--short", "HEAD"],
    ) {
        Some(branch) if branch != input.configured_branch.as_bytes() => {
            return Err(CandidateError::BranchInvalid {})
        }
        None if input.environment == "development" => return Err(CandidateError::BranchInvalid {}),
        _ => {}
    }
    let commit = read(user, source, &["rev-parse", "--verify", "HEAD^{commit}"])
        .and_then(|commit| String::from_utf8(commit).ok())
        .filter(|commit| {
            commit.len() >= 4
                && commit
                    .bytes()
                    .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
        })
        .ok_or(CandidateError::SourceInvalid {})?;
    clean_tree(user, &real)?;
    Ok((real, commit))
}

/// Walk Git links from each index without invoking a shell or interpreting path bytes as lines.
fn clean_tree(user: &str, root: &Path) -> Result<(), CandidateError> {
    let mut pending = vec![root.to_path_buf()];
    while let Some(directory) = pending.pop() {
        let status = read_raw(
            user,
            &directory,
            &[
                "status",
                "--porcelain=v1",
                "-z",
                "--untracked-files=all",
                "--ignore-submodules=none",
            ],
        )
        .ok_or(CandidateError::Dirty {})?;
        if !status.is_empty() {
            return Err(CandidateError::Dirty {});
        }
        let index = read_raw(user, &directory, &["ls-files", "--stage", "-z"])
            .ok_or(CandidateError::Dirty {})?;
        for entry in index
            .split(|byte| *byte == 0)
            .filter(|entry| !entry.is_empty())
        {
            let separator = entry
                .iter()
                .position(|byte| *byte == b'\t')
                .ok_or(CandidateError::Dirty {})?;
            let (header, path) = (&entry[..separator], &entry[separator + 1..]);
            let fields: Vec<_> = header.split(|byte| *byte == b' ').collect();
            if fields.len() != 3 || fields[2] != b"0" {
                return Err(CandidateError::Dirty {});
            }
            if fields[0] != b"160000" {
                continue;
            }
            let relative = PathBuf::from(OsString::from_vec(path.to_vec()));
            if relative.as_os_str().is_empty()
                || relative
                    .components()
                    .any(|part| !matches!(part, std::path::Component::Normal(_)))
            {
                return Err(CandidateError::Dirty {});
            }
            let child = directory.join(relative);
            // A Git link must lead to its own initialized checkout within this source tree.
            // Reject linked paths rather than traversing another checkout or a cycle.
            if realpath(&child).as_ref() != Some(&child)
                || !child.join(".git").exists()
                || read_raw(user, &child, &["rev-parse", "--show-toplevel"])
                    .as_deref()
                    .and_then(|bytes| bytes.strip_suffix(b"\n"))
                    != Some(child.as_os_str().as_encoded_bytes())
                || read(user, &child, &["rev-parse", "--verify", "HEAD^{commit}"]).as_deref()
                    != Some(fields[1])
            {
                return Err(CandidateError::Dirty {});
            }
            pending.push(child);
        }
    }
    Ok(())
}

/// Fetches the repository into a private scratch directory and checks it holds the commit and the target branch.
fn repository(input: &CandidateInput, commit: &str) -> Result<(), CandidateError> {
    let user = input.user.as_str();
    let mut command = Process::new("mktemp");
    command.arg("-d").stdin(Stdio::null()).stderr(Stdio::null());
    let unavailable = || CandidateError::RepositoryUnavailable {
        stderr: String::new(),
    };
    identity::drop_to(&mut command, user).map_err(|_| unavailable())?;
    let scratch = command
        .output()
        .ok()
        .filter(|output| output.status.success())
        .map(|output| {
            PathBuf::from(String::from_utf8_lossy(&substitution(&output.stdout)).into_owned())
        })
        .ok_or_else(unavailable)?;
    let result = (|| {
        let bare = scratch.join("repository.git");
        let succeeded =
            |output: Option<Output>| output.is_some_and(|output| output.status.success());
        if !succeeded(git(
            user,
            &[
                "init".as_ref(),
                "--quiet".as_ref(),
                "--bare".as_ref(),
                bare.as_os_str(),
            ],
            &GitRead::default(),
        )) {
            return Err(unavailable());
        }
        let git_dir = format!("--git-dir={}", bare.display());
        let fetch = git(
            user,
            &[
                git_dir.as_ref(),
                "fetch".as_ref(),
                "--quiet".as_ref(),
                "--no-tags".as_ref(),
                "--prune".as_ref(),
                input.repository.as_ref(),
                "+refs/heads/*:refs/remotes/origin/*".as_ref(),
            ],
            &input.git_read,
        )
        .ok_or_else(unavailable)?;
        if !fetch.status.success() {
            let stderr = String::from_utf8_lossy(&fetch.stderr);
            let tail: String = stderr
                .trim()
                .chars()
                .rev()
                .take(1000)
                .collect::<Vec<_>>()
                .into_iter()
                .rev()
                .collect();
            return Err(CandidateError::RepositoryUnavailable { stderr: tail });
        }
        let object = format!("{commit}^{{commit}}");
        if !succeeded(git(
            user,
            &[
                git_dir.as_ref(),
                "cat-file".as_ref(),
                "-e".as_ref(),
                object.as_ref(),
            ],
            &GitRead::default(),
        )) {
            return Err(CandidateError::CommitUnavailable {});
        }
        let target = format!("refs/remotes/origin/{}", input.target_branch);
        if !succeeded(git(
            user,
            &[
                git_dir.as_ref(),
                "show-ref".as_ref(),
                "--verify".as_ref(),
                "--quiet".as_ref(),
                target.as_ref(),
            ],
            &GitRead::default(),
        )) {
            return Err(CandidateError::TargetBranchMissing {});
        }
        Ok(())
    })();
    let mut remove = Process::new("rm");
    remove
        .args(["-rf", "--"])
        .arg(&scratch)
        .stdin(Stdio::null());
    if identity::drop_to(&mut remove, user).is_ok() {
        let _ = remove.output();
    }
    result
}

pub fn inspect(input: &CandidateInput) -> Result<Candidate, CandidateError> {
    let source = source(input)?;
    if source != input.expected_source {
        return Err(CandidateError::ReleaseChanged {});
    }
    let (source, commit) = checkout(input, &source)?;
    repository(input, &commit)?;
    Ok(Candidate { source, commit })
}

pub struct InspectCloneCandidate;

impl Command for InspectCloneCandidate {
    const NAME: &'static str = "instances.inspect-clone-candidate";
    const SUMMARY: &'static str =
        "Check that a clone candidate's source is clean and its repository holds its commit.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = CandidateInput;
    type Output = Candidate;
    type Error = CandidateError;

    /// Git runs as the Instance user, which a production candidate does not share with the managed account.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: CandidateInput, _: &Context) -> Result<Candidate, CandidateError> {
        inspect(&input)
    }
}

#[cfg(test)]
#[path = "clone_candidate_tests.rs"]
mod tests;

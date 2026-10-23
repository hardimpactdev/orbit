//! `projects.update-source`: the checkout steps of a Project update, as the managed account. Before a repository
//! change it checks each checkout's origin and, outside a task VM, that the proposed repository answers; it then
//! changes each origin, or restores it. A default branch change fetches and checks the branch, switches to it, or
//! restores the previous one. Git never runs hooks or fsmonitor.

use super::dev_source::fetch_options;
use super::source::git;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{collections::BTreeMap, ffi::OsStr, path::Path, path::PathBuf, time::Duration};

command_errors! {
    pub enum ProjectSourceError {
        Failed { step: String } => "projects.source_failed", "A checkout step of the Project update failed.";
    }
}

fn failed(step: &str) -> ProjectSourceError {
    ProjectSourceError::Failed { step: step.into() }
}

#[derive(Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum ProjectSourceInput {
    /// The checkout is a plain Git checkout at `current`; with `git_read`, the proposed repository answers.
    PreflightRepository {
        path: PathBuf,
        current: String,
        proposed: String,
        git_read: Option<BTreeMap<String, String>>,
    },
    SetOrigin {
        path: PathBuf,
        url: String,
    },
    /// Writes the origin only when it differs, so a read-only checkout never blocks a rollback.
    RestoreOrigin {
        path: PathBuf,
        url: String,
    },
    PreflightBranch {
        path: PathBuf,
        branch: String,
        repository: String,
        protocols: Vec<String>,
        git_read: BTreeMap<String, String>,
    },
    SwitchBranch {
        path: PathBuf,
        branch: String,
    },
    RestoreBranch {
        path: PathBuf,
        branch: String,
    },
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Done {}

/// Git in `path` without a credential environment.
fn git_in(path: &Path, arguments: &[&str]) -> Option<String> {
    let mut all: Vec<&OsStr> = vec!["-C".as_ref(), path.as_os_str()];
    all.extend(arguments.iter().map(OsStr::new));
    let output = git(&all, &BTreeMap::new(), true);
    output.status.success().then(|| {
        String::from_utf8_lossy(&output.stdout)
            .trim_end()
            .to_string()
    })
}

fn origin(path: &Path) -> Option<String> {
    git_in(path, &["config", "--get", "remote.origin.url"])
}

/// A checkout with its own `.git` directory, not a linked worktree.
fn plain_checkout(path: &Path) -> bool {
    path.is_dir() && path.join(".git").is_dir()
}

fn require(condition: bool, step: &str) -> Result<(), ProjectSourceError> {
    if condition {
        Ok(())
    } else {
        Err(failed(step))
    }
}

pub fn update(input: &ProjectSourceInput) -> Result<Done, ProjectSourceError> {
    match input {
        ProjectSourceInput::PreflightRepository {
            path,
            current,
            proposed,
            git_read,
        } => {
            let step = "app-update-repository-preflight";
            require(plain_checkout(path), step)?;
            require(origin(path).as_deref() == Some(current.as_str()), step)?;
            require(
                git_in(path, &["rev-parse", "--verify", "--quiet", "HEAD"]).is_some(),
                step,
            )?;
            if let Some(environment) = git_read {
                // Outside the checkout, so its config cannot shape a credentialed call.
                let words = [
                    "-c",
                    "credential.helper=",
                    "-c",
                    "http.followRedirects=false",
                    "-C",
                    "/",
                    "ls-remote",
                    "--heads",
                    "--",
                    proposed.as_str(),
                ];
                let arguments: Vec<&OsStr> = words.iter().map(OsStr::new).collect();
                require(git(&arguments, environment, true).status.success(), step)?;
            }
        }
        ProjectSourceInput::SetOrigin { path, url } => {
            let step = "app-update-repository-origin";
            require(plain_checkout(path), step)?;
            require(
                git_in(path, &["remote", "set-url", "origin", "--", url]).is_some(),
                step,
            )?;
            require(origin(path).as_deref() == Some(url.as_str()), step)?;
        }
        ProjectSourceInput::RestoreOrigin { path, url } => {
            if origin(path).as_deref() != Some(url.as_str()) {
                require(
                    git_in(path, &["remote", "set-url", "origin", "--", url]).is_some(),
                    "app-update-repository-restore",
                )?;
            }
        }
        ProjectSourceInput::PreflightBranch {
            path,
            branch,
            repository,
            protocols,
            git_read,
        } => {
            let step = "app-update-default-branch-preflight";
            require(path.is_dir(), step)?;
            require(
                git_in(path, &["rev-parse", "--is-inside-work-tree"]).is_some(),
                step,
            )?;
            let options = fetch_options(protocols);
            let mut arguments: Vec<&OsStr> = options.iter().map(OsStr::new).collect();
            arguments.extend(["-C".as_ref(), path.as_os_str()]);
            arguments.extend(
                [
                    "fetch",
                    "--quiet",
                    "--no-tags",
                    "--prune",
                    "--",
                    repository,
                    "+refs/heads/*:refs/remotes/origin/*",
                ]
                .map(OsStr::new),
            );
            require(git(&arguments, git_read, true).status.success(), step)?;
            let tracking = format!("refs/remotes/origin/{branch}");
            require(
                git_in(path, &["show-ref", "--verify", "--quiet", &tracking]).is_some(),
                step,
            )?;
        }
        ProjectSourceInput::SwitchBranch { path, branch } => {
            let step = "app-update-default-branch-switch";
            let upstream = format!("origin/{branch}");
            require(
                git_in(path, &["checkout", "--no-track", "-B", branch, &upstream]).is_some(),
                step,
            )?;
            require(
                git_in(
                    path,
                    &["config", &format!("branch.{branch}.remote"), "origin"],
                )
                .is_some(),
                step,
            )?;
            require(
                git_in(
                    path,
                    &[
                        "config",
                        &format!("branch.{branch}.merge"),
                        &format!("refs/heads/{branch}"),
                    ],
                )
                .is_some(),
                step,
            )?;
        }
        ProjectSourceInput::RestoreBranch { path, branch } => {
            require(
                git_in(path, &["checkout", branch, "--"]).is_some(),
                "app-update-default-branch-restore",
            )?;
        }
    }
    Ok(Done {})
}

pub struct UpdateProjectSource;

impl Command for UpdateProjectSource {
    const NAME: &'static str = "projects.update-source";
    const SUMMARY: &'static str =
        "Check, change, or restore a development checkout's origin or default branch.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = ProjectSourceInput;
    type Output = Done;
    type Error = ProjectSourceError;

    /// The managed account owns every development checkout.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: ProjectSourceInput, _: &Context) -> Result<Done, ProjectSourceError> {
        update(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{fs, process::Command as Process};

    fn sh(directory: &Path, arguments: &[&str]) {
        let status = Process::new("git")
            .args([
                "-c",
                "user.name=Orbit",
                "-c",
                "user.email=orbit@example.test",
                "-c",
                "init.defaultBranch=main",
            ])
            .args(arguments)
            .current_dir(directory)
            .status()
            .unwrap();
        assert!(status.success(), "git {arguments:?}");
    }

    #[test]
    fn an_origin_change_checks_the_checkout_and_restores_only_a_changed_origin() {
        let root = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!(
                "orbit-project-source-{:016x}",
                rand::random::<u64>()
            ));
        let checkout = root.join("checkout");
        fs::create_dir_all(&checkout).unwrap();
        sh(&checkout, &["init", "--quiet"]);
        sh(
            &checkout,
            &["commit", "--quiet", "--allow-empty", "-m", "one"],
        );
        sh(
            &checkout,
            &["remote", "add", "origin", "https://example.test/old.git"],
        );
        let preflight = |current: &str| ProjectSourceInput::PreflightRepository {
            path: checkout.clone(),
            current: current.into(),
            proposed: "https://example.test/new.git".into(),
            git_read: None,
        };

        assert_eq!(
            update(&preflight("https://example.test/old.git")),
            Ok(Done {})
        );
        assert_eq!(
            update(&preflight("https://example.test/other.git")),
            Err(failed("app-update-repository-preflight"))
        );
        update(&ProjectSourceInput::SetOrigin {
            path: checkout.clone(),
            url: "https://example.test/new.git".into(),
        })
        .unwrap();
        assert_eq!(
            origin(&checkout).as_deref(),
            Some("https://example.test/new.git")
        );
        update(&ProjectSourceInput::RestoreOrigin {
            path: checkout.clone(),
            url: "https://example.test/old.git".into(),
        })
        .unwrap();
        assert_eq!(
            origin(&checkout).as_deref(),
            Some("https://example.test/old.git")
        );
        let _ = fs::remove_dir_all(&root);
    }

    #[test]
    fn a_branch_switch_fetches_checks_switches_and_restores() {
        let root = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!(
                "orbit-project-branch-{:016x}",
                rand::random::<u64>()
            ));
        let work = root.join("work");
        fs::create_dir_all(&work).unwrap();
        sh(&work, &["init", "--quiet"]);
        sh(&work, &["commit", "--quiet", "--allow-empty", "-m", "one"]);
        sh(&work, &["branch", "release"]);
        sh(&root, &["clone", "--quiet", "--bare", "work", "origin.git"]);
        sh(&root, &["clone", "--quiet", "origin.git", "checkout"]);
        let checkout = root.join("checkout");
        let repository = root.join("origin.git").display().to_string();
        let preflight = |branch: &str| ProjectSourceInput::PreflightBranch {
            path: checkout.clone(),
            branch: branch.into(),
            repository: repository.clone(),
            protocols: vec!["file".into()],
            git_read: BTreeMap::new(),
        };

        assert_eq!(update(&preflight("release")), Ok(Done {}));
        assert_eq!(
            update(&preflight("missing")),
            Err(failed("app-update-default-branch-preflight"))
        );
        update(&ProjectSourceInput::SwitchBranch {
            path: checkout.clone(),
            branch: "release".into(),
        })
        .unwrap();
        assert_eq!(
            git_in(&checkout, &["symbolic-ref", "--short", "HEAD"]).as_deref(),
            Some("release")
        );
        update(&ProjectSourceInput::RestoreBranch {
            path: checkout.clone(),
            branch: "main".into(),
        })
        .unwrap();
        assert_eq!(
            git_in(&checkout, &["symbolic-ref", "--short", "HEAD"]).as_deref(),
            Some("main")
        );
        let _ = fs::remove_dir_all(&root);
    }
}

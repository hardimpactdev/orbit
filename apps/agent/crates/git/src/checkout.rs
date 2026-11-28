//! `git.checkout`: the branch of one work tree, as desired state.

use std::path::Path;

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{
    branch, commit, full_ref, invalid, parse, path, require_repository, resolve, Failure,
    NodeSystem, LOCAL_SECONDS,
};

const CODE: &str = "git.checkout_failed";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct CheckoutInput {
    worktree: String,
    /// The branch the work tree must be on.
    branch: String,
    /// Where a missing branch starts: a full object id or a full ref name.
    #[serde(default)]
    start: Option<String>,
    /// A branch the work tree may be on instead: it takes the new name when the new branch does not exist yet.
    #[serde(default)]
    rename_from: Option<String>,
    /// The commit the branch and every tracked file must hold; it discards local changes, so it needs `destructive`.
    #[serde(default)]
    reset: Option<String>,
    /// Removes the files Git does not track (ignored ones stay); it needs `destructive`.
    #[serde(default)]
    clean: bool,
    /// Allows `reset` and `clean`, which discard work inside this work tree.
    #[serde(default)]
    destructive: bool,
}

/// What a checkout answers: the branch, its commit and the changes made.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct CheckedOut {
    /// The branch the work tree is on.
    pub branch: String,
    /// The commit `HEAD` names afterwards.
    pub head: String,
    /// The steps that changed something: switched or renamed, reset, cleaned. 0 on a repeat.
    pub changes: u32,
}

/// Puts one work tree on its branch: renamed from `rename_from`, switched to an existing branch, or made at `start`. Then,
/// when asked and allowed, resets it hard to `reset` and removes untracked files. Each step runs only when the work tree
/// differs, and the result is read back.
///
/// # Errors
///
/// `git.input_invalid`, `git.destructive_not_allowed` or `git.checkout_failed`.
#[expect(
    clippy::too_many_lines,
    reason = "one desired state read, changed and read back in order: branch, reset, clean"
)]
pub fn checkout(system: &NodeSystem, input: &Value) -> Result<CheckedOut, Failure> {
    let input: CheckoutInput = parse(input)?;
    let tree = path(&input.worktree, "worktree")?;
    let wanted = branch(&input.branch)?;
    let start = input
        .start
        .as_deref()
        .map(|start| {
            if start.starts_with("refs/") {
                full_ref(start)
            } else {
                commit(start)
            }
        })
        .transpose()?;
    let rename_from = input.rename_from.as_deref().map(branch).transpose()?;
    let reset = input.reset.as_deref().map(commit).transpose()?;
    if (reset.is_some() || input.clean) && !input.destructive {
        return Err(Failure::new(
            "git.destructive_not_allowed",
            "reset and clean need destructive",
        ));
    }
    if rename_from == Some(wanted) {
        return Err(invalid("rename_from is the branch itself"));
    }
    require_repository(system, CODE, &tree, crate::Kind::WorkTree)?;
    let mut changes = 0;
    let current = current_branch(system, &tree)?;
    if current.as_deref() != Some(wanted) {
        let exists = resolve(system, CODE, &tree, &format!("refs/heads/{wanted}"))?.is_some();
        if !exists && rename_from.is_some() && current.as_deref() == rename_from {
            system.git_ok(
                CODE,
                LOCAL_SECONDS,
                &tree,
                &[],
                &[
                    "branch",
                    "--move",
                    "--",
                    rename_from.unwrap_or_default(),
                    wanted,
                ],
            )?;
        } else if exists {
            system.git_ok(
                CODE,
                LOCAL_SECONDS,
                &tree,
                &[],
                &[
                    "switch",
                    "--quiet",
                    "--no-guess",
                    "--end-of-options",
                    wanted,
                ],
            )?;
        } else {
            let start = start
                .ok_or_else(|| Failure::new(CODE, "the branch is missing and no start is given"))?;
            system.git_ok(
                CODE,
                LOCAL_SECONDS,
                &tree,
                &[],
                &[
                    "switch",
                    "--quiet",
                    "--no-guess",
                    "--no-track",
                    "--create",
                    wanted,
                    "--end-of-options",
                    start,
                ],
            )?;
        }
        changes += 1;
    }
    if let Some(reset) = reset {
        let head = head(system, &tree)?;
        let dirty = !system
            .git_ok(
                CODE,
                LOCAL_SECONDS,
                &tree,
                &[],
                &["status", "--porcelain=v1", "-z", "--untracked-files=no"],
            )?
            .is_empty();
        if head.as_deref() != Some(reset) || dirty {
            system.git_ok(
                CODE,
                LOCAL_SECONDS,
                &tree,
                &[],
                &["reset", "--quiet", "--hard", "--end-of-options", reset],
            )?;
            changes += 1;
        }
    }
    if input.clean && !untracked(system, &tree)?.is_empty() {
        // Only inside this work tree: Git leaves a nested repository and every ignored file.
        system.git_ok(
            CODE,
            LOCAL_SECONDS,
            &tree,
            &[],
            &["clean", "--quiet", "--force", "-d", "--"],
        )?;
        if !untracked(system, &tree)?.is_empty() {
            return Err(Failure::new(CODE, "untracked files remain after clean"));
        }
        changes += 1;
    }
    let after = current_branch(system, &tree)?;
    let head =
        head(system, &tree)?.ok_or_else(|| Failure::new(CODE, "the branch has no commit"))?;
    if after.as_deref() != Some(wanted) || reset.is_some_and(|reset| reset != head) {
        return Err(Failure::new(
            CODE,
            "the work tree does not read back as asked",
        ));
    }
    Ok(CheckedOut {
        branch: wanted.to_owned(),
        head,
        changes,
    })
}

/// The branch `HEAD` names, or `None` when it is detached.
fn current_branch(system: &NodeSystem, tree: &Path) -> Result<Option<String>, Failure> {
    let ran = system.git(
        LOCAL_SECONDS,
        tree,
        &[],
        &["symbolic-ref", "--quiet", "HEAD"],
    )?;
    match ran.code {
        Some(0) => Ok(String::from_utf8_lossy(&ran.stdout)
            .trim()
            .strip_prefix("refs/heads/")
            .map(str::to_owned)),
        Some(1) => Ok(None),
        _ => Err(Failure::new(
            CODE,
            format!("symbolic-ref: {}", ran.stderr.trim()),
        )),
    }
}

/// The commit `HEAD` names, or `None` on a branch with no commit yet.
fn head(system: &NodeSystem, tree: &Path) -> Result<Option<String>, Failure> {
    let ran = system.git(
        LOCAL_SECONDS,
        tree,
        &[],
        &["rev-parse", "--verify", "--quiet", "HEAD^{commit}"],
    )?;
    match ran.code {
        Some(0) => Ok(Some(String::from_utf8_lossy(&ran.stdout).trim().to_owned())),
        Some(1) => Ok(None),
        _ => Err(Failure::new(
            CODE,
            format!("rev-parse: {}", ran.stderr.trim()),
        )),
    }
}

fn untracked(system: &NodeSystem, tree: &Path) -> Result<String, Failure> {
    system.git_ok(
        CODE,
        LOCAL_SECONDS,
        tree,
        &[],
        &[
            "ls-files",
            "-z",
            "--others",
            "--exclude-standard",
            "--directory",
            "--no-empty-directory",
        ],
    )
}

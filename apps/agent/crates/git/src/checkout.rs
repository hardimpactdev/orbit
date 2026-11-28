//! `git.checkout`: the branch of one work tree, as desired state.

use std::path::Path;

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{
    branch, commit, entries, full_ref, invalid, obstructions, parse, path, require_repository,
    resolve, Failure, NodeSystem, LOCAL_SECONDS,
};

const CODE: &str = "git.checkout_failed";
const REFUSED: &str = "git.checkout_refused";

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
    /// Removes the files Git does not track (ignored ones and nested repositories stay); it needs `destructive`.
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
/// when asked and allowed, resets it hard to `reset` and removes untracked files. Each step measures the same state before
/// and after it, runs only when the work tree differs, and is read back.
///
/// A switch or reset that would overwrite or remove an untracked or ignored path, a nested repository included, refuses
/// with `git.checkout_refused`: no step passes an overwrite flag, and no step deletes another repository. A work tree with
/// submodules, or with assume-unchanged or skip-worktree entries, refuses a reset and a clean.
///
/// # Errors
///
/// `git.input_invalid`, `git.destructive_not_allowed`, `git.checkout_refused`, `git.ref_symbolic`,
/// `git.repository_refused` or `git.checkout_failed`.
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
    let mut changes = switch(system, &tree, wanted, start, rename_from)?;
    if reset.is_some() || input.clean {
        guard_discard(system, &tree)?;
    }
    if let Some(reset) = reset {
        if head(system, &tree)?.as_deref() != Some(reset) || dirty(system, &tree)? {
            if obstructions(system, CODE, &tree, reset)? > 0 {
                return Err(Failure::new(
                    REFUSED,
                    "the reset would overwrite untracked or ignored paths",
                ));
            }
            system.text(
                CODE,
                &tree,
                &[
                    "reset",
                    "--quiet",
                    "--hard",
                    "--no-recurse-submodules",
                    "--end-of-options",
                    reset,
                ],
            )?;
            changes += 1;
        }
        if dirty(system, &tree)? {
            return Err(Failure::new(CODE, "tracked files differ after the reset"));
        }
    }
    if input.clean && untracked(system, &tree)? > 0 {
        // Without -x and a second -f, Git keeps ignored files and every nested repository.
        system.text(CODE, &tree, &["clean", "--quiet", "--force", "-d", "--"])?;
        if untracked(system, &tree)? > 0 {
            return Err(Failure::new(CODE, "untracked files remain after clean"));
        }
        changes += 1;
    }
    let after = current_branch(system, &tree)?;
    let head =
        head(system, &tree)?.ok_or_else(|| Failure::new(CODE, "the branch has no commit"))?;
    if after.as_deref() != Some(wanted)
        || resolve(system, CODE, &tree, &format!("refs/heads/{wanted}"))?.as_deref()
            != Some(head.as_str())
        || reset.is_some_and(|reset| reset != head)
    {
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

/// Puts `HEAD` on the branch; answers 1 when it moved. The branch must exist as a ref, not only as `HEAD`'s name.
fn switch(
    system: &NodeSystem,
    tree: &Path,
    wanted: &str,
    start: Option<&str>,
    rename_from: Option<&str>,
) -> Result<u32, Failure> {
    let current = current_branch(system, tree)?;
    let exists = resolve(system, CODE, tree, &format!("refs/heads/{wanted}"))?.is_some();
    if current.as_deref() == Some(wanted) {
        return if exists {
            Ok(0)
        } else {
            Err(Failure::new(
                REFUSED,
                "HEAD names the branch, but the branch has no commit",
            ))
        };
    }
    if let (Some(from), false) = (rename_from, exists) {
        if current.as_deref() == Some(from)
            && resolve(system, CODE, tree, &format!("refs/heads/{from}"))?.is_some()
        {
            system.text(CODE, tree, &["branch", "--move", "--", from, wanted])?;
            return Ok(1);
        }
    }
    let target = if exists {
        format!("refs/heads/{wanted}")
    } else {
        start
            .ok_or_else(|| Failure::new(CODE, "the branch is missing and no start is given"))?
            .to_owned()
    };
    if start.is_some_and(|start| start.starts_with("refs/")) && !exists {
        resolve(system, CODE, tree, &target)?
            .ok_or_else(|| Failure::new(CODE, "start names no ref"))?;
    }
    if obstructions(system, CODE, tree, &target)? > 0 {
        return Err(Failure::new(
            REFUSED,
            "the switch would overwrite untracked or ignored paths",
        ));
    }
    if exists {
        system.text(
            CODE,
            tree,
            &[
                "switch",
                "--quiet",
                "--no-guess",
                "--end-of-options",
                wanted,
            ],
        )?;
    } else {
        system.text(
            CODE,
            tree,
            &[
                "switch",
                "--quiet",
                "--no-guess",
                "--no-track",
                "--create",
                wanted,
                "--end-of-options",
                &target,
            ],
        )?;
    }
    Ok(1)
}

/// A reset or clean refuses a work tree whose state the dirt count could not measure: submodules, and entries marked
/// assume-unchanged or skip-worktree.
fn guard_discard(system: &NodeSystem, tree: &Path) -> Result<(), Failure> {
    let staged = system.bytes(CODE, tree, &["ls-files", "-z", "--stage"])?;
    if entries(&staged).any(|entry| entry.starts_with(b"160000 ")) {
        return Err(Failure::new(REFUSED, "the work tree has submodules"));
    }
    let tagged = system.bytes(CODE, tree, &["ls-files", "-z", "-v"])?;
    if entries(&tagged).any(|entry| {
        entry
            .first()
            .is_some_and(|tag| tag.is_ascii_lowercase() || *tag == b'S')
    }) {
        return Err(Failure::new(
            REFUSED,
            "the work tree has assume-unchanged or skip-worktree entries",
        ));
    }
    Ok(())
}

/// Whether a tracked file differs from `HEAD`, in the index or the work tree. Submodules are not part of it.
fn dirty(system: &NodeSystem, tree: &Path) -> Result<bool, Failure> {
    let status = system.bytes(
        CODE,
        tree,
        &[
            "status",
            "--porcelain=v2",
            "-z",
            "--untracked-files=no",
            "--ignore-submodules=all",
            "--no-renames",
        ],
    )?;
    let dirty = entries(&status).next().is_some();
    Ok(dirty)
}

/// Untracked files that are not ignored, nested repositories left out: the count `clean` brings to 0.
fn untracked(system: &NodeSystem, tree: &Path) -> Result<usize, Failure> {
    let listed = system.bytes(
        CODE,
        tree,
        &[
            "ls-files",
            "-z",
            "--others",
            "--exclude-standard",
            "--directory",
            "--no-empty-directory",
        ],
    )?;
    Ok(entries(&listed)
        .filter(|entry| {
            !(entry.ends_with(b"/")
                && tree
                    .join(<std::ffi::OsStr as std::os::unix::ffi::OsStrExt>::from_bytes(entry))
                    .join(".git")
                    .exists())
        })
        .count())
}

/// The branch `HEAD` names, or `None` when it is detached.
fn current_branch(system: &NodeSystem, tree: &Path) -> Result<Option<String>, Failure> {
    let ran = system.git(LOCAL_SECONDS, tree, &["symbolic-ref", "--quiet", "HEAD"])?;
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

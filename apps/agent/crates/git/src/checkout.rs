//! `git.checkout`: the branch of one work tree, as desired state.

use std::collections::HashSet;
use std::path::Path;

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{
    ancestors, below, branch, commit, entries, full_ref, head_branch, invalid, parse, path,
    require_repository, resolve, Failure, NodeSystem, LOCAL_SECONDS,
};

const CODE: &str = "git.checkout_failed";
const REFUSED: &str = "git.checkout_refused";
/// How many entries a clean may look through for another repository before it refuses instead.
const WALK_LIMIT: usize = 100_000;

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
    /// Allows `reset` and `clean`, which discard tracked changes and untracked files inside this work tree.
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
/// Every state that cannot be proven safe refuses with `git.checkout_refused` before anything changes: a path of the
/// target tree or the index where a folder, an untracked file or another repository sits, a submodule in the index or the
/// target, assume-unchanged or skip-worktree entries, and, for a clean, any repository among the untracked paths.
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
    if reset.is_some() || input.clean {
        guard_index(system, &tree)?;
    }
    if let Some(reset) = reset {
        // The reset's target is checked before the switch moves anything.
        guard_tree(system, &tree, reset)?;
    }
    if input.clean {
        guard_untracked(system, &tree)?;
    }
    let mut changes = switch(system, &tree, wanted, start, rename_from)?;
    if let Some(reset) = reset {
        if head(system, &tree)?.as_deref() != Some(reset) || dirty(system, &tree)? {
            guard_tree(system, &tree, reset)?;
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
        guard_untracked(system, &tree)?;
        system.text(CODE, &tree, &["clean", "--quiet", "--force", "-d", "--"])?;
        if untracked(system, &tree)? > 0 {
            return Err(Failure::new(CODE, "untracked files remain after clean"));
        }
        changes += 1;
    }
    let after = head_branch(system, CODE, &tree)?;
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

/// Puts `HEAD` on the branch; answers 1 when it moved. The branch must exist as a ref, not only as `HEAD`'s name, and the
/// branch `HEAD` names itself is checked, never the branch an alias points to.
fn switch(
    system: &NodeSystem,
    tree: &Path,
    wanted: &str,
    start: Option<&str>,
    rename_from: Option<&str>,
) -> Result<u32, Failure> {
    let current = head_branch(system, CODE, tree)?;
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
    guard_tree(system, tree, &target)?;
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

/// What the disk holds at one path of a work tree, without following a final link.
fn kind(tree: &Path, relative: &[u8]) -> Result<Option<bool>, Failure> {
    match std::fs::symlink_metadata(below(tree, relative)) {
        Ok(meta) => Ok(Some(meta.is_dir())),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(None),
        Err(error) => Err(Failure::new(CODE, error.to_string())),
    }
}

/// Refuses a switch to, or a reset to, a tree that would overwrite or remove data Git does not track. For every path of the
/// target and of the index, the disk must hold no folder there, and no file the index lacks. For every folder the target
/// needs, the disk must hold a folder that is no repository, nothing, or a file the index tracks. A submodule in the target
/// or the index refuses. Paths that only share a folder are no collision.
fn guard_tree(system: &NodeSystem, tree: &Path, target: &str) -> Result<(), Failure> {
    let refused = |detail: &str| Err(Failure::new(REFUSED, detail.to_owned()));
    let listed = system.bytes(
        CODE,
        tree,
        &[
            "ls-tree",
            "-r",
            "-z",
            "--full-tree",
            "--end-of-options",
            target,
        ],
    )?;
    let staged = system.bytes(CODE, tree, &["ls-files", "-z", "--stage"])?;
    let mut wanted = Vec::new();
    for entry in entries(&listed) {
        let (meta, path) = split_tab(entry)?;
        if meta.starts_with(b"160000 ") {
            return refused("the target tree has a submodule");
        }
        wanted.push(path);
    }
    let mut tracked = HashSet::new();
    for entry in entries(&staged) {
        let (meta, path) = split_tab(entry)?;
        if meta.starts_with(b"160000 ") {
            return refused("the index has a submodule");
        }
        tracked.insert(path);
    }
    for path in wanted.iter().chain(tracked.iter()) {
        match kind(tree, path)? {
            Some(true) => {
                return refused("a folder sits at a path the work tree tracks or the target holds")
            }
            Some(false) if !tracked.contains(path) => {
                return refused("an untracked or ignored file sits at a path of the target")
            }
            _ => {}
        }
    }
    let folders: HashSet<&[u8]> = wanted.iter().flat_map(|path| ancestors(path)).collect();
    for folder in folders {
        match kind(tree, folder)? {
            Some(false) if !tracked.contains(folder) => {
                return refused("an untracked file sits where the target needs a folder")
            }
            Some(true) if repository(&below(tree, folder)) => {
                return refused("another repository sits where the target needs a folder")
            }
            _ => {}
        }
    }
    Ok(())
}

/// The metadata and path of one `ls-tree` or `ls-files --stage` record.
fn split_tab(entry: &[u8]) -> Result<(&[u8], &[u8]), Failure> {
    let at = entry
        .iter()
        .position(|byte| *byte == b'\t')
        .ok_or_else(|| Failure::new(CODE, "git listed a record without a path"))?;
    Ok((&entry[..at], &entry[at + 1..]))
}

/// Whether a folder is a repository: a `.git` entry, or the `HEAD`, `objects` and `refs` of a bare one.
fn repository(folder: &Path) -> bool {
    std::fs::symlink_metadata(folder.join(".git")).is_ok()
        || (folder.join("HEAD").is_file()
            && folder.join("objects").is_dir()
            && folder.join("refs").is_dir())
}

/// Refuses a clean when any untracked path is or holds another repository, so a clean never removes one, bare or not.
fn guard_untracked(system: &NodeSystem, tree: &Path) -> Result<(), Failure> {
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
    let mut pending: Vec<std::path::PathBuf> = entries(&listed)
        .filter(|entry| entry.ends_with(b"/"))
        .map(|entry| below(tree, entry))
        .collect();
    let mut seen = 0;
    while let Some(folder) = pending.pop() {
        if repository(&folder) {
            return Err(Failure::new(
                REFUSED,
                "an untracked path holds another repository",
            ));
        }
        for entry in
            std::fs::read_dir(&folder).map_err(|error| Failure::new(CODE, error.to_string()))?
        {
            let entry = entry.map_err(|error| Failure::new(CODE, error.to_string()))?;
            seen += 1;
            if seen > WALK_LIMIT {
                return Err(Failure::new(
                    REFUSED,
                    "too many untracked entries to prove no repository is among them",
                ));
            }
            if entry
                .file_type()
                .map_err(|error| Failure::new(CODE, error.to_string()))?
                .is_dir()
            {
                pending.push(entry.path());
            }
        }
    }
    Ok(())
}

/// A reset or clean refuses a work tree whose state the dirt count could not measure: submodules, and entries marked
/// assume-unchanged or skip-worktree.
fn guard_index(system: &NodeSystem, tree: &Path) -> Result<(), Failure> {
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

/// Whether a tracked file differs from `HEAD`, in the index or the work tree.
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

/// Untracked files that are not ignored: the count `clean` brings to 0.
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
    let count = entries(&listed).count();
    Ok(count)
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

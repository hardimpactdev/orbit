//! `git.worktree.read`: the state of one work tree, read only.

use std::collections::BTreeMap;
use std::path::Path;

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{
    entries, full_ref, head_branch, parse, path, require_repository, resolve, Failure, NodeSystem,
};

const CODE: &str = "git.read_failed";
/// How many paths a list carries; a longer list says `truncated`, as `instance.work_read` holds at most 5,000.
const PATHS: usize = 5000;

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct ReadInput {
    worktree: String,
    /// A full ref to count `ahead` and `behind` against.
    #[serde(default)]
    against: Option<String>,
}

/// The paths of one list, in Git's order, each exactly as Git names it.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Listed {
    /// How many paths the work tree has in this list.
    pub count: u32,
    /// The first 5,000 of them.
    pub paths: Vec<String>,
    /// Whether `paths` holds fewer than `count`.
    pub truncated: bool,
}

/// One stash entry: its commit and its reflog label, such as `stash@{0}: WIP on main: …`.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Stash {
    /// The stash commit.
    pub commit: String,
    /// The reflog selector and subject; a byte that is not UTF-8 reads as U+FFFD, as it is only shown.
    pub label: String,
}

/// What one work tree holds.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(tag = "state", rename_all = "snake_case")]
pub enum Worktree {
    /// Nothing is at the path.
    Absent,
    /// A work tree is there.
    Present {
        /// The commit `HEAD` names, `None` before the first commit.
        head: Option<String>,
        /// The branch `HEAD` names itself, `None` when `HEAD` is detached.
        branch: Option<String>,
        /// Tracked paths with a staged change.
        staged: u32,
        /// Tracked paths with an unstaged change.
        unstaged: u32,
        /// Listed with a merge conflict.
        unmerged: u32,
        /// Tracked paths that differ from `HEAD`, in the index or the work tree.
        modified: Listed,
        /// Untracked files that are not ignored.
        untracked: Listed,
        /// The stash's reflog, newest first.
        stashes: Vec<Stash>,
        /// Every ref under `refs/heads/`, by its full name exactly as Git holds it, and the commit it names.
        heads: BTreeMap<String, String>,
        /// Commits on `HEAD` that `against` lacks, only when asked.
        #[serde(skip_serializing_if = "Option::is_none")]
        ahead: Option<u32>,
        /// Commits on `against` that `HEAD` lacks, only when asked.
        #[serde(skip_serializing_if = "Option::is_none")]
        behind: Option<u32>,
    },
}

/// Reads one work tree. Every call takes no lock (`--no-optional-locks`) and refreshes no index, and an active split index
/// refuses, so the read writes no file. A symbolic branch, `HEAD` naming one, or a symbolic `against` refuses. A path or a
/// branch name that is not UTF-8 fails the read instead of being changed.
///
/// # Errors
///
/// `git.input_invalid`, `git.ref_symbolic`, `git.repository_refused` or `git.read_failed`, also for a path that is there
/// but is no work tree's own folder.
pub fn worktree_read(system: &NodeSystem, input: &Value) -> Result<Worktree, Failure> {
    let input: ReadInput = parse(input)?;
    let tree = path(&input.worktree, "worktree")?;
    let against = input.against.as_deref().map(full_ref).transpose()?;
    match std::fs::symlink_metadata(&tree) {
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(Worktree::Absent),
        Err(error) => return Err(Failure::new(CODE, error.to_string())),
        Ok(_) => {}
    }
    require_repository(system, CODE, &tree, crate::Kind::WorkTree)?;
    if !system
        .text(CODE, &tree, &["rev-parse", "--shared-index-path"])?
        .trim()
        .is_empty()
    {
        return Err(Failure::new(
            "git.repository_refused",
            "the work tree uses a split index",
        ));
    }
    let branch = head_branch(system, CODE, &tree)?;
    let status = system.bytes(
        CODE,
        &tree,
        &[
            "status",
            "--porcelain=v2",
            "--branch",
            "-z",
            "--untracked-files=all",
            "--no-renames",
        ],
    )?;
    let mut head = None;
    let (mut staged, mut unstaged, mut unmerged) = (0, 0, 0);
    let (mut modified, mut untracked) = (Vec::new(), Vec::new());
    for record in entries(&status) {
        if let Some(oid) = record.strip_prefix(b"# branch.oid ") {
            head = (oid != b"(initial)").then(|| String::from_utf8_lossy(oid).into_owned());
        } else if let Some(rest) = record.strip_prefix(b"1 ") {
            staged += u32::from(rest.first().is_some_and(|byte| *byte != b'.'));
            unstaged += u32::from(rest.get(1).is_some_and(|byte| *byte != b'.'));
            // `1 XY sub mH mI mW hH hI path`: the path follows the eighth space.
            modified.push(field(rest, 7));
        } else if let Some(rest) = record.strip_prefix(b"u ") {
            unmerged += 1;
            modified.push(field(rest, 9));
        } else if let Some(rest) = record.strip_prefix(b"? ") {
            untracked.push(rest);
        }
    }
    let heads = heads(system, &tree)?;
    let stashes = stashes(system, &tree)?;
    let (ahead, behind) = match (against, &head) {
        (Some(against), Some(_)) => counts(system, &tree, against)?,
        _ => (None, None),
    };
    Ok(Worktree::Present {
        head,
        branch,
        staged,
        unstaged,
        unmerged,
        modified: listed(&modified)?,
        untracked: listed(&untracked)?,
        stashes,
        heads,
        ahead,
        behind,
    })
}

/// The bytes after the `skip`-th space of a status record: its path.
fn field(record: &[u8], skip: usize) -> &[u8] {
    record
        .splitn(skip + 1, |byte| *byte == b' ')
        .nth(skip)
        .unwrap_or_default()
}

fn listed(paths: &[&[u8]]) -> Result<Listed, Failure> {
    let named = paths
        .iter()
        .take(PATHS)
        .map(|path| {
            String::from_utf8(path.to_vec()).map_err(|_| Failure::new(CODE, "a path is not UTF-8"))
        })
        .collect::<Result<Vec<_>, _>>()?;
    Ok(Listed {
        count: u32::try_from(paths.len()).unwrap_or(u32::MAX),
        truncated: named.len() < paths.len(),
        paths: named,
    })
}

/// Every ref under `refs/heads/` by its full name; a symbolic one refuses, and a name that is not UTF-8 fails.
fn heads(system: &NodeSystem, tree: &Path) -> Result<BTreeMap<String, String>, Failure> {
    let listed = system.bytes(
        CODE,
        tree,
        &[
            "for-each-ref",
            "--format=%(objectname) %(symref)%00%(refname)%00",
            "refs/heads/",
        ],
    )?;
    let mut heads = BTreeMap::new();
    let mut fields = listed.split(|byte| *byte == 0);
    while let (Some(first), Some(name)) = (fields.next(), fields.next()) {
        let first = first.strip_prefix(b"\n").unwrap_or(first);
        let first = std::str::from_utf8(first)
            .map_err(|_| Failure::new(CODE, "for-each-ref answered no object"))?;
        let (object, symref) = first
            .split_once(' ')
            .ok_or_else(|| Failure::new(CODE, "for-each-ref answered no object"))?;
        let name = String::from_utf8(name.to_vec())
            .map_err(|_| Failure::new(CODE, "a branch name is not UTF-8"))?;
        if !symref.is_empty() {
            return Err(Failure::new(
                "git.ref_symbolic",
                format!("{name} is a symbolic ref"),
            ));
        }
        if heads
            .insert(name, crate::commit(object)?.to_owned())
            .is_some()
        {
            return Err(Failure::new(CODE, "two branches read as one name"));
        }
    }
    Ok(heads)
}

/// The stash's reflog, newest first, or none without `refs/stash`.
fn stashes(system: &NodeSystem, tree: &Path) -> Result<Vec<Stash>, Failure> {
    if resolve(system, CODE, tree, "refs/stash")?.is_none() {
        return Ok(Vec::new());
    }
    let log = system.bytes(
        CODE,
        tree,
        &[
            "log",
            "--walk-reflogs",
            "-z",
            "--format=%H %gd: %gs",
            "refs/stash",
            "--",
        ],
    )?;
    entries(&log)
        .map(|entry| {
            let text = String::from_utf8_lossy(entry);
            let (commit, label) = text
                .split_once(' ')
                .ok_or_else(|| Failure::new(CODE, "the stash log answered no commit"))?;
            Ok(Stash {
                commit: crate::commit(commit)?.to_owned(),
                label: label.to_owned(),
            })
        })
        .collect()
}

/// Commits on `HEAD` that `against` lacks, and the reverse.
fn counts(
    system: &NodeSystem,
    tree: &Path,
    against: &str,
) -> Result<(Option<u32>, Option<u32>), Failure> {
    resolve(system, CODE, tree, against)?
        .ok_or_else(|| Failure::new(CODE, "against names no ref"))?;
    let range = format!("HEAD...{against}");
    let counts = system.text(
        CODE,
        tree,
        &[
            "rev-list",
            "--left-right",
            "--count",
            "--end-of-options",
            &range,
            "--",
        ],
    )?;
    let mut parts = counts.split_whitespace().map(str::parse::<u32>);
    match (parts.next(), parts.next()) {
        (Some(Ok(ahead)), Some(Ok(behind))) => Ok((Some(ahead), Some(behind))),
        _ => Err(Failure::new(CODE, "rev-list answered no counts")),
    }
}

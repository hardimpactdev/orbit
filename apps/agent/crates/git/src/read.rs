//! `git.worktree.read`: the state of one work tree, read only.

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{full_ref, parse, path, require_repository, Failure, NodeSystem, LOCAL_SECONDS};

const CODE: &str = "git.read_failed";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct ReadInput {
    worktree: String,
    /// A full ref to count `ahead` and `behind` against.
    #[serde(default)]
    against: Option<String>,
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
        /// The branch, `None` when `HEAD` is detached.
        branch: Option<String>,
        /// Tracked paths with a staged change.
        staged: u32,
        /// Tracked paths with an unstaged change.
        unstaged: u32,
        /// Paths with a merge conflict.
        unmerged: u32,
        /// Untracked files; ignored files are not counted.
        untracked: u32,
        /// Commits on `HEAD` that `against` lacks, only when asked.
        #[serde(skip_serializing_if = "Option::is_none")]
        ahead: Option<u32>,
        /// Commits on `against` that `HEAD` lacks, only when asked.
        #[serde(skip_serializing_if = "Option::is_none")]
        behind: Option<u32>,
    },
}

/// Reads one work tree with `git status --porcelain=v2` and, when asked, `rev-list --left-right --count`. It takes no lock
/// (`--no-optional-locks`) and writes nothing.
///
/// # Errors
///
/// `git.input_invalid` or `git.read_failed`, also for a path that is there but is no work tree's own folder.
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
    let status = system.git_ok(
        CODE,
        LOCAL_SECONDS,
        &tree,
        &[],
        &[
            "status",
            "--porcelain=v2",
            "--branch",
            "-z",
            "--untracked-files=all",
            "--ignore-submodules=none",
        ],
    )?;
    let (mut head, mut branch) = (None, None);
    let (mut staged, mut unstaged, mut unmerged, mut untracked) = (0, 0, 0, 0);
    let mut records = status.split('\0');
    while let Some(record) = records.next() {
        if let Some(oid) = record.strip_prefix("# branch.oid ") {
            head = (oid != "(initial)").then(|| oid.to_owned());
        } else if let Some(name) = record.strip_prefix("# branch.head ") {
            branch = (name != "(detached)").then(|| name.to_owned());
        } else if let Some(rest) = record
            .strip_prefix("1 ")
            .or_else(|| record.strip_prefix("2 "))
        {
            let xy = rest.as_bytes();
            staged += u32::from(xy.first().is_some_and(|byte| *byte != b'.'));
            unstaged += u32::from(xy.get(1).is_some_and(|byte| *byte != b'.'));
            if record.starts_with("2 ") {
                // A rename or copy carries its original path as the next record.
                records.next();
            }
        } else if record.starts_with("u ") {
            unmerged += 1;
        } else if record.starts_with("? ") {
            untracked += 1;
        }
    }
    let (ahead, behind) = match (against, &head) {
        (Some(against), Some(_)) => {
            let range = format!("HEAD...{against}");
            let counts = system.git_ok(
                CODE,
                LOCAL_SECONDS,
                &tree,
                &[],
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
                (Some(Ok(ahead)), Some(Ok(behind))) => (Some(ahead), Some(behind)),
                _ => return Err(Failure::new(CODE, "rev-list answered no counts")),
            }
        }
        _ => (None, None),
    };
    Ok(Worktree::Present {
        head,
        branch,
        staged,
        unstaged,
        unmerged,
        untracked,
        ahead,
        behind,
    })
}

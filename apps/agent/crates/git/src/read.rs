//! `git.worktree.read`: the state of one work tree, read only.

use std::collections::BTreeMap;
use std::path::Path;

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{entries, full_ref, parse, path, require_repository, resolve, Failure, NodeSystem};

const CODE: &str = "git.read_failed";
/// How many path names a list carries, each cut to this many characters, as `instance.work_read` answers them.
const NAMES: usize = 5;
const NAME_LENGTH: usize = 200;

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct ReadInput {
    worktree: String,
    /// A full ref to count `ahead` and `behind` against.
    #[serde(default)]
    against: Option<String>,
}

/// A count and the first names of a list of paths, in `instance.work_read`'s shape.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Listed {
    /// How many paths.
    pub count: u32,
    /// The first five, each at most 200 characters; a byte that is not UTF-8 reads as U+FFFD.
    pub names: Vec<String>,
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
        /// Tracked paths that differ from `HEAD`, in the index or the work tree.
        modified: Listed,
        /// Untracked files that are not ignored.
        untracked: Listed,
        /// Entries in the stash's reflog.
        stashes: u32,
        /// Every local branch and the commit it names.
        heads: BTreeMap<String, String>,
        /// Commits on `HEAD` that `against` lacks, only when asked.
        #[serde(skip_serializing_if = "Option::is_none")]
        ahead: Option<u32>,
        /// Commits on `against` that `HEAD` lacks, only when asked.
        #[serde(skip_serializing_if = "Option::is_none")]
        behind: Option<u32>,
    },
}

/// Reads one work tree. Every call takes no lock (`--no-optional-locks`) and refreshes no index, and a split index refuses,
/// so the read writes no file. A symbolic branch or `against` refuses: the read certifies no ref through another.
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
    split_index(system, &tree)?;
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
    let (mut head, mut branch) = (None, None);
    let (mut staged, mut unstaged, mut unmerged) = (0, 0, 0);
    let (mut modified, mut untracked) = (Vec::new(), Vec::new());
    for record in entries(&status) {
        if let Some(oid) = record.strip_prefix(b"# branch.oid ") {
            head = (oid != b"(initial)").then(|| String::from_utf8_lossy(oid).into_owned());
        } else if let Some(name) = record.strip_prefix(b"# branch.head ") {
            branch = (name != b"(detached)").then(|| String::from_utf8_lossy(name).into_owned());
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
    if let Some(name) = &branch {
        resolve(system, CODE, &tree, &format!("refs/heads/{name}"))?;
    }
    let heads = heads(system, &tree)?;
    let stashes = match resolve(system, CODE, &tree, "refs/stash")? {
        Some(_) => u32::try_from(
            entries(&system.bytes(
                CODE,
                &tree,
                &[
                    "log",
                    "--walk-reflogs",
                    "-z",
                    "--format=%H",
                    "refs/stash",
                    "--",
                ],
            )?)
            .count(),
        )
        .unwrap_or(u32::MAX),
        None => 0,
    };
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
        modified: listed(&modified),
        untracked: listed(&untracked),
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

fn listed(paths: &[&[u8]]) -> Listed {
    let names = paths
        .iter()
        .take(NAMES)
        .map(|path| {
            let name = String::from_utf8_lossy(path);
            if name.chars().count() <= NAME_LENGTH {
                name.into_owned()
            } else {
                name.chars().take(NAME_LENGTH - 1).chain(['…']).collect()
            }
        })
        .collect();
    Listed {
        count: u32::try_from(paths.len()).unwrap_or(u32::MAX),
        names,
    }
}

/// A split index keeps a shared index file that a read could write; such a work tree refuses.
fn split_index(system: &NodeSystem, tree: &Path) -> Result<(), Failure> {
    let folder = system.text(CODE, tree, &["rev-parse", "--absolute-git-dir"])?;
    let shared = std::fs::read_dir(folder.trim())
        .map_err(|error| Failure::new(CODE, error.to_string()))?
        .flatten()
        .any(|entry| {
            entry
                .file_name()
                .as_encoded_bytes()
                .starts_with(b"sharedindex.")
        });
    if shared {
        return Err(Failure::new(
            "git.repository_refused",
            "the work tree has a split index",
        ));
    }
    Ok(())
}

/// Every local branch and its commit; a symbolic branch refuses.
fn heads(system: &NodeSystem, tree: &Path) -> Result<BTreeMap<String, String>, Failure> {
    let listed = system.bytes(
        CODE,
        tree,
        &[
            "for-each-ref",
            "--format=%(objectname) %(symref)%00%(refname)",
            "refs/heads/",
        ],
    )?;
    let mut heads = BTreeMap::new();
    for line in listed
        .split(|byte| *byte == b'\n')
        .filter(|line| !line.is_empty())
    {
        let text = String::from_utf8_lossy(line);
        let (object, rest) = text
            .split_once(' ')
            .ok_or_else(|| Failure::new(CODE, "for-each-ref answered no object"))?;
        let (symref, name) = rest
            .split_once('\0')
            .ok_or_else(|| Failure::new(CODE, "for-each-ref answered no name"))?;
        if !symref.is_empty() {
            return Err(Failure::new(
                "git.ref_symbolic",
                format!("{name} is a symbolic ref"),
            ));
        }
        heads.insert(
            name.trim_start_matches("refs/heads/").to_owned(),
            object.to_owned(),
        );
    }
    Ok(heads)
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

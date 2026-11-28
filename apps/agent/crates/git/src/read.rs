//! `git.worktree.read`: the state of one work tree, read only.

use std::collections::BTreeMap;
use std::path::Path;

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{
    entries, full_ref, head_branch, parse, path, require_repository, resolve, Failure, NodeSystem,
};

const CODE: &str = "git.read_failed";

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
    /// Those that fit the answer's byte bound, in Git's order.
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
    if split_index(system, &tree)? {
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
    let (modified, untracked) = (named(&modified)?, named(&untracked)?);
    let mut read = Worktree::Present {
        head,
        branch,
        staged,
        unstaged,
        unmerged,
        modified: Listed {
            count: count(modified.len()),
            paths: Vec::new(),
            truncated: !modified.is_empty(),
        },
        untracked: Listed {
            count: count(untracked.len()),
            paths: Vec::new(),
            truncated: !untracked.is_empty(),
        },
        stashes,
        heads,
        ahead,
        behind,
    };
    let mut left = ANSWER_BYTES.checked_sub(embedded(&read)).ok_or_else(|| {
        Failure::new(
            CODE,
            "the work tree's branches and stashes exceed the answer's bound",
        )
    })?;
    if let Worktree::Present {
        modified: held_modified,
        untracked: held_untracked,
        ..
    } = &mut read
    {
        fill(held_modified, modified, &mut left);
        fill(held_untracked, untracked, &mut left);
    }
    Ok(read)
}

/// The answer's bound, as the receipt embeds it: the JSON answer inside a JSON string, so every quote and backslash is
/// escaped twice. 48 KiB leaves 16 KiB of the relay's 64 KiB for the receipt's other fields.
pub(crate) const ANSWER_BYTES: usize = 48 * 1024;

/// The bytes a value takes once the receipt embeds its JSON as a string.
pub(crate) fn embedded(value: &impl Serialize) -> usize {
    serde_json::to_string(&serde_json::to_string(value).unwrap_or_default())
        .map_or(usize::MAX, |text| text.len())
}

/// Adds paths in Git's order while they fit; the first that does not fit ends the list, which stays `truncated`.
fn fill(list: &mut Listed, paths: Vec<String>, left: &mut usize) {
    let total = paths.len();
    for path in paths {
        // The path's own encoded bytes with the receipt's escaping, and its separator.
        let cost = embedded(&path).saturating_sub(2) + 1;
        if cost > *left {
            break;
        }
        *left -= cost;
        list.paths.push(path);
    }
    list.truncated = list.paths.len() < total;
}

fn count(length: usize) -> u32 {
    u32::try_from(length).unwrap_or(u32::MAX)
}

/// The bytes after the `skip`-th space of a status record: its path.
fn field(record: &[u8], skip: usize) -> &[u8] {
    record
        .splitn(skip + 1, |byte| *byte == b' ')
        .nth(skip)
        .unwrap_or_default()
}

/// Every path as text; one that is not UTF-8 fails the read instead of being changed.
fn named(paths: &[&[u8]]) -> Result<Vec<String>, Failure> {
    paths
        .iter()
        .map(|path| {
            String::from_utf8(path.to_vec()).map_err(|_| Failure::new(CODE, "a path is not UTF-8"))
        })
        .collect()
}

/// Whether the work tree's index is split: its `link` extension names a shared index by a non-null id. It reads the index
/// file's header and extensions in Rust, so the check runs no Git command that could touch the repository. A missing index
/// is not split, and a leftover `sharedindex.*` file beside an ordinary index does not count.
fn split_index(system: &NodeSystem, tree: &Path) -> Result<bool, Failure> {
    let folder = system.text(CODE, tree, &["rev-parse", "--absolute-git-dir"])?;
    let hash = match system
        .text(CODE, tree, &["rev-parse", "--show-object-format"])?
        .trim()
    {
        "sha256" => 32,
        _ => 20,
    };
    match std::fs::read(Path::new(folder.trim()).join("index")) {
        Ok(index) => linked(&index, hash)
            .ok_or_else(|| Failure::new(CODE, "the index file cannot be parsed")),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(false),
        Err(error) => Err(Failure::new(CODE, error.to_string())),
    }
}

/// Parses an index file (versions 2 to 4): whether its `link` extension holds a non-null shared index id. `None` for a
/// file that is no index.
pub(crate) fn linked(index: &[u8], hash: usize) -> Option<bool> {
    let word = |at: usize| {
        index
            .get(at..at + 4)
            .map(|bytes| u32::from_be_bytes([bytes[0], bytes[1], bytes[2], bytes[3]]))
    };
    if index.get(..4)? != b"DIRC" {
        return None;
    }
    let version = word(4)?;
    if !(2..=4).contains(&version) {
        return None;
    }
    let mut at = 12;
    // ctime, mtime, dev, ino, mode, uid, gid and size, the object id, then 16 bits of flags.
    let fixed = 40 + hash + 2;
    for _ in 0..word(8)? {
        let flags = u16::from_be_bytes([*index.get(at + fixed - 2)?, *index.get(at + fixed - 1)?]);
        let head = fixed
            + if version >= 3 && flags & 0x4000 != 0 {
                2
            } else {
                0
            };
        let mut name = at + head;
        if version == 4 {
            // The length of the previous path's prefix to drop, as Git's offset varint.
            let mut byte = *index.get(name)?;
            name += 1;
            while byte & 0x80 != 0 {
                byte = *index.get(name)?;
                name += 1;
            }
        }
        let length = index.get(name..)?.iter().position(|byte| *byte == 0)?;
        // Version 4 has no padding; earlier versions pad each entry with 1 to 8 NULs to a multiple of 8.
        at = if version == 4 {
            name + length + 1
        } else {
            at + ((head + length + 8) & !7)
        };
    }
    while index.len().checked_sub(at)? > hash {
        let signature = index.get(at..at + 4)?;
        let size = usize::try_from(word(at + 4)?).ok()?;
        let data = index.get(at + 8..at.checked_add(8)?.checked_add(size)?)?;
        if signature == b"link" {
            return Some(data.get(..hash)?.iter().any(|byte| *byte != 0));
        }
        at += 8 + size;
    }
    Some(false)
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

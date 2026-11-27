//! `instance.sync_base` and `instance.clear_base`: `task:sync` of a task copy (docs/reference/tasks.md#bring-main-into-a-copy).
//!
//! The default head that `instance.fetch_branch` brought into the copy's Node mirror reaches the copy as one bundle of what it
//! lacks, and only `refs/remotes/origin/<default>` moves, by a compare-and-swap on the value read before. HEAD, the local
//! branches, the index, the working tree and stashes are never written. No credential takes part: the mirror is local.

use std::collections::BTreeSet;
use std::os::unix::fs::PermissionsExt as _;

use serde::Deserialize;
use serde_json::{json, Value};

use crate::{Failure, System};

const GIT: &str = "/usr/bin/git";
const INCUS: &str = crate::INCUS;
const RUNUSER: &str = "/usr/sbin/runuser";
/// The copy's workspace, which only `agent` writes.
const ROOT: &str = "/srv/workspace";
/// The Node scratch and the copy's bundle folder of one copy's sync, as task-copy-base-names.yml names them.
const NODE_SCRATCH: &str = "/var/tmp/orbit-task-base-";
const GUEST_SCRATCH: &str = "/tmp/orbit-task-base-";
/// The mirror ref that names the pinned head for one bundle: the mirror's own branch may move under a later fetch.
const MIRROR_REF: &str = "refs/orbit/task-base-";
/// The most changed paths the work read names; the count gives the rest.
const NAMES: usize = 5;
const FAILED: &str = "instance.sync_base_failed";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct SyncInput {
    project: String,
    name: String,
    instance_id: u64,
    mirror: String,
    default_branch: String,
    branch: String,
    /// The mirror head the fetch receipt returned; null when the origin lacks the default branch.
    head: Option<String>,
    start: String,
    /// The commits the group's publishes pushed, as a JSON list in a blob: a long publish history never outgrows the order.
    published: crate::BlobRef,
    /// The group's latest approved commit, or empty.
    #[serde(default)]
    approved: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct ClearInput {
    project: String,
    name: String,
    instance_id: u64,
    mirror: String,
}

/// Who runs a command in the copy: `agent` in the workspace, or root for the bundle folder that agent may only read.
#[derive(Clone, Copy, PartialEq, Eq)]
enum As {
    Agent,
    Root,
}

/// The copy as `agent` and as root reaches it.
struct Copy<'a, S: System> {
    system: &'a S,
    project: &'a str,
    name: &'a str,
}

impl<S: System> Copy<'_, S> {
    fn exec(&self, who: As, command: &[&str]) -> Result<String, String> {
        let mut argv: Vec<String> = [INCUS, "--project", self.project, "exec", "--disable-stdin"].iter().map(|part| (*part).to_owned()).collect();
        if who == As::Agent {
            argv.extend(["--cwd".to_owned(), ROOT.to_owned()]);
        }
        argv.extend([self.name.to_owned(), "--".to_owned()]);
        if who == As::Agent {
            argv.extend(
                [RUNUSER, "-u", "agent", "--", "/usr/bin/env", "-i", "HOME=/home/agent", "PATH=/usr/local/bin:/usr/bin:/bin", "GIT_CONFIG_GLOBAL=/dev/null",
                    "GIT_CONFIG_NOSYSTEM=1", "GIT_TERMINAL_PROMPT=0"]
                    .iter()
                    .map(|part| (*part).to_owned()),
            );
        }
        argv.extend(command.iter().map(|part| (*part).to_owned()));
        self.system.run(120, &argv, None)
    }

    /// Git in the workspace as `agent`, with no hooks, no file system monitor and no untracked cache, as the Swamp phase runs it.
    fn try_git(&self, args: &[&str]) -> Result<String, String> {
        let safe = format!("safe.directory={ROOT}");
        let mut command = vec![GIT, "--literal-pathspecs", "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", "-c", "core.untrackedCache=false",
            "-c", "core.quotePath=false", "-c", &safe];
        command.extend_from_slice(args);
        self.exec(As::Agent, &command)
    }

    fn git(&self, args: &[&str]) -> Result<String, Failure> {
        self.try_git(args).map_err(|detail| Failure::new(FAILED, format!("git {}: {detail}", args.first().copied().unwrap_or_default())))
    }

    fn text(&self, args: &[&str]) -> Result<String, Failure> {
        self.git(args).map(|out| out.trim().to_owned())
    }

    fn holds(&self, commit: &str) -> bool {
        self.try_git(&["cat-file", "-e", &format!("{commit}^{{commit}}")]).is_ok()
    }

    fn root(&self, command: &[&str]) -> Result<(), Failure> {
        self.exec(As::Root, command).map(drop).map_err(|detail| Failure::new(FAILED, format!("{}: {detail}", command.join(" "))))
    }
}

/// What the import must leave as it found it: HEAD's branch and commit and every local branch.
#[derive(Debug, PartialEq, Eq)]
struct Kept {
    branch: String,
    head: String,
    branches: String,
}

/// Imports what the copy lacks of the pinned head from the Node mirror and points `refs/remotes/origin/<default>` at it, then
/// reads back the ref, HEAD and the local branches, how far the branch is behind and the copy's work. A repeat finds the ref at
/// the head and changes nothing; a ref that moved since the read refuses. Answers the base evidence of the Swamp phase.
///
/// # Errors
///
/// `instance.sync_input_invalid`, `tasks.default_branch_missing` (the origin has no default branch), `tasks.base_ref_mismatch`
/// (the ref does not hold the head after the update) or `instance.sync_base_failed`.
pub fn sync_base(system: &impl System, input: &Value) -> Result<String, Failure> {
    let input: SyncInput = serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.sync_input_invalid", error.to_string()))?;
    let published: Vec<String> = system
        .blob(&input.published.blob.sha256)
        .map_err(|detail| Failure::new("instance.sync_input_invalid", detail))
        .and_then(|bytes| serde_json::from_slice(&bytes).map_err(|error| Failure::new("instance.sync_input_invalid", error.to_string())))?;
    check(&input, &published).map_err(|detail| Failure::new("instance.sync_input_invalid", detail))?;
    let head = input.head.clone().ok_or_else(|| Failure::new("tasks.default_branch_missing", "the origin has no default branch"))?;
    let copy = Copy { system, project: &input.project, name: &input.name };
    let reference = format!("refs/remotes/origin/{}", input.default_branch);
    let before = kept(&copy, &input.branch)?;
    // A symbolic remote ref names another ref, such as a local branch: moving it would move that branch.
    if copy.try_git(&["symbolic-ref", "--quiet", &reference]).is_ok() {
        return Err(Failure::new(FAILED, format!("{reference} is a symbolic ref")));
    }
    let origin = copy.text(&["for-each-ref", "--format=%(objectname)", &reference])?;
    let bundled = origin != head && !copy.holds(&head);
    if bundled {
        let imported = import(system, &copy, &input, &head, &origin, &before.head);
        let cleared = clear(system, &copy, input.instance_id, &input.mirror);
        imported?;
        cleared?;
    }
    if origin != head {
        copy.git(&["cat-file", "-e", &format!("{head}^{{commit}}")])?;
        // The compare-and-swap on the literal ref: an empty old value requires the ref to be absent.
        if let Err(detail) = copy.try_git(&["update-ref", "--no-deref", &reference, &head, &origin]) {
            let now = copy.text(&["for-each-ref", "--format=%(objectname)", &reference])?;
            let code = if now == origin { FAILED } else { "tasks.base_ref_mismatch" };
            return Err(Failure::new(code, format!("update-ref: {detail}")));
        }
    }
    if copy.text(&["rev-parse", "--verify", &format!("{reference}^{{commit}}")])? != head {
        return Err(Failure::new("tasks.base_ref_mismatch", "the remote ref does not hold the head"));
    }
    if kept(&copy, &input.branch)? != before {
        return Err(Failure::new(FAILED, "HEAD or a local branch moved during the import"));
    }
    copy.git(&["cat-file", "-e", &format!("{}^{{commit}}", input.start)])?;
    let behind = copy.text(&["rev-list", "--count", &format!("{}..{reference}", before.head)])?;
    let behind: u64 = behind.parse().map_err(|_| Failure::new(FAILED, "rev-list answered no count"))?;
    let work = work(&copy, &input, &published, &before.head)?;
    Ok(json!({
        "default_branch": input.default_branch, "default_commit": head, "branch": input.branch, "branch_commit": before.head,
        "behind_by": behind, "start": input.start, "bundled": bundled, "changed": origin != head, "work": work,
    })
    .to_string())
}

/// Removes what a sync of the copy left: its Node scratch, its mirror ref and its bundle folder in the copy. Answers
/// `{"cleaned": true}`; a repeat finds nothing and answers the same.
///
/// # Errors
///
/// `instance.sync_input_invalid` or `instance.sync_base_failed`.
pub fn clear_base(system: &impl System, input: &Value) -> Result<String, Failure> {
    let input: ClearInput = serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.sync_input_invalid", error.to_string()))?;
    names(&input.project, &input.name, &input.mirror).map_err(|detail| Failure::new("instance.sync_input_invalid", detail))?;
    clear(system, &Copy { system, project: &input.project, name: &input.name }, input.instance_id, &input.mirror)?;
    Ok(json!({"cleaned": true}).to_string())
}

fn kept(copy: &Copy<'_, impl System>, branch: &str) -> Result<Kept, Failure> {
    if copy.text(&["rev-parse", "--show-toplevel"])? != ROOT {
        return Err(Failure::new(FAILED, "the workspace is not the copy's"));
    }
    let now = copy.text(&["symbolic-ref", "--quiet", "--short", "HEAD"]).unwrap_or_default();
    if now != branch {
        return Err(Failure::new(FAILED, format!("the workspace holds {now}")));
    }
    Ok(Kept { branch: now, head: copy.text(&["rev-parse", "HEAD"])?, branches: copy.text(&["for-each-ref", "--format=%(refname) %(objectname)", "refs/heads"])? })
}

/// One bundle of the pinned head, without the copy's commits the mirror also holds, fetched as `agent` into the object store:
/// no ref is written by it. Root makes the bundle folder fresh and owns it; agent only reads the bundle.
fn import(system: &impl System, copy: &Copy<'_, impl System>, input: &SyncInput, head: &str, origin: &str, branch: &str) -> Result<(), Failure> {
    let mirror = system.path(&input.mirror).display().to_string();
    let node = system.path(&format!("{NODE_SCRATCH}{}", input.instance_id));
    let guest = format!("{GUEST_SCRATCH}{}", input.instance_id);
    let pinned = format!("{MIRROR_REF}{}", input.instance_id);
    let mirror_git = |args: &[&str]| -> Result<String, String> {
        let mut argv: Vec<String> = [GIT, "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", "-C", &mirror].iter().map(|part| (*part).to_owned()).collect();
        argv.extend(args.iter().map(|part| (*part).to_owned()));
        system.run(300, &argv, None)
    };
    let _ = std::fs::remove_dir_all(&node);
    std::fs::create_dir_all(&node)
        .and_then(|()| std::fs::set_permissions(&node, std::fs::Permissions::from_mode(0o700)))
        .map_err(|error| failed("scratch")(error.to_string()))?;
    let mut prerequisites: Vec<String> = Vec::new();
    for commit in [origin, branch] {
        if !commit.is_empty() && !prerequisites.iter().any(|known| known == commit) && mirror_git(&["cat-file", "-e", &format!("{commit}^{{commit}}")]).is_ok() {
            prerequisites.push(format!("^{commit}"));
        }
    }
    mirror_git(&["update-ref", &pinned, head]).map_err(failed("pin"))?;
    let bundle = node.join("base.bundle").display().to_string();
    let mut create = vec!["bundle", "create", "--quiet", &bundle, &pinned];
    create.extend(prerequisites.iter().map(String::as_str));
    mirror_git(&create).map_err(failed("bundle"))?;
    let gid = copy.exec(As::Agent, &["/usr/bin/id", "-g"]).map_err(failed("gid"))?;
    let gid = gid.trim();
    copy.root(&["/bin/rm", "-rf", "--", &guest])?;
    copy.root(&["/bin/mkdir", "-m", "0755", "--", &guest])?;
    let target = format!("{}{guest}/base.bundle", copy.name);
    system
        .run(300, &[INCUS, "--project", copy.project, "file", "push", "--uid", "0", "--gid", gid, "--mode", "0640", &bundle, &target].map(str::to_owned), None)
        .map_err(failed("push"))?;
    let inside = format!("{guest}/base.bundle");
    copy.git(&["bundle", "verify", "--quiet", &inside])?;
    copy.git(&["-c", "gc.auto=0", "-c", "maintenance.auto=false", "fetch", "--quiet", "--no-tags", "--no-write-fetch-head", &inside, &pinned])?;
    Ok(())
}

fn failed(what: &'static str) -> impl Fn(String) -> Failure {
    move |detail| Failure::new(FAILED, format!("{what}: {detail}"))
}

/// Whether a Node path is there: only a confirmed missing path is absent, and any other read error fails.
fn present(path: &std::path::Path) -> Result<bool, Failure> {
    match std::fs::symlink_metadata(path) {
        Ok(_) => Ok(true),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(false),
        Err(error) => Err(Failure::new(FAILED, format!("read {}: {error}", path.display()))),
    }
}

fn clear(system: &impl System, copy: &Copy<'_, impl System>, instance: u64, mirror: &str) -> Result<(), Failure> {
    let node = system.path(&format!("{NODE_SCRATCH}{instance}"));
    if present(&node)? {
        std::fs::remove_dir_all(&node).map_err(|error| Failure::new(FAILED, format!("scratch: {error}")))?;
    }
    let mirror = system.path(mirror).display().to_string();
    let pinned = format!("{MIRROR_REF}{instance}");
    // A read that fails is no proof the pin is gone: only an answer counts.
    let held = || -> Result<bool, Failure> {
        if !present(&std::path::Path::new(&mirror).join("HEAD"))? {
            return Ok(false);
        }
        system
            .run(30, &[GIT, "-C", &mirror, "for-each-ref", "--format=%(refname)", &pinned].map(str::to_owned), None)
            .map(|refs| !refs.trim().is_empty())
            .map_err(|detail| Failure::new(FAILED, format!("read the pin: {detail}")))
    };
    if held()? {
        system.run(30, &[GIT, "-C", &mirror, "update-ref", "-d", &pinned].map(str::to_owned), None).map_err(failed("unpin"))?;
    }
    // What a stopped mirror seed of this copy left (instance.fetch_branch `seed`), and no other copy's.
    let staging = std::path::PathBuf::from(crate::fetch::seeding(&mirror));
    if present(&staging)? {
        std::fs::remove_dir_all(&staging).map_err(|error| failed("seed staging")(error.to_string()))?;
    }
    let guest = format!("{GUEST_SCRATCH}{instance}");
    copy.root(&["/bin/rm", "-rf", "--", &guest])?;
    if present(&node)? || present(&staging)? || held()? || copy.exec(As::Root, &["/usr/bin/test", "!", "-e", &guest]).is_err() {
        return Err(Failure::new(FAILED, "the sync scratch is still there"));
    }
    Ok(())
}

/// The one content rule (docs/reference/tasks.md#published-work-of-a-copy) on the default head now in the ref: every path HEAD
/// changed since its merge base with it must hold the same content on it, or in a commit a publish pushed. Uncommitted,
/// untracked and stashed work is never published. The same read as the Swamp phase `base-work`.
fn work(copy: &Copy<'_, impl System>, input: &SyncInput, published: &[String], head: &str) -> Result<Value, Failure> {
    let default = copy.text(&["rev-parse", "--verify", &format!("refs/remotes/origin/{}^{{commit}}", input.default_branch)])?;
    // Git quotes a path that is not plain ASCII, one line each: every distinct path keeps its own name through the text
    // boundary, and only the names shown are decoded.
    let paths = |source: &str| -> Result<BTreeSet<String>, Failure> {
        Ok(copy.git(&["-c", "core.quotePath=true", "diff-tree", "-r", "--no-renames", "--name-only", source, head])?.lines().filter(|name| !name.is_empty())
            .map(str::to_owned).collect())
    };
    let changed = paths(&copy.text(&["merge-base", head, &default])?)?;
    let mut differ: BTreeSet<String> = changed.intersection(&paths(&default)?).cloned().collect();
    for commit in published {
        if !differ.is_empty() && copy.holds(commit) {
            differ = differ.intersection(&paths(commit)?).cloned().collect();
        }
    }
    let status = copy.git(&["--no-optional-locks", "status", "--porcelain=v1", "-z", "--untracked-files=all"])?;
    let (mut modified, mut untracked) = (0_u64, 0_u64);
    let mut entries = status.split('\0');
    while let Some(entry) = entries.next() {
        if entry.starts_with("??") {
            untracked += 1;
        } else if !entry.is_empty() {
            modified += 1;
            if entry.starts_with(['R', 'C']) {
                entries.next();
            }
        }
    }
    let stashes = if copy.text(&["for-each-ref", "--format=%(refname)", "refs/stash"])?.is_empty() {
        0
    } else {
        copy.text(&["reflog", "show", "--format=%H", "refs/stash"])?.split_whitespace().count()
    };
    let approved_held = (!input.approved.is_empty()).then(|| copy.try_git(&["merge-base", "--is-ancestor", &input.approved, head]).is_ok());
    let mut names: Vec<String> = differ.iter().map(|name| unquote(name)).collect();
    names.sort();
    names.truncate(NAMES);
    let dirty = !differ.is_empty() || modified > 0 || untracked > 0 || stashes > 0;
    Ok(json!({
        "state": if dirty { "dirty" } else { "clean" }, "head": head, "default_commit": default, "empty": changed.is_empty(),
        "paths": {"count": differ.len(), "names": names}, "uncommitted": modified, "untracked": untracked, "stashes": stashes, "approved_held": approved_held,
    }))
}

/// A path as Git printed it with `core.quotePath`: a C-quoted name decoded to its bytes, shown with invalid UTF-8 replaced.
fn unquote(name: &str) -> String {
    let Some(inner) = name.strip_prefix('"').and_then(|rest| rest.strip_suffix('"')) else { return name.to_owned() };
    let (mut bytes, mut chars) = (Vec::new(), inner.bytes().peekable());
    while let Some(byte) = chars.next() {
        if byte != b'\\' {
            bytes.push(byte);
            continue;
        }
        match chars.next() {
            Some(digit @ b'0'..=b'7') => {
                let mut value = u32::from(digit - b'0');
                for _ in 0..2 {
                    if let Some(next @ b'0'..=b'7') = chars.peek().copied() {
                        value = value * 8 + u32::from(next - b'0');
                        chars.next();
                    }
                }
                bytes.push(u8::try_from(value).unwrap_or(b'?'));
            }
            Some(b'n') => bytes.push(b'\n'),
            Some(b't') => bytes.push(b'\t'),
            Some(b'r') => bytes.push(b'\r'),
            Some(b'a') => bytes.push(0x07),
            Some(b'b') => bytes.push(0x08),
            Some(b'f') => bytes.push(0x0c),
            Some(b'v') => bytes.push(0x0b),
            Some(other) => bytes.push(other),
            None => {}
        }
    }
    String::from_utf8_lossy(&bytes).into_owned()
}

fn commit(value: &str) -> bool {
    matches!(value.len(), 40 | 64) && value.bytes().all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
}

fn names(project: &str, name: &str, mirror: &str) -> Result<(), String> {
    for (field, value) in [("project", project), ("name", name)] {
        if !crate::name(value) {
            return Err(format!("{field} is not a name"));
        }
    }
    crate::fetch::mirror_path(mirror).then_some(()).ok_or_else(|| "mirror is not an Orbit mirror".to_owned())
}

fn check(input: &SyncInput, published: &[String]) -> Result<(), String> {
    names(&input.project, &input.name, &input.mirror)?;
    crate::fetch::branch_name(&input.default_branch).then_some(()).ok_or("default_branch is not a branch name")?;
    let task = input.branch.strip_prefix("task-").is_some_and(|id| !id.is_empty() && !id.starts_with('0') && id.bytes().all(|byte| byte.is_ascii_digit()));
    task.then_some(()).ok_or("branch is not a task branch")?;
    if input.head.as_deref().is_some_and(|head| !commit(head)) || !commit(&input.start) || published.iter().any(|value| !commit(value))
        || (!input.approved.is_empty() && !commit(&input.approved))
    {
        return Err("a commit is not a commit id".to_owned());
    }
    (input.instance_id > 0).then_some(()).ok_or_else(|| "instance_id is not an Instance".to_owned())
}

#[cfg(test)]
mod tests;

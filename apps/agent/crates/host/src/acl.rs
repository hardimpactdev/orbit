//! `posix.acl.ensure`: one user entry of the access or default list of one path, or, with `scope: tree`, of one folder tree:
//! the root and every folder and file below it, walked here without following a link. The tree scope is the one exception to
//! "one resource" (Nick, 2026-11-28; docs/reference/executor.md#primitives). Lifted from the `setfacl` calls of
//! `route.publish_site` (`access_argv`); the subject user, the path, the permissions and the scope come from input.

use std::collections::BTreeMap;
use std::os::fd::{AsFd, OwnedFd};
use std::os::unix::fs::MetadataExt as _;
use std::sync::LazyLock;

use regex::Regex;
use rustix::fs::{AtFlags, Dir, FileType, Mode, OFlags, CWD};
use serde::Deserialize;

use crate::{absolute, invalid, parse, strings, Failure, System, NAME};

static PERMS: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[r-][w-][xX-]$").expect("a valid pattern"));

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    path: String,
    /// The user the entry is for.
    user: String,
    /// `rwx` form; `X` grants search on folders only.
    perms: String,
    /// The default entry that new folders inherit, instead of the access entry.
    #[serde(default)]
    default: bool,
    /// The account that runs `setfacl`, so a path it cannot reach is never changed with root's rights.
    #[serde(default)]
    run_as: Option<String>,
    /// A missing path is left out and changes nothing, instead of failing.
    #[serde(default)]
    optional: bool,
    /// The one path, or the folder tree below it too.
    #[serde(default)]
    scope: Scope,
}

/// What the entry is converged on.
#[derive(Debug, Default, Clone, Copy, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "snake_case")]
enum Scope {
    /// The path alone; nothing below it changes.
    #[default]
    Path,
    /// The path and every folder below it, and for an access entry every plain file below it. A link, and anything that is
    /// neither a folder nor a plain file, is skipped and never followed.
    Tree,
}

/// Sets the entry on each path where it differs and answers how many paths changed: `0` on a repeat. Each path is read first,
/// never through a link; with the path scope nothing below it is touched. A path changes when its entry differs from the
/// wanted permissions, `X` resolved as `setfacl` does, or when its mask cuts the entry. Every change is read back.
///
/// # Errors
///
/// `host.input_invalid` for an input outside the admitted shape, and `acl.ensure_failed` for a missing path or a failed
/// read, walk, change or read-back anywhere in the tree.
pub fn acl_ensure(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = parse(input)?;
    if !absolute(&input.path) || !NAME.is_match(&input.user) || !PERMS.is_match(&input.perms) || input.run_as.as_ref().is_some_and(|user| !NAME.is_match(user)) {
        return Err(invalid("acl"));
    }
    let failed = |detail: String| Failure::new("acl.ensure_failed", detail);
    let root = system.path(&input.path);
    match std::fs::symlink_metadata(&root) {
        Err(error) if error.kind() == std::io::ErrorKind::NotFound && input.optional => return Ok(0),
        Err(error) => return Err(failed(error.to_string())),
        Ok(_) => {}
    }
    // Every path as the order names it, with the permissions it must hold.
    let mut paths: Vec<(String, String)> = Vec::new();
    match input.scope {
        Scope::Path => collect(&root, &input.path, &input, &mut paths),
        Scope::Tree => tree(&root, &input.path, &input, &mut paths),
    }
    .map_err(|error| failed(error.to_string()))?;
    let prefix = input.run_as.as_ref().map_or_else(Vec::new, |user| strings(&["/usr/sbin/runuser", "-u", user, "--", "/usr/bin/env", "-C", "/"]));
    let read = |names: &[String]| -> Result<BTreeMap<String, Entry>, String> {
        let mut held = BTreeMap::new();
        for chunk in names.chunks(200) {
            let mut argv = prefix.clone();
            argv.extend(strings(&["/usr/bin/getfacl", "--physical", "--absolute-names", "--"]));
            argv.extend(chunk.iter().cloned());
            held.extend(listing(&system.run(60, &argv)?, &input));
        }
        Ok(held)
    };
    let names: Vec<String> = paths.iter().map(|(name, _)| name.clone()).collect();
    let before = read(&names).map_err(failed)?;
    let changed: Vec<String> = paths.iter().filter(|(name, wanted)| before.get(name).is_none_or(|entry| !entry.holds(wanted))).map(|(name, _)| name.clone()).collect();
    let wants: BTreeMap<&str, &str> = paths.iter().map(|(name, perms)| (name.as_str(), perms.as_str())).collect();
    let wanted = |name: &String| wants.get(name.as_str()).map_or_else(String::new, |perms| (*perms).to_owned());
    // The mask is shared by every group-class entry: it is given explicitly, so setfacl never recomputes it. It only widens by
    // the bits this entry needs, and only where no other entry holds a bit the mask cut, so no other subject's effective
    // permissions move.
    let tag = if input.default { "d:" } else { "" };
    // For each changed path: the entries to write, and what every other subject must still hold afterwards.
    let mut plans = BTreeMap::new();
    for name in &changed {
        let entry = before.get(name).ok_or_else(|| failed(format!("{name} was not read")))?;
        let want = wanted(name);
        // The first default entry of a folder starts a default list: it is seeded from the access list (owner, group,
        // other, and the group-class bits as the mask), so every other subject keeps what new files inherit.
        let (mask, others, base, seed) = if input.default && !entry.has_default {
            let access = |key: &str| entry.access.get(key).cloned().ok_or_else(|| failed(format!("{name} has no access {key} entry")));
            let (owner, group, other) = (access("user")?, access("group")?, access("other")?);
            let mask = entry.access.get("mask").cloned().unwrap_or_else(|| group.clone());
            let seed = format!("d:u::{owner},d:g::{group},d:o::{other},");
            (Some(mask), vec![("group:".to_owned(), group)], vec![("other:".to_owned(), other), ("user:".to_owned(), owner)], seed)
        } else {
            (entry.mask.clone(), entry.others.clone(), entry.base.clone(), String::new())
        };
        // The mask is shared by every group-class entry: it is given explicitly, so setfacl never recomputes it. It only
        // widens by the bits this entry needs, and only where no other entry holds a bit the mask cut.
        let current = mask.clone().unwrap_or_else(|| others.iter().fold("---".to_owned(), |held, (_, perms)| union(&held, perms)));
        let widened = union(&current, &want);
        if mask.is_some() && others.iter().any(|(_, perms)| cut(perms, &current) != cut(perms, &widened)) {
            return Err(failed(format!("{name}: the mask would widen another entry's permissions")));
        }
        let expected = Entry { mask: Some(widened.clone()), others, base, ..Entry::default() };
        plans.insert(name.clone(), (format!("{seed}{tag}u:{}:{want},{tag}m::{widened}", input.user), expected));
    }
    // Paths that need the same entries share one `setfacl` call, at most 200 at a time.
    let mut batches: BTreeMap<&str, Vec<String>> = BTreeMap::new();
    for name in &changed {
        batches.entry(plans[name].0.as_str()).or_default().push(name.clone());
    }
    for (entries, names) in &batches {
        for chunk in names.chunks(200) {
            let mut argv = prefix.clone();
            argv.extend(strings(&["/usr/bin/setfacl", "-P", "-n", "-m", entries, "--"]));
            argv.extend(chunk.iter().cloned());
            system.run(120, &argv).map_err(failed)?;
        }
    }
    // Every changed path holds the entry, and every other subject's effective permissions are what they were.
    let after = if changed.is_empty() { BTreeMap::new() } else { read(&changed).map_err(failed)? };
    for name in &changed {
        let expected = &plans[name].1;
        let kept = after.get(name).is_some_and(|entry| entry.holds(&wanted(name)) && entry.effective_others() == expected.effective_others() && sorted(&entry.base) == sorted(&expected.base));
        if !kept {
            return Err(failed(format!("{name} read-back")));
        }
    }
    Ok(u32::try_from(changed.len()).unwrap_or(u32::MAX))
}

/// The bits set in either `rwx` string.
fn union(left: &str, right: &str) -> String {
    left.chars().zip(right.chars()).map(|(a, b)| if a == '-' { b } else { a }).collect()
}

/// `perms` as the mask lets them take effect.
fn cut(perms: &str, mask: &str) -> String {
    perms.chars().zip(mask.chars()).map(|(bit, allowed)| if allowed == '-' { '-' } else { bit }).collect()
}

/// The user's entry, the mask that bounds it, every other group-class entry the mask bounds too, and the owner and other
/// entries, of the listed access or default list on one path; with the path's access list and whether it has a default list.
#[derive(Debug, Default, Clone)]
struct Entry {
    perms: Option<String>,
    mask: Option<String>,
    others: Vec<(String, String)>,
    base: Vec<(String, String)>,
    access: BTreeMap<String, String>,
    has_default: bool,
}

fn sorted(entries: &[(String, String)]) -> Vec<(String, String)> {
    let mut entries = entries.to_vec();
    entries.sort();
    entries
}

impl Entry {
    fn holds(&self, wanted: &str) -> bool {
        let effective = |perms: &str| self.mask.as_deref().map_or_else(|| perms.to_owned(), |mask| cut(perms, mask));
        self.perms.as_deref().is_some_and(|perms| perms == wanted && effective(perms) == wanted)
    }

    /// What each other group-class entry may do, after the mask.
    fn effective_others(&self) -> Vec<(String, String)> {
        self.others.iter().map(|(tag, perms)| (tag.clone(), self.mask.as_deref().map_or_else(|| perms.clone(), |mask| cut(perms, mask)))).collect()
    }
}

/// getfacl's listing as each path's entry for the input's user, of the access or the default list.
fn listing(text: &str, input: &Input) -> BTreeMap<String, Entry> {
    let tag = if input.default { "default:" } else { "" };
    let mut entries = BTreeMap::new();
    let mut current: Option<String> = None;
    for line in text.lines() {
        if let Some(name) = line.strip_prefix("# file: ") {
            current = Some(unescape(name));
            entries.entry(current.clone().unwrap_or_default()).or_insert_with(Entry::default);
            continue;
        }
        let Some(name) = &current else { continue };
        let line = line.split('\t').next().unwrap_or_default().trim();
        let entry = entries.entry(name.clone()).or_insert_with(Entry::default);
        entry.has_default |= line.starts_with("default:");
        for key in ["user", "group", "other", "mask"] {
            if let Some(perms) = line.strip_prefix(&format!("{key}::")) {
                entry.access.insert(key.to_owned(), perms.to_owned());
            }
        }
        if let Some(rest) = line.strip_prefix(tag).filter(|rest| rest.starts_with("user::") || rest.starts_with("other::")) {
            if let Some((subject, perms)) = rest.split_once("::") {
                entry.base.push((format!("{subject}:"), perms.to_owned()));
            }
        }
        if let Some(perms) = line.strip_prefix(&format!("{tag}user:{}:", input.user)) {
            entry.perms = Some(perms.to_owned());
        } else if let Some(perms) = line.strip_prefix(&format!("{tag}mask::")) {
            entry.mask = Some(perms.to_owned());
        } else if let Some(rest) = line.strip_prefix(tag).filter(|rest| (rest.starts_with("user:") && !rest.starts_with("user::")) || rest.starts_with("group:")) {
            if let Some((subject, perms)) = rest.rsplit_once(':') {
                entry.others.push((subject.to_owned(), perms.to_owned()));
            }
        }
    }
    entries
}

/// A getfacl file name with its `\ooo` escapes resolved.
fn unescape(name: &str) -> String {
    let bytes = name.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut index = 0;
    while index < bytes.len() {
        if bytes[index] == b'\\' && index + 4 <= bytes.len() && bytes[index + 1..index + 4].iter().all(|digit| (b'0'..=b'7').contains(digit)) {
            out.push(u8::from_str_radix(std::str::from_utf8(&bytes[index + 1..index + 4]).unwrap_or("0"), 8).unwrap_or(0));
            index += 4;
        } else {
            out.push(bytes[index]);
            index += 1;
        }
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// The one path (named `name`), never through a link, with the permissions it must hold: `X` is search on a folder, and
/// on a file only when some execute bit is set.
fn collect(path: &std::path::Path, name: &str, input: &Input, paths: &mut Vec<(String, String)>) -> std::io::Result<()> {
    let meta = std::fs::symlink_metadata(path)?;
    let folder = meta.file_type().is_dir();
    if meta.file_type().is_symlink() || (input.default && !folder) {
        return Ok(());
    }
    paths.push((name.to_owned(), wanted(&input.perms, meta.mode())));
    Ok(())
}

/// The tree at `path` (named `name`): the root and everything below it that the entry applies to, in walk order, with the
/// permissions each must hold. The walk opens every folder relative to its parent's descriptor with `O_NOFOLLOW` and reads
/// each entry with `AT_SYMLINK_NOFOLLOW`, so a link is skipped and never followed, also when it points out of the tree. A
/// root that is a link is skipped too. Any failed read fails the walk; a path that vanishes is not counted as absent.
fn tree(path: &std::path::Path, name: &str, input: &Input, paths: &mut Vec<(String, String)>) -> std::io::Result<()> {
    let stat = rustix::fs::statat(CWD, path, AtFlags::SYMLINK_NOFOLLOW)?;
    let kind = FileType::from_raw_mode(stat.st_mode);
    if kind.is_dir() {
        let folder = open(CWD, path, &stat)?;
        walk(folder, name, input, paths)?;
    } else if kind.is_file() {
        visit(name, kind, stat.st_mode, input, paths);
    }
    Ok(())
}

/// The folder `folder` (named `name`) and, below it, every folder and plain file, depth first.
fn walk(folder: OwnedFd, name: &str, input: &Input, paths: &mut Vec<(String, String)>) -> std::io::Result<()> {
    paths.push((name.to_owned(), wanted(&input.perms, 0o040_000)));
    let mut children = Vec::new();
    let mut entries = Dir::new(folder)?;
    while let Some(entry) = entries.read() {
        let entry = entry?;
        let child = entry.file_name().to_bytes();
        if child != b"." && child != b".." {
            children.push(entry.file_name().to_owned());
        }
    }
    children.sort();
    let at = entries.fd()?;
    for child in children {
        let text = child.to_str().map_err(|_| std::io::Error::other(format!("{name}: a name below it is not UTF-8")))?;
        let named = format!("{name}/{text}");
        let stat = rustix::fs::statat(at, child.as_c_str(), AtFlags::SYMLINK_NOFOLLOW).map_err(|error| std::io::Error::other(format!("{named}: {error}")))?;
        let kind = FileType::from_raw_mode(stat.st_mode);
        if kind.is_dir() {
            let below = open(at, child.as_c_str(), &stat).map_err(|error| std::io::Error::other(format!("{named}: {error}")))?;
            walk(below, &named, input, paths)?;
        } else {
            visit(&named, kind, stat.st_mode, input, paths);
        }
    }
    Ok(())
}

/// Opens the folder `path` relative to `at`, never through a link, and only when it is still the folder `stat` read.
fn open<P: rustix::path::Arg>(at: impl AsFd, path: P, stat: &rustix::fs::Stat) -> std::io::Result<OwnedFd> {
    let folder = rustix::fs::openat(at, path, OFlags::RDONLY | OFlags::DIRECTORY | OFlags::NOFOLLOW | OFlags::CLOEXEC, Mode::empty())?;
    let held = rustix::fs::fstat(&folder)?;
    if held.st_dev != stat.st_dev || held.st_ino != stat.st_ino {
        return Err(std::io::Error::other("a folder changed while it was read"));
    }
    Ok(folder)
}

/// A plain file below the root: it holds the access entry, never a default entry. Links and every other type are skipped.
fn visit(name: &str, kind: FileType, mode: u32, input: &Input, paths: &mut Vec<(String, String)>) {
    if kind.is_file() && !input.default {
        paths.push((name.to_owned(), wanted(&input.perms, mode)));
    }
}

/// `perms` with `X` resolved for a path of `mode` (`st_mode`): search on a folder, and on a file only when some execute bit
/// is set.
fn wanted(perms: &str, mode: u32) -> String {
    let search = mode & 0o170_000 == 0o040_000 || mode & 0o111 != 0;
    perms.replace('X', if search { "x" } else { "-" })
}

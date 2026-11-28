//! `posix.acl.ensure`: one user entry of the access list of one path, or of every folder below it. Lifted from the
//! `setfacl` calls of `route.publish_site` (`access_argv`); the subject user, the path and the permissions come from input.

use std::os::unix::fs::MetadataExt as _;
use std::sync::LazyLock;

use regex::Regex;
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
    /// Every folder below the path too (and, for an access entry, every file).
    #[serde(default)]
    recursive: bool,
    /// The account that runs `setfacl`, so a path it cannot reach is never changed with root's rights.
    #[serde(default)]
    run_as: Option<String>,
    /// A missing path is left out and changes nothing, instead of failing.
    #[serde(default)]
    optional: bool,
}

/// Sets the entry where it differs and answers how many paths changed. Each affected path (the path, or with `recursive`
/// every folder below it, and every file for an access entry; links are never followed) is read first. A path changes when
/// its entry differs from the wanted permissions, `X` resolved per path as `setfacl` does, or when its mask cuts the entry.
/// Every changed path is read back.
///
/// # Errors
///
/// `host.input_invalid` for an input outside the admitted shape, and `acl.ensure_failed` for a missing path or a failed
/// read, change or read-back.
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
    // Each affected path as the order names it, with the permissions it must hold.
    let mut paths: Vec<(String, String)> = Vec::new();
    collect(&root, &input.path, &input, &mut paths).map_err(|error| failed(error.to_string()))?;
    let prefix = input.run_as.as_ref().map_or_else(Vec::new, |user| strings(&["/usr/sbin/runuser", "-u", user, "--", "/usr/bin/env", "-C", "/"]));
    let read = |names: &[String]| -> Result<std::collections::BTreeMap<String, Entry>, String> {
        let mut held = std::collections::BTreeMap::new();
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
    let differ = |held: &std::collections::BTreeMap<String, Entry>| -> Vec<String> {
        paths.iter().filter(|(name, wanted)| held.get(name).is_none_or(|entry| !entry.holds(wanted))).map(|(name, _)| name.clone()).collect()
    };
    let changed = differ(&before);
    let wanted = |name: &String| paths.iter().find(|(path, _)| path == name).map(|(_, perms)| perms.clone()).unwrap_or_default();
    // The mask is shared by every group-class entry: it is given explicitly, so setfacl never recomputes it. It only widens by
    // the bits this entry needs, and only where no other entry holds a bit the mask cut, so no other subject's effective
    // permissions move.
    let tag = if input.default { "d:" } else { "" };
    // For each changed path: the entries to write, and what every other subject must still hold afterwards.
    let mut plans = std::collections::BTreeMap::new();
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
    for name in &changed {
        let mut argv = prefix.clone();
        argv.extend(strings(&["/usr/bin/setfacl", "-P", "-n", "-m", &plans[name].0, "--", name]));
        system.run(120, &argv).map_err(failed)?;
    }
    // Every changed path holds the entry, and every other subject's effective permissions are what they were.
    let after = if changed.is_empty() { std::collections::BTreeMap::new() } else { read(&changed).map_err(failed)? };
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
    access: std::collections::BTreeMap<String, String>,
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
fn listing(text: &str, input: &Input) -> std::collections::BTreeMap<String, Entry> {
    let tag = if input.default { "default:" } else { "" };
    let mut entries = std::collections::BTreeMap::new();
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

/// The affected paths below `path` (named `name`), never through a link, each with the permissions it must hold: `X` is
/// search on a folder, and on a file only when some execute bit is set.
fn collect(path: &std::path::Path, name: &str, input: &Input, paths: &mut Vec<(String, String)>) -> std::io::Result<()> {
    let meta = std::fs::symlink_metadata(path)?;
    let folder = meta.file_type().is_dir();
    if meta.file_type().is_symlink() || (input.default && !folder) {
        return Ok(());
    }
    let search = folder || meta.mode() & 0o111 != 0;
    paths.push((name.to_owned(), input.perms.replace('X', if search { "x" } else { "-" })));
    if input.recursive && folder {
        let mut children: Vec<_> = std::fs::read_dir(path)?.collect::<Result<_, _>>()?;
        children.sort_by_key(std::fs::DirEntry::file_name);
        for child in children {
            let child_name = format!("{name}/{}", child.file_name().to_string_lossy());
            collect(&child.path(), &child_name, input, paths)?;
        }
    }
    Ok(())
}

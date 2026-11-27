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
    // Each path with its own permissions, `X` resolved, goes alone.
    for name in &changed {
        let mut argv = prefix.clone();
        argv.extend(strings(&["/usr/bin/setfacl", "-P", "-m", &format!("{}u:{}:{}", if input.default { "d:" } else { "" }, input.user, wanted(name)), "--", name]));
        system.run(120, &argv).map_err(failed)?;
    }
    let after = if changed.is_empty() { std::collections::BTreeMap::new() } else { read(&changed).map_err(failed)? };
    if changed.iter().any(|name| after.get(name).is_none_or(|entry| !entry.holds(&wanted(name)))) {
        return Err(failed(format!("{} read-back", input.path)));
    }
    Ok(u32::try_from(changed.len()).unwrap_or(u32::MAX))
}

/// The user's entry and the mask that bounds it, on one path.
#[derive(Debug, Default)]
struct Entry {
    perms: Option<String>,
    mask: Option<String>,
}

impl Entry {
    fn holds(&self, wanted: &str) -> bool {
        let effective = |perms: &str| match &self.mask {
            Some(mask) => perms.chars().zip(mask.chars()).map(|(bit, allowed)| if allowed == '-' { '-' } else { bit }).collect::<String>(),
            None => perms.to_owned(),
        };
        self.perms.as_deref().is_some_and(|perms| perms == wanted && effective(perms) == wanted)
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
        if let Some(perms) = line.strip_prefix(&format!("{tag}user:{}:", input.user)) {
            entry.perms = Some(perms.to_owned());
        } else if let Some(perms) = line.strip_prefix(&format!("{tag}mask::")) {
            entry.mask = Some(perms.to_owned());
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

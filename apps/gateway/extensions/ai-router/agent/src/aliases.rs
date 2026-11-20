//! Orbit's desired `antigravity` model alias list of CLIProxyAPI. Orbit owns only this list of the router's
//! configuration file: it edits those lines in place and keeps every other byte, so it never depends on a router save.
use crate::collector::Error;
use serde_yaml::{Mapping, Value};
use std::{
    fs,
    io::{Read, Seek, SeekFrom, Write},
    path::{Path, PathBuf},
};

pub const CHANNEL: &str = "antigravity";

/// The router's configuration under the collector's account, which is the router's account.
pub const CONFIG: &str = ".config/cliproxyapi/config.yaml";

/// Base Gemini names that agy sends, mapped to the tiered ids of the Antigravity pool, as (name, alias).
/// Every entry forks, so the tiered id stays listed beside its alias.
pub const ANTIGRAVITY: [(&str, &str); 7] = [
    ("gemini-4-argon-high", "gemini-4-argon"),
    ("gemini-4-argon-high", "gemini-3.1-pro"),
    ("gemini-4-argon-high", "gemini-3.1-pro-preview"),
    ("gemini-3.8-flash-high", "gemini-3.8-flash"),
    ("gemini-3.8-flash-high", "gemini-3.7-flash"),
    ("gemini-3.8-flash-high", "gemini-3.6-flash"),
    ("gemini-3.8-flash-high", "gemini-3.1-flash-lite-preview"),
];

/// CLIProxyAPI v8 reads `oauth.model-alias` when that key is present, and the legacy root key otherwise.
const CURRENT: [&str; 2] = ["oauth", "model-alias"];
const LEGACY: [&str; 1] = ["oauth-model-alias"];

const UNSUPPORTED: Error =
    Error::Invalid("Router configuration has a model alias form Orbit cannot edit safely");

/// Writes the antigravity list into the file when the effective persisted list differs, and reports whether it
/// wrote. CLIProxyAPI reloads the file when it changes.
pub fn converge(path: &Path) -> Result<bool, Error> {
    converge_with(path, |file, bytes| file.write_all(bytes))
}

fn converge_with(
    path: &Path,
    write: impl Fn(&mut fs::File, &[u8]) -> std::io::Result<()>,
) -> Result<bool, Error> {
    let path =
        fs::canonicalize(path).map_err(|_| Error::Invalid("Router configuration unavailable"))?;
    recover(&path)?;
    let original =
        fs::read_to_string(&path).map_err(|_| Error::Invalid("Router configuration unreadable"))?;
    let Some(edited) = edit(&original)? else {
        return Ok(false);
    };
    replace(&path, &original, &edited, write)?;
    Ok(true)
}

fn desired() -> Value {
    Value::Sequence(
        ANTIGRAVITY
            .iter()
            .map(|(name, alias)| {
                let mut entry = Mapping::new();
                entry.insert("name".into(), (*name).into());
                entry.insert("alias".into(), (*alias).into());
                entry.insert("fork".into(), true.into());
                Value::Mapping(entry)
            })
            .collect(),
    )
}

fn render(indent: usize) -> String {
    let pad = " ".repeat(indent);
    let mut out = format!("{pad}{CHANNEL}:\n");
    for (name, alias) in ANTIGRAVITY {
        out.push_str(&format!(
            "{pad}  - name: {name}\n{pad}    alias: {alias}\n{pad}    fork: true\n"
        ));
    }
    out
}

fn at<'a>(root: &'a Mapping, path: &[&str]) -> Option<&'a Value> {
    let mut node = root.get(path[0]);
    for key in &path[1..] {
        node = node.and_then(Value::as_mapping).and_then(|m| m.get(*key));
    }
    node
}

/// The effective alias map path and the key of the antigravity channel in it.
type Target = (&'static [&'static str], Option<Value>);
fn target(root: &Mapping) -> Result<Target, Error> {
    if root.keys().any(|k| {
        k.as_str()
            .is_some_and(|k| k.starts_with("oauth.") || k.trim() != k)
    }) {
        return Err(UNSUPPORTED);
    }
    let current = root
        .get("oauth")
        .and_then(Value::as_mapping)
        .is_some_and(|o| o.contains_key("model-alias"));
    let path: &'static [&'static str] = if current { &CURRENT } else { &LEGACY };
    let channels: Vec<&Value> = match at(root, path) {
        None | Some(Value::Null) => Vec::new(),
        Some(Value::Mapping(aliases)) => aliases
            .keys()
            .filter(|k| {
                k.as_str()
                    .is_some_and(|k| k.trim().eq_ignore_ascii_case(CHANNEL))
            })
            .collect(),
        Some(_) => return Err(UNSUPPORTED),
    };
    match channels.as_slice() {
        [] => Ok((path, None)),
        [key] => Ok((path, Some((*key).clone()))),
        _ => Err(UNSUPPORTED),
    }
}

/// The original document with only the managed list set, which a candidate must equal.
fn expected(root: &Mapping, path: &[&str], channel: &Value) -> Value {
    let mut root = root.clone();
    let mut node = &mut root;
    for key in path {
        let entry = node.entry((*key).into()).or_insert(Value::Null);
        if entry.is_null() {
            *entry = Value::Mapping(Mapping::new());
        }
        let Some(mapping) = entry.as_mapping_mut() else {
            return Value::Null;
        };
        node = mapping;
    }
    node.insert(channel.clone(), desired());
    Value::Mapping(root)
}

fn indent(line: &str) -> usize {
    line.len() - line.trim_start_matches(' ').len()
}

fn significant(line: &str) -> bool {
    let content = line.trim();
    !content.is_empty() && !content.starts_with('#')
}

/// Splits `key: rest` and unquotes the key.
fn key(content: &str) -> Option<(&str, &str)> {
    let content = content.trim_start();
    let (key, rest) = match content.chars().next()? {
        quote @ ('"' | '\'') => {
            let end = content[1..].find(quote)? + 1;
            (
                &content[1..end],
                content[end + 1..].trim_start().strip_prefix(':')?,
            )
        }
        _ => {
            let at = content
                .char_indices()
                .find(|&(i, c)| {
                    c == ':'
                        && content[i + 1..]
                            .chars()
                            .next()
                            .is_none_or(char::is_whitespace)
                })?
                .0;
            (content[..at].trim_end(), &content[at + 1..])
        }
    };
    Some((key, rest))
}

/// The significant lines of the block under the line `parent`.
fn block(lines: &[&str], parent: usize) -> Vec<usize> {
    let depth = indent(lines[parent]);
    let end = (parent + 1..lines.len())
        .find(|&i| significant(lines[i]) && indent(lines[i]) <= depth)
        .unwrap_or(lines.len());
    (parent + 1..end)
        .filter(|&i| significant(lines[i]))
        .collect()
}

/// The last line of the entry at `at`: a sequence at the column of its key, as PyYAML writes it, belongs to it.
fn last(lines: &[&str], children: &[usize], at: usize) -> usize {
    let column = indent(lines[at]);
    let next = children.iter().copied().find(|&i| {
        i > at
            && (indent(lines[i]) < column
                || (indent(lines[i]) == column && !lines[i].trim_start().starts_with('-')))
    });
    children
        .iter()
        .copied()
        .filter(|&i| i >= at && next.is_none_or(|n| i < n))
        .max()
        .unwrap_or(at)
}

fn find(lines: &[&str], candidates: &[usize], column: usize, name: &str) -> Vec<usize> {
    candidates
        .iter()
        .copied()
        .filter(|&i| {
            indent(lines[i]) == column
                && key(lines[i]).is_some_and(|(k, _)| k.trim().eq_ignore_ascii_case(name))
        })
        .collect()
}

/// Builds the file with only the managed lines changed, then accepts it only when it parses to the original
/// document with the managed list set. Returns `None` when the effective list already matches, in any form.
fn edit(text: &str) -> Result<Option<String>, Error> {
    let document: Value = serde_yaml::from_str(text).map_err(|_| UNSUPPORTED)?;
    let root = match document {
        Value::Mapping(root) => root,
        Value::Null => Mapping::new(),
        _ => return Err(UNSUPPORTED),
    };
    let (path, channel) = target(&root)?;
    if let Some(channel) = &channel {
        if at(&root, path)
            .and_then(Value::as_mapping)
            .and_then(|m| m.get(channel))
            == Some(&desired())
        {
            return Ok(None);
        }
    }
    let candidate = lines(text, path).ok_or(UNSUPPORTED)?;
    let parsed: Value = serde_yaml::from_str(&candidate).map_err(|_| UNSUPPORTED)?;
    if parsed != expected(&root, path, &channel.unwrap_or_else(|| CHANNEL.into())) {
        return Err(UNSUPPORTED);
    }
    Ok(Some(candidate))
}

fn lines(text: &str, path: &[&str]) -> Option<String> {
    let lines: Vec<&str> = text.split_inclusive('\n').collect();
    let splice = |from: usize, to: usize, block: String| {
        let mut out = lines[..from].concat();
        if !out.is_empty() && !out.ends_with('\n') {
            out.push('\n');
        }
        out.push_str(&block);
        out.push_str(&lines[to..].concat());
        Some(out)
    };
    let mut parent = None;
    let mut candidates: Vec<usize> = (0..lines.len())
        .filter(|&i| significant(lines[i]) && indent(lines[i]) == 0)
        .collect();
    for (depth, name) in path.iter().enumerate() {
        let column = candidates.first().map_or(0, |&i| indent(lines[i]));
        match find(&lines, &candidates, column, name).as_slice() {
            [] if depth == 0 => {
                let nested: String = path
                    .iter()
                    .enumerate()
                    .map(|(d, k)| format!("{}{k}:\n", " ".repeat(2 * d)))
                    .collect();
                return splice(lines.len(), lines.len(), nested + &render(2 * path.len()));
            }
            [found] => {
                parent = Some(*found);
                candidates = block(&lines, *found);
            }
            _ => return None,
        }
    }
    let parent = parent?;
    let column = indent(lines[parent]);
    let (name, rest) = key(lines[parent])?;
    let inline = rest.split(" #").next()?.trim();
    if !inline.is_empty() {
        if !candidates.is_empty() || !matches!(inline, "{}" | "null" | "~") {
            return None;
        }
        return splice(
            parent,
            parent + 1,
            format!("{}{name}:\n{}", " ".repeat(column), render(column + 2)),
        );
    }
    let child = candidates.first().map_or(column + 2, |&i| indent(lines[i]));
    if child <= column {
        return None;
    }
    match find(&lines, &candidates, child, CHANNEL).as_slice() {
        [] => {
            let after = candidates
                .last()
                .map_or(parent + 1, |&i| last(&lines, &candidates, i) + 1);
            splice(after, after, render(child))
        }
        [found] => splice(*found, last(&lines, &candidates, *found) + 1, render(child)),
        _ => None,
    }
}

fn sibling(path: &Path, suffix: &str) -> Option<PathBuf> {
    Some(path.parent()?.join(format!(
        ".{}.orbit-{suffix}",
        path.file_name()?.to_string_lossy()
    )))
}

fn store(path: &Path, bytes: &str) -> std::io::Result<()> {
    use std::os::unix::fs::OpenOptionsExt;
    let mut file = fs::OpenOptions::new()
        .write(true)
        .create(true)
        .truncate(true)
        .mode(0o600)
        .open(path)?;
    file.write_all(bytes.as_bytes())?;
    file.sync_all()
}

/// Finishes a write that stopped part-way: a file that holds only the start of the next version gets the previous
/// version back.
fn recover(path: &Path) -> Result<(), Error> {
    let failed = Error::Invalid("Router configuration could not be recovered");
    let previous = sibling(path, "previous").ok_or(failed.clone())?;
    let next = sibling(path, "next").ok_or(failed.clone())?;
    if previous.exists() {
        let current = fs::read_to_string(path).unwrap_or_default();
        if fs::read_to_string(&next).is_ok_and(|next| current != next && next.starts_with(&current))
        {
            fs::rename(&previous, path).map_err(|_| failed)?;
        }
    }
    let _ = fs::remove_file(&previous);
    let _ = fs::remove_file(&next);
    Ok(())
}

/// Rewrites the file in place, unless it changed since it was read. CLIProxyAPI watches the file itself, so only an
/// in-place write reloads it, and it keeps the owner and mode. The previous version is stored first: a write that
/// fails gets it back at once by rename, and a write that stops part-way gets it back in the next round.
fn replace(
    path: &Path,
    original: &str,
    edited: &str,
    write: impl Fn(&mut fs::File, &[u8]) -> std::io::Result<()>,
) -> Result<(), Error> {
    let failed = Error::Invalid("Router configuration could not be written");
    let previous = sibling(path, "previous").ok_or(failed.clone())?;
    let next = sibling(path, "next").ok_or(failed.clone())?;
    let discard = || {
        let _ = fs::remove_file(&previous);
        let _ = fs::remove_file(&next);
    };
    if store(&previous, original)
        .and_then(|()| store(&next, edited))
        .is_err()
    {
        discard();
        return Err(failed);
    }
    let Ok(mut file) = fs::OpenOptions::new().read(true).write(true).open(path) else {
        discard();
        return Err(failed);
    };
    let mut current = String::new();
    if file.read_to_string(&mut current).is_err() || current != original {
        discard();
        return Err(Error::Invalid(
            "Router configuration changed while it was edited",
        ));
    }
    let written = file
        .seek(SeekFrom::Start(0))
        .and_then(|_| file.set_len(0))
        .and_then(|()| write(&mut file, edited.as_bytes()))
        .and_then(|()| file.sync_all())
        .and_then(|()| fs::read_to_string(path))
        .is_ok_and(|now| now == edited);
    if !written {
        let restored = fs::rename(&previous, path).is_ok();
        discard();
        return Err(if restored {
            failed
        } else {
            Error::Invalid("Router configuration could not be restored")
        });
    }
    discard();
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::os::unix::fs::{MetadataExt, PermissionsExt};

    const LIVE: &str = "oauth-model-alias:
  antigravity:
    - {name: gemini-4-argon-high, alias: gemini-4-argon, fork: true}
    - {name: gemini-4-argon-high, alias: gemini-3.1-pro, fork: true}
    - {name: gemini-4-argon-high, alias: gemini-3.1-pro-preview, fork: true}
    - {name: gemini-3.8-flash-high, alias: gemini-3.8-flash, fork: true}
    - {name: gemini-3.8-flash-high, alias: gemini-3.7-flash, fork: true}
    - {name: gemini-3.8-flash-high, alias: gemini-3.6-flash, fork: true}
    - {name: gemini-3.8-flash-high, alias: gemini-3.1-flash-lite-preview, fork: true}
";

    const ADOPTED: &str = "# operator notes stay
port: 8317
api-keys:
  - anchor-placeholder
generative-language-api-key:
  - \"gl-placeholder\"
openai-compatibility:
  - name: other
    base-url: https://example.invalid/v1
    api-keys: [\"oc-placeholder\"]
amp-upstream-url: https://ampcode.invalid
auth:
  providers: {}
oauth-model-alias:
  codex:   # operator's
    - name: gpt-6
      alias: gpt-latest
  antigravity:
    - name: gemini-4-argon-high
      alias: gemini-4-argon
      fork: true
  claude:
    - {name: claude-x, alias: claude-y}
ampcode:
  model-mappings: []
";

    struct Dir(PathBuf);
    impl Drop for Dir {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.0);
        }
    }

    fn file(text: &str) -> (Dir, PathBuf) {
        let dir =
            Dir(std::env::temp_dir().join(format!("orbit-aliases-{:016x}", rand::random::<u64>())));
        fs::create_dir(&dir.0).unwrap();
        let path = dir.0.join("config.yaml");
        fs::write(&path, text).unwrap();
        (dir, path)
    }

    fn written(text: &str) -> String {
        edit(text).unwrap().unwrap()
    }

    #[test]
    fn keeps_the_live_antigravity_values() {
        assert_eq!(edit(LIVE).unwrap(), None);
        assert_eq!(
            edit(&format!("oauth-model-alias:\n{}", render(2))).unwrap(),
            None
        );
        let flow = "{oauth-model-alias: {antigravity: [".to_owned()
            + &ANTIGRAVITY
                .map(|(n, a)| format!("{{name: {n}, alias: {a}, fork: true}}"))
                .join(", ")
            + "]}}\n";
        assert_eq!(edit(&flow).unwrap(), None);
    }

    #[test]
    fn replaces_only_the_antigravity_lines_and_then_changes_nothing() {
        let (_dir, path) = file(ADOPTED);
        fs::set_permissions(&path, fs::Permissions::from_mode(0o640)).unwrap();
        let inode = fs::metadata(&path).unwrap().ino();
        assert!(converge(&path).unwrap());
        assert_eq!(fs::metadata(&path).unwrap().ino(), inode);
        let text = fs::read_to_string(&path).unwrap();
        let (before, after) = ADOPTED.split_once("  antigravity:\n").unwrap();
        let after = &after[after.find("  claude:").unwrap()..];
        assert_eq!(text, format!("{before}{}{after}", render(2)));
        assert_eq!(
            fs::metadata(&path).unwrap().permissions().mode() & 0o777,
            0o640
        );
        assert!(!converge(&path).unwrap());
        assert_eq!(fs::read_to_string(&path).unwrap(), text);
        assert_eq!(fs::read_dir(path.parent().unwrap()).unwrap().count(), 1);
    }

    #[test]
    fn adds_the_channel_or_the_key_when_missing() {
        let text =
            "port: 8317\noauth-model-alias:\n    codex:\n      - name: a\n        alias: b\n# tail\n";
        let edited = written(text);
        assert_eq!(
            edited,
            format!(
                "port: 8317\noauth-model-alias:\n    codex:\n      - name: a\n        alias: b\n{}# tail\n",
                render(4)
            )
        );
        assert_eq!(edit(&edited).unwrap(), None);
        assert_eq!(
            written("port: 8317"),
            format!("port: 8317\noauth-model-alias:\n{}", render(2))
        );
        assert_eq!(
            written("oauth-model-alias: {}\nport: 1\n"),
            format!("oauth-model-alias:\n{}port: 1\n", render(2))
        );
        let commented = "# oauth-model-alias:\n#   antigravity: []\nport: 1\n";
        assert_eq!(
            written(commented),
            format!("{commented}oauth-model-alias:\n{}", render(2))
        );
    }

    #[test]
    fn edits_a_sequence_at_the_column_of_its_key() {
        let text = "oauth-model-alias:\n  antigravity:\n  - name: x\n    alias: y\n  codex:\n  - name: a\n    alias: b\nport: 1\n";
        assert_eq!(
            written(text),
            format!(
                "oauth-model-alias:\n{}  codex:\n  - name: a\n    alias: b\nport: 1\n",
                render(2)
            )
        );
    }

    #[test]
    fn edits_a_quoted_channel_key() {
        let text = "oauth-model-alias:\n  \"antigravity\": []\n  codex: []\n";
        assert_eq!(
            written(text),
            format!("oauth-model-alias:\n{}  codex: []\n", render(2))
        );
    }

    #[test]
    fn converges_the_v8_key_when_present_and_leaves_the_legacy_one() {
        let text = "oauth:\n  auth-dir: /a\n  model-alias:\n    codex:\n      - {name: a, alias: b}\n    antigravity: []\noauth-model-alias:\n  antigravity: []\n";
        let edited = written(text);
        assert_eq!(
            edited,
            format!(
                "oauth:\n  auth-dir: /a\n  model-alias:\n    codex:\n      - {{name: a, alias: b}}\n{}oauth-model-alias:\n  antigravity: []\n",
                render(4)
            )
        );
        assert_eq!(edit(&edited).unwrap(), None);
        // Without `oauth.model-alias`, the root key is the effective one even beside other oauth settings.
        let legacy = format!("oauth:\n  auth-dir: /a\noauth-model-alias:\n{}", render(2));
        assert_eq!(edit(&legacy).unwrap(), None);
    }

    #[test]
    fn refuses_a_form_it_cannot_edit_safely_without_writing() {
        for text in [
            "{port: 1, oauth-model-alias: {codex: []}}\n",
            "  port: 1\n  oauth-model-alias:\n    antigravity: []\n",
            "port: 1\n...\n",
            "oauth-model-alias:\n  antigravity: &list [{name: a, alias: b}]\n  codex: *list\n",
            "oauth-model-alias:\n  \"Antigravity\": []\n  antigravity: []\n",
            "oauth.model-alias:\n  antigravity: []\n",
            "oauth-model-alias:\n  {antigravity: [], codex: [{name: a, alias: b}]}\n",
            "port: [1\n",
        ] {
            let (_dir, path) = file(text);
            assert!(converge(&path).is_err(), "{text}");
            assert_eq!(fs::read_to_string(&path).unwrap(), text);
            assert_eq!(fs::read_dir(path.parent().unwrap()).unwrap().count(), 1);
        }
    }

    #[test]
    fn a_short_write_restores_the_previous_config() {
        let (_dir, path) = file(ADOPTED);
        let half = |file: &mut fs::File, bytes: &[u8]| {
            file.write_all(&bytes[..bytes.len() / 2])?;
            Err(std::io::Error::other("File too large"))
        };
        assert!(converge_with(&path, half).is_err());
        assert_eq!(fs::read_to_string(&path).unwrap(), ADOPTED);
        assert_eq!(fs::read_dir(path.parent().unwrap()).unwrap().count(), 1);
        assert!(converge(&path).unwrap());
    }

    #[test]
    fn a_write_that_stopped_part_way_is_restored_in_the_next_round() {
        let (_dir, path) = file(ADOPTED);
        let next = written(ADOPTED);
        fs::write(sibling(&path, "previous").unwrap(), ADOPTED).unwrap();
        fs::write(sibling(&path, "next").unwrap(), &next).unwrap();
        fs::write(&path, &next[..next.len() / 3]).unwrap();
        assert!(converge(&path).unwrap());
        assert_eq!(fs::read_to_string(&path).unwrap(), next);
        assert_eq!(fs::read_dir(path.parent().unwrap()).unwrap().count(), 1);
    }

    #[test]
    fn a_failed_write_changes_nothing_and_the_next_round_writes_again() {
        let (_dir, path) = file(ADOPTED);
        fs::set_permissions(&path, fs::Permissions::from_mode(0o440)).unwrap();
        assert!(converge(&path).is_err());
        assert_eq!(fs::read_to_string(&path).unwrap(), ADOPTED);
        fs::set_permissions(&path, fs::Permissions::from_mode(0o640)).unwrap();
        assert!(converge(&path).unwrap());
        assert!(!converge(&path).unwrap());
    }
}

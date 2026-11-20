//! Orbit's desired `oauth-model-alias.antigravity` list of CLIProxyAPI. Orbit owns only this node of the router's
//! configuration file: it edits those lines in place and keeps every other byte, so it never depends on a router save.
use crate::collector::Error;
use std::{
    fs,
    io::{Read, Seek, SeekFrom, Write},
    path::Path,
};

pub const KEY: &str = "oauth-model-alias";
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

/// Writes the antigravity list into the file when the persisted list differs, and reports whether it wrote.
/// CLIProxyAPI reloads the file when it changes.
pub fn converge(path: &Path) -> Result<bool, Error> {
    let path =
        fs::canonicalize(path).map_err(|_| Error::Invalid("Router configuration unavailable"))?;
    let original =
        fs::read_to_string(&path).map_err(|_| Error::Invalid("Router configuration unreadable"))?;
    let Some(edited) = edit(&original)? else {
        return Ok(false);
    };
    replace(&path, &original, &edited)?;
    Ok(true)
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
            (&content[1..end], content[end + 1..].strip_prefix(':')?)
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

fn value(raw: &str) -> &str {
    let raw = raw.split(" #").next().unwrap_or_default().trim();
    raw.strip_prefix('"')
        .and_then(|v| v.strip_suffix('"'))
        .or_else(|| raw.strip_prefix('\'').and_then(|v| v.strip_suffix('\'')))
        .unwrap_or(raw)
}

/// Reads a block list of `name`, `alias` and `fork` maps, in block or flow form. Any other shape is not the desired list.
fn matches(lines: &[&str]) -> bool {
    let mut items: Vec<Vec<(String, String)>> = Vec::new();
    for line in lines.iter().filter(|l| significant(l)) {
        let content = line.trim();
        let pairs = if let Some(item) = content
            .strip_prefix("- ")
            .or_else(|| (content == "-").then_some(""))
        {
            items.push(Vec::new());
            let item = item.trim();
            match item.strip_prefix('{').and_then(|i| i.strip_suffix('}')) {
                Some(flow) => flow.split(',').map(str::to_owned).collect(),
                None if item.is_empty() => Vec::new(),
                None => vec![item.to_owned()],
            }
        } else {
            vec![content.to_owned()]
        };
        let Some(item) = items.last_mut() else {
            return false;
        };
        for pair in pairs {
            let Some((k, v)) = key(&pair) else {
                return false;
            };
            item.push((k.to_owned(), value(v).to_owned()));
        }
    }
    items.len() == ANTIGRAVITY.len()
        && items.iter().zip(ANTIGRAVITY).all(|(item, (name, alias))| {
            let mut item = item.clone();
            item.sort();
            item == [
                ("alias".into(), alias.into()),
                ("fork".into(), "true".into()),
                ("name".into(), name.into()),
            ]
        })
}

/// Returns the file with only the antigravity node replaced, or `None` when it already holds the desired list.
fn edit(text: &str) -> Result<Option<String>, Error> {
    let unsupported =
        Error::Invalid("Router configuration has an unsupported oauth-model-alias form");
    let lines: Vec<&str> = text.split_inclusive('\n').collect();
    let top: Vec<usize> = (0..lines.len())
        .filter(|&i| significant(lines[i]) && indent(lines[i]) == 0)
        .collect();
    let owners: Vec<usize> = top
        .iter()
        .copied()
        .filter(|&i| key(lines[i]).is_some_and(|(k, _)| k == KEY))
        .collect();
    let (start, end, rest) = match owners.as_slice() {
        [] => {
            if lines.iter().any(|l| l.contains(KEY)) {
                return Err(unsupported);
            }
            let separator = if text.is_empty() || text.ends_with('\n') {
                ""
            } else {
                "\n"
            };
            return Ok(Some(format!("{text}{separator}{KEY}:\n{}", render(2))));
        }
        [owner] => {
            let end = top
                .iter()
                .copied()
                .find(|&i| i > *owner)
                .unwrap_or(lines.len());
            (
                *owner,
                end,
                value(key(lines[*owner]).ok_or(Error::Invalid("unreachable"))?.1),
            )
        }
        _ => return Err(unsupported),
    };
    let children: Vec<usize> = (start + 1..end)
        .filter(|&i| significant(lines[i]))
        .collect();
    if !rest.is_empty() {
        if !matches!(rest, "{}" | "null" | "~") || !children.is_empty() {
            return Err(unsupported);
        }
        let mut out = lines[..start].concat();
        out.push_str(&format!("{KEY}:\n{}", render(2)));
        out.push_str(&lines[start + 1..].concat());
        return Ok(Some(out));
    }
    let child = children.first().map_or(2, |&i| indent(lines[i]));
    if children
        .iter()
        .any(|&i| indent(lines[i]) < child || lines[i].starts_with('\t'))
    {
        return Err(unsupported);
    }
    let channels: Vec<usize> = children
        .iter()
        .copied()
        .filter(|&i| indent(lines[i]) == child && key(lines[i]).is_some_and(|(k, _)| k == CHANNEL))
        .collect();
    let (from, to) = match channels.as_slice() {
        [] => {
            let after = children.last().map_or(start + 1, |&i| i + 1);
            (after, after)
        }
        [channel] => {
            let next = children
                .iter()
                .copied()
                .find(|&i| i > *channel && indent(lines[i]) <= child)
                .unwrap_or(end);
            let last = children
                .iter()
                .copied()
                .filter(|&i| i < next)
                .max()
                .unwrap_or(*channel);
            if value(key(lines[*channel]).ok_or(Error::Invalid("unreachable"))?.1).is_empty()
                && matches(&lines[channel + 1..=last])
            {
                return Ok(None);
            }
            (*channel, last + 1)
        }
        _ => return Err(unsupported),
    };
    let mut out = lines[..from].concat();
    if !out.is_empty() && !out.ends_with('\n') {
        out.push('\n');
    }
    out.push_str(&render(child));
    out.push_str(&lines[to..].concat());
    Ok(Some(out))
}

/// Rewrites the file in place, unless it changed since it was read. CLIProxyAPI watches the file itself, so a
/// replacement by rename would not reload; an in-place write keeps its owner, mode and watch, as the router's own save does.
fn replace(path: &Path, original: &str, edited: &str) -> Result<(), Error> {
    let failed = Error::Invalid("Router configuration could not be written");
    let mut file = fs::OpenOptions::new()
        .read(true)
        .write(true)
        .open(path)
        .map_err(|_| failed.clone())?;
    let mut current = String::new();
    file.read_to_string(&mut current)
        .map_err(|_| failed.clone())?;
    if current != original {
        return Err(Error::Invalid(
            "Router configuration changed while it was edited",
        ));
    }
    file.seek(SeekFrom::Start(0))
        .and_then(|_| file.set_len(0))
        .and_then(|()| file.write_all(edited.as_bytes()))
        .and_then(|()| file.sync_all())
        .map_err(|_| failed)
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

    struct Dir(std::path::PathBuf);
    impl Dir {
        fn path(&self) -> &Path {
            &self.0
        }
    }
    impl Drop for Dir {
        fn drop(&mut self) {
            let _ = fs::set_permissions(&self.0, fs::Permissions::from_mode(0o700));
            let _ = fs::remove_dir_all(&self.0);
        }
    }

    fn file(text: &str) -> (Dir, std::path::PathBuf) {
        let dir =
            Dir(std::env::temp_dir().join(format!("orbit-aliases-{:016x}", rand::random::<u64>())));
        fs::create_dir(dir.path()).unwrap();
        let path = dir.path().join("config.yaml");
        fs::write(&path, text).unwrap();
        (dir, path)
    }

    fn desired_block(indent: usize) -> String {
        render(indent)
    }

    #[test]
    fn keeps_the_live_antigravity_values() {
        assert_eq!(edit(LIVE).unwrap(), None);
        let rendered = format!("{KEY}:\n{}", render(2));
        assert_eq!(edit(&rendered).unwrap(), None);
        for (name, alias) in ANTIGRAVITY {
            assert!(LIVE.contains(&format!("{{name: {name}, alias: {alias}, fork: true}}")));
        }
    }

    #[test]
    fn replaces_only_the_antigravity_lines_and_then_changes_nothing() {
        let (_dir, path) = file(ADOPTED);
        fs::set_permissions(&path, fs::Permissions::from_mode(0o640)).unwrap();
        let inode = fs::metadata(&path).unwrap().ino();
        assert!(converge(&path).unwrap());
        assert_eq!(fs::metadata(&path).unwrap().ino(), inode);
        let written = fs::read_to_string(&path).unwrap();
        let (before, after) = ADOPTED.split_once("  antigravity:\n").unwrap();
        let after = &after[after.find("  claude:").unwrap()..];
        assert_eq!(written, format!("{before}{}{after}", desired_block(2)));
        assert_eq!(
            fs::metadata(&path).unwrap().permissions().mode() & 0o777,
            0o640
        );
        assert!(!converge(&path).unwrap());
        assert_eq!(fs::read_to_string(&path).unwrap(), written);
    }

    #[test]
    fn adds_the_channel_or_the_key_when_missing() {
        let text = "port: 8317\noauth-model-alias:\n    codex:\n      - name: a\n        alias: b\n# tail\n";
        let edited = edit(text).unwrap().unwrap();
        assert_eq!(edited, format!("port: 8317\noauth-model-alias:\n    codex:\n      - name: a\n        alias: b\n{}# tail\n", desired_block(4)));
        assert_eq!(edit(&edited).unwrap(), None);
        let edited = edit("port: 8317").unwrap().unwrap();
        assert_eq!(edited, format!("port: 8317\n{KEY}:\n{}", desired_block(2)));
        assert_eq!(edit(&edited).unwrap(), None);
        let edited = edit("oauth-model-alias: {}\nport: 1\n").unwrap().unwrap();
        assert_eq!(edited, format!("{KEY}:\n{}port: 1\n", desired_block(2)));
    }

    #[test]
    fn refuses_a_form_it_cannot_edit_without_writing() {
        for text in [
            "oauth-model-alias: {codex: []}\n",
            "\"oauth-model-alias\" :\n",
            "oauth-model-alias:\n  antigravity: []\n  antigravity: []\n",
        ] {
            let (_dir, path) = file(text);
            assert!(converge(&path).is_err(), "{text}");
            assert_eq!(fs::read_to_string(&path).unwrap(), text);
        }
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

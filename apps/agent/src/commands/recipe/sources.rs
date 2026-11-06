//! APT source ownership, parsing, and durable retirement. Planning never publishes a file.
use super::{Failure, Files};
use regex::Regex;
use std::{
    collections::BTreeMap,
    fs, io,
    path::{Path, PathBuf},
};

pub type Fields = BTreeMap<String, String>;
fn expression(pattern: &str) -> Regex {
    Regex::new(pattern).expect("fixed source expression")
}

pub fn deb822(paragraph: &str, source: &Path) -> Result<Fields, Failure> {
    let mut fields = Fields::new();
    let mut previous: Option<String> = None;
    for (number, line) in paragraph.lines().enumerate() {
        if line.trim().is_empty() || line.starts_with('#') {
            continue;
        }
        let fail = |reason: &str| {
            Failure::io(
                "apt-source",
                source,
                format!("line {}: {reason}", number + 1),
            )
        };
        if line.starts_with(char::is_whitespace) {
            let Some(key) = &previous else {
                return Err(fail("continuation before a field"));
            };
            let field = fields.get_mut(key).expect("previous field exists");
            field.push(' ');
            field.push_str(line.trim());
            continue;
        }
        let Some((key, value)) = line.split_once(':') else {
            return Err(fail("missing field separator"));
        };
        let key = key.to_ascii_lowercase();
        if !expression(r"\A[a-z][a-z0-9-]*\z").is_match(&key) {
            return Err(fail("invalid field name"));
        }
        if fields.insert(key.clone(), value.trim().into()).is_some() {
            return Err(fail(&format!("duplicate field [{key}]")));
        }
        previous = Some(key);
    }
    Ok(fields)
}
fn words<'a>(fields: &'a Fields, key: &str) -> Vec<&'a str> {
    fields
        .get(key)
        .map(|s| s.split_whitespace().collect())
        .unwrap_or_default()
}
pub fn paths(files: &Files) -> Result<Vec<PathBuf>, Failure> {
    let mut result = vec![files.apt().join("sources.list")];
    let directory = files.apt().join("sources.list.d");
    let entries = match fs::read_dir(&directory) {
        Ok(entries) => entries,
        Err(e) if e.kind() == io::ErrorKind::NotFound => return Ok(result),
        Err(e) => return Err(Failure::io("apt-source", &directory, e)),
    };
    let mut sources = vec![];
    let mut lists = vec![];
    for entry in entries {
        let path = entry
            .map_err(|e| Failure::io("apt-source", &directory, e))?
            .path();
        match path.extension().and_then(|s| s.to_str()) {
            Some("sources") => sources.push(path),
            Some("list") => lists.push(path),
            _ => {}
        }
    }
    sources.sort();
    lists.sort();
    result.extend(sources);
    result.extend(lists);
    Ok(result)
}
pub fn read(path: &Path) -> Result<String, Failure> {
    fs::read_to_string(path).map_err(|e| Failure::io("apt-source", path, e))
}
pub fn backup(
    files: &Files,
    path: &Path,
    content: &[u8],
    owner: &str,
    check_only: bool,
) -> Result<PathBuf, Failure> {
    let name = path
        .file_name()
        .and_then(|s| s.to_str())
        .ok_or_else(|| Failure::io("apt-source", path, "No source filename"))?;
    let target = files.apt().join(format!("{name}.orbit-{owner}-backup"));
    match fs::symlink_metadata(&target) {
        Ok(meta) => {
            let reason = if meta.is_symlink() {
                Some("is a symlink")
            } else if !meta.is_file() {
                Some("is not a regular file")
            } else if fs::read(&target).map_err(|e| Failure::io("apt-source", &target, e))?
                != content
            {
                Some("differs from the source")
            } else {
                None
            };
            if let Some(reason) = reason {
                return Err(Failure::io(
                    "apt-source",
                    &target,
                    format!("{reason}; refusing to replace source [{}]", path.display()),
                ));
            }
        }
        Err(e) if e.kind() == io::ErrorKind::NotFound => {
            if !check_only {
                files.publish_root(&target, content)?;
            }
        }
        Err(e) => return Err(Failure::io("apt-source", &target, e)),
    }
    Ok(target)
}

const OWNER: &str = "node";
fn mentions(text: &str) -> bool {
    expression(r"deb\.nodesource\.com").is_match(text)
}
fn accepts(uris: &[&str], suites: &[&str], components: &[&str], types: &[&str]) -> bool {
    if uris.is_empty() || types.is_empty() || types.iter().any(|t| !["deb", "deb-src"].contains(t))
    {
        return false;
    }
    uris.iter().all(|uri| {
        let uri = uri.trim_end_matches('/');
        (10..27).any(|major| uri == format!("https://deb.nodesource.com/node_{major}.x"))
    }) && suites == ["nodistro"]
        && components == ["main"]
}
#[derive(Debug)]
pub struct Change {
    pub path: PathBuf,
    pub before: Vec<u8>,
    pub after: Vec<u8>,
}
pub fn provider_changes(files: &Files) -> Result<Vec<Change>, Failure> {
    let mut changes = vec![];
    for path in paths(files)? {
        if !path.is_file() {
            continue;
        }
        let content = read(&path)?;
        if !mentions(&content) {
            continue;
        }
        if path.is_symlink() {
            return Err(Failure::io(
                "apt-source",
                &path,
                "Provider source is a symlink; refusing to retire it",
            ));
        }
        let deb822_file = path.extension().is_some_and(|s| s == "sources");
        // Preserve both unrelated paragraphs and their original separators byte for byte.
        let mut blocks = vec![];
        if deb822_file {
            let mut offset = 0;
            for separator in expression(r"\n\s*\n").find_iter(&content) {
                blocks.push(&content[offset..separator.start()]);
                blocks.push(separator.as_str());
                offset = separator.end();
            }
            blocks.push(&content[offset..]);
        } else {
            blocks.extend(content.split_inclusive('\n'));
        }
        let mut replacement = String::new();
        for block in blocks {
            let active = block
                .lines()
                .filter(|line| !line.trim_start().starts_with('#'))
                .collect::<Vec<_>>()
                .join("\n");
            if !mentions(&active) {
                replacement.push_str(block);
                continue;
            }
            let (uris, suites, components, types): (
                Vec<String>,
                Vec<String>,
                Vec<String>,
                Vec<String>,
            );
            if deb822_file {
                let fields = deb822(block, &path)?;
                let own = |key| {
                    words(&fields, key)
                        .iter()
                        .map(|s| (*s).to_string())
                        .collect()
                };
                uris = own("uris");
                suites = own("suites");
                components = own("components");
                types = own("types");
            } else {
                let pattern = expression(
                    r"\A\s*(deb(?:-src)?)\s+(?:\[[^]]+\]\s+)?(\S+)\s+(\S+)\s+([^#]+?)(?:\s*#.*)?\s*\z",
                );
                let entry = pattern.captures(block).ok_or_else(|| {
                    Failure::io("apt-source", &path, "Invalid deb/deb-src list entry")
                })?;
                types = vec![entry[1].into()];
                uris = vec![entry[2].into()];
                suites = vec![entry[3].into()];
                components = entry[4].split_whitespace().map(str::to_string).collect();
            }
            fn refs(v: &[String]) -> Vec<&str> {
                v.iter().map(String::as_str).collect()
            }
            if !accepts(
                &refs(&uris),
                &refs(&suites),
                &refs(&components),
                &refs(&types),
            ) {
                return Err(Failure::io("apt-source", &path, format!("Unsupported or mixed provider fields: URIs={uris:?}, Suites={suites:?}, Components={components:?}, Types={types:?}")));
            }
        }
        if replacement != content {
            backup(files, &path, content.as_bytes(), OWNER, true)?;
            changes.push(Change {
                path,
                before: content.into_bytes(),
                after: replacement.into_bytes(),
            });
        }
    }
    Ok(changes)
}
pub fn retire(files: &Files, changes: &[Change]) -> Result<(), Failure> {
    // Preflight the complete set before touching any source.
    for change in changes {
        backup(files, &change.path, &change.before, OWNER, true)?;
        if change.path.is_symlink()
            || fs::read(&change.path).map_err(|e| Failure::io("apt-source", &change.path, e))?
                != change.before
        {
            return Err(Failure::io(
                "apt-source",
                &change.path,
                "Source changed since inspection",
            ));
        }
    }
    for change in changes {
        backup(files, &change.path, &change.before, OWNER, false)?;
        if change.after.iter().all(u8::is_ascii_whitespace) {
            fs::remove_file(&change.path)
                .map_err(|e| Failure::io("apt-source", &change.path, e))?;
        } else {
            files.publish_root(&change.path, &change.after)?;
        }
    }
    Ok(())
}
#[cfg(test)]
mod tests;

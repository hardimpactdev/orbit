//! APT source ownership, parsing, and durable retirement. Planning never publishes a file.
use super::{Failure, Files};
use regex::Regex;
use std::{
    collections::{BTreeMap, BTreeSet},
    fs, io,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
};

pub const UBUNTU_KEYRING: &str = "/usr/share/keyrings/ubuntu-archive-keyring.gpg";
pub type Fields = BTreeMap<String, String>;
pub type SignedSources = BTreeSet<(String, String)>;
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
pub fn signed_sources(files: &Files, source: Option<&Path>) -> Result<SignedSources, Failure> {
    let mut trusted = SignedSources::new();
    let candidates = match source {
        Some(path) => vec![path.to_owned()],
        None => paths(files)?,
    };
    for path in candidates {
        if !path.is_file() {
            continue;
        }
        let content = read(&path)?;
        if path.extension().is_some_and(|s| s == "sources") {
            for paragraph in expression(r"\n\s*\n").split(&content) {
                let fields = deb822(paragraph, &path)?;
                if fields.get("signed-by").map(String::as_str) != Some(UBUNTU_KEYRING)
                    || !words(&fields, "types").contains(&"deb")
                    || fields
                        .get("enabled")
                        .is_some_and(|v| v.eq_ignore_ascii_case("no"))
                {
                    continue;
                }
                for uri in words(&fields, "uris") {
                    for suite in words(&fields, "suites") {
                        for component in words(&fields, "components") {
                            trusted.insert((
                                uri.trim_end_matches('/').into(),
                                format!("{suite}/{component}"),
                            ));
                        }
                    }
                }
            }
        } else {
            let pattern = expression(r"\A\s*deb\s+\[([^]]+)\]\s+(\S+)\s+(\S+)\s+([^#]+)(?:#.*)?\z");
            for line in content.lines() {
                let Some(entry) = pattern.captures(line) else {
                    continue;
                };
                if !entry[1]
                    .split_whitespace()
                    .any(|field| field == format!("signed-by={UBUNTU_KEYRING}"))
                {
                    continue;
                }
                for component in entry[4].split_whitespace() {
                    trusted.insert((
                        entry[2].trim_end_matches('/').into(),
                        format!("{}/{component}", &entry[3]),
                    ));
                }
            }
        }
    }
    Ok(trusted)
}
pub fn snapshot_source(snapshot: &str) -> String {
    format!("Types: deb\nURIs: https://snapshot.ubuntu.com/ubuntu/{snapshot}/\nSuites: resolute resolute-updates resolute-security\nComponents: main universe restricted multiverse\nSigned-By: {UBUNTU_KEYRING}\nCheck-Valid-Until: no\n")
}
pub fn rolling_source(architecture: &str) -> String {
    let (archive, security) = if architecture == "arm64" {
        (
            "http://ports.ubuntu.com/ubuntu-ports",
            "http://ports.ubuntu.com/ubuntu-ports",
        )
    } else {
        (
            "http://archive.ubuntu.com/ubuntu",
            "http://security.ubuntu.com/ubuntu",
        )
    };
    format!("Types: deb\nURIs: {archive}\nSuites: resolute resolute-updates resolute-backports\nComponents: main universe restricted multiverse\nSigned-By: {UBUNTU_KEYRING}\n\nTypes: deb\nURIs: {security}\nSuites: resolute-security\nComponents: main universe restricted multiverse\nSigned-By: {UBUNTU_KEYRING}\n")
}
pub fn ubuntu_repair(files: &Files) -> Result<Option<Vec<u8>>, Failure> {
    let path = files.apt().join("sources.list.d/ubuntu.sources");
    if !path.is_file() || path.is_symlink() {
        return Ok(None);
    }
    let bytes = fs::read(&path).map_err(|e| Failure::io("apt-source", &path, e))?;
    let Ok(content) = std::str::from_utf8(&bytes) else {
        return Ok(None);
    };
    let pattern =
        expression(r"\ATypes: deb\nURIs: https://snapshot\.ubuntu\.com/ubuntu/(\d{8}T\d{6}Z)/\n");
    Ok(pattern
        .captures(content)
        .filter(|m| content == snapshot_source(&m[1]))
        .map(|_| bytes.clone()))
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

#[derive(Clone, Copy)]
pub enum Provider {
    Git,
    Docker,
    Node,
    Incus,
}
impl Provider {
    pub fn owner(self) -> &'static str {
        match self {
            Self::Git => "git",
            Self::Docker => "docker",
            Self::Node => "node",
            Self::Incus => "incus",
        }
    }
    fn mentions(self, text: &str) -> bool {
        expression(match self {
            Self::Git => r"(?:ppa\.launchpadcontent\.net|ppa\.launchpad\.net)/git-core/ppa",
            Self::Docker => r"download\.docker\.com",
            Self::Node => r"deb\.nodesource\.com",
            Self::Incus => r"pkgs\.zabbly\.com/incus",
        })
        .is_match(text)
    }
    fn accepts(self, uris: &[&str], suites: &[&str], components: &[&str], types: &[&str]) -> bool {
        if uris.is_empty()
            || suites.len() != 1
            || types.is_empty()
            || types.iter().any(|t| !["deb", "deb-src"].contains(t))
        {
            return false;
        }
        let uri_ok = uris.iter().all(|uri| {
            let uri = uri.trim_end_matches('/');
            match self {
                Self::Git => ["http", "https"].iter().any(|s| {
                    ["ppa.launchpad.net", "ppa.launchpadcontent.net"]
                        .iter()
                        .any(|h| uri == format!("{s}://{h}/git-core/ppa/ubuntu"))
                }),
                Self::Docker => uri == "https://download.docker.com/linux/ubuntu",
                Self::Incus => uri == "https://pkgs.zabbly.com/incus/lts-7.0",
                Self::Node => (10..27)
                    .any(|major| uri == format!("https://deb.nodesource.com/node_{major}.x")),
            }
        });
        uri_ok
            && match self {
                Self::Git | Self::Incus => suites == ["resolute"] && components == ["main"],
                Self::Node => suites == ["nodistro"] && components == ["main"],
                Self::Docker => {
                    expression(r"\A[a-z][a-z0-9-]*\z").is_match(suites[0])
                        && components == ["stable"]
                }
            }
    }
}
#[derive(Debug)]
pub struct Change {
    pub path: PathBuf,
    pub before: Vec<u8>,
    pub after: Vec<u8>,
}
pub fn provider_changes(files: &Files, provider: Provider) -> Result<Vec<Change>, Failure> {
    let mut changes = vec![];
    for path in paths(files)? {
        if !path.is_file() {
            continue;
        }
        let content = read(&path)?;
        if !provider.mentions(&content) {
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
            if !provider.mentions(&active) {
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
            if !provider.accepts(
                &refs(&uris),
                &refs(&suites),
                &refs(&components),
                &refs(&types),
            ) {
                return Err(Failure::io("apt-source", &path, format!("Unsupported or mixed provider fields: URIs={uris:?}, Suites={suites:?}, Components={components:?}, Types={types:?}")));
            }
        }
        if replacement != content {
            backup(files, &path, content.as_bytes(), provider.owner(), true)?;
            changes.push(Change {
                path,
                before: content.into_bytes(),
                after: replacement.into_bytes(),
            });
        }
    }
    Ok(changes)
}
pub fn retire(files: &Files, changes: &[Change], owner: &str) -> Result<(), Failure> {
    // Preflight the complete set before touching any source.
    for change in changes {
        backup(files, &change.path, &change.before, owner, true)?;
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
        backup(files, &change.path, &change.before, owner, false)?;
        if change.after.iter().all(u8::is_ascii_whitespace) {
            fs::remove_file(&change.path)
                .map_err(|e| Failure::io("apt-source", &change.path, e))?;
        } else {
            files.publish_root(&change.path, &change.after)?;
        }
    }
    Ok(())
}
pub fn artifact_owned(
    files: &Files,
    name: &str,
    version: &str,
    names: &BTreeSet<String>,
    owner: &str,
) -> bool {
    let path = files.apt().join(format!("orbit-{owner}.json"));
    let Ok(meta) = fs::symlink_metadata(&path) else {
        return false;
    };
    if !meta.is_file() || meta.uid() != files.uid || meta.mode() & 0o022 != 0 {
        return false;
    }
    let Ok(bytes) = fs::read(&path) else {
        return false;
    };
    let Ok(receipt) = serde_json::from_slice::<BTreeMap<String, String>>(&bytes) else {
        return false;
    };
    receipt.keys().cloned().collect::<BTreeSet<_>>() == *names
        && receipt.get(name).is_some_and(|v| v == version)
        && receipt
            .values()
            .all(|v| v == version && expression(r"\A[0-9][0-9A-Za-z.+:~_-]*\z").is_match(v))
}

#[cfg(test)]
mod tests;

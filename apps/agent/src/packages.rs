//! Installed packages per Tool Manager, read from each manager's own state files and never by running the manager
//! (Node agent observer pass). One `packages` fact per manager, named by the manager in `subject`.

use base64::Engine;
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;
use std::path::Path;

/// A state or key file larger than this is left out.
const FILE_BYTES: u64 = 65_536;
const MAX_FILES: usize = 256;

/// Installed packages in dpkg's status database: each `install ok installed` stanza, by name, with its version.
pub fn apt_packages(status: &str) -> Vec<Value> {
    let mut packages = BTreeMap::new();
    for stanza in status.split("\n\n") {
        let field = |key: &str| {
            stanza.lines().find_map(|line| {
                line.strip_prefix(key)
                    .and_then(|rest| rest.strip_prefix(": "))
            })
        };
        if let (Some(name), Some("install ok installed"), Some(version)) =
            (field("Package"), field("Status"), field("Version"))
        {
            packages
                .entry(name.to_owned())
                .or_insert_with(|| version.to_owned());
        }
    }
    packages
        .into_iter()
        .map(|(name, version)| json!({"name": name, "version": version}))
        .collect()
}

/// The apt fact: installed packages, the source files and the public signing keys, which the Gateway compares with a
/// Tool's repository.
pub fn apt() -> Value {
    let status = std::fs::read_to_string("/var/lib/dpkg/status").unwrap_or_default();
    let mut sources = small_files(Path::new("/etc/apt/sources.list.d"), &[".list", ".sources"]);
    if let Some(main) = small_file(Path::new("/etc/apt/sources.list")) {
        sources.insert(0, main);
    }
    let mut keys = small_files(Path::new("/etc/apt/keyrings"), &[".gpg", ".asc"]);
    keys.extend(small_files(
        Path::new("/usr/share/keyrings"),
        &[".gpg", ".asc"],
    ));
    keys.truncate(MAX_FILES);
    json!({"packages": apt_packages(&status), "sources": sources, "keys": keys})
}

/// One regular file of at most 64 KiB: its path, sha256 and content as base64.
fn small_file(path: &Path) -> Option<Value> {
    let metadata = std::fs::symlink_metadata(path).ok()?;
    if !metadata.is_file() || metadata.len() > FILE_BYTES {
        return None;
    }
    let bytes = std::fs::read(path).ok()?;
    Some(json!({
        "path": path.to_string_lossy(),
        "sha256": format!("{:x}", Sha256::digest(&bytes)),
        "content": base64::engine::general_purpose::STANDARD.encode(&bytes),
    }))
}

/// The regular files directly in `dir` whose names end with one of `suffixes`, sorted.
fn small_files(dir: &Path, suffixes: &[&str]) -> Vec<Value> {
    let Ok(entries) = std::fs::read_dir(dir) else {
        return Vec::new();
    };
    let mut paths: Vec<_> = entries
        .flatten()
        .map(|entry| entry.path())
        .filter(|path| {
            let name = path
                .file_name()
                .map(|name| name.to_string_lossy().into_owned())
                .unwrap_or_default();
            suffixes.iter().any(|suffix| name.ends_with(suffix))
        })
        .collect();
    paths.sort();
    paths
        .iter()
        .filter_map(|path| small_file(path))
        .take(MAX_FILES)
        .collect()
}

/// Composer's global scope: the root packages it requires and every installed package with its version.
pub fn composer(scope: &Path) -> Value {
    let read = |name: &str| {
        std::fs::read_to_string(scope.join(name))
            .ok()
            .and_then(|text| serde_json::from_str::<Value>(&text).ok())
    };
    let roots: Vec<String> = read("composer.json")
        .and_then(|manifest| manifest.get("require").and_then(Value::as_object).cloned())
        .map(|require| require.keys().cloned().collect())
        .unwrap_or_default();
    let installed = read("vendor/composer/installed.json").unwrap_or(Value::Null);
    let rows = installed
        .get("packages")
        .or(Some(&installed))
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default();
    json!({"roots": roots, "packages": named_versions(&rows, "name", "version")})
}

/// Vite+ global packages, one record per command in `bins/`, by package name.
pub fn vp(home: &Path) -> Value {
    let rows: Vec<Value> = std::fs::read_dir(home.join("bins"))
        .map(|entries| {
            entries
                .flatten()
                .filter(|entry| entry.file_name().to_string_lossy().ends_with(".json"))
                .filter_map(|entry| small_text(&entry.path()))
                .filter_map(|text| serde_json::from_str::<Value>(&text).ok())
                .filter(|row| row.get("source").and_then(Value::as_str) == Some("vp"))
                .collect()
        })
        .unwrap_or_default();
    json!({"packages": named_versions(&rows, "package", "version")})
}

/// Homebrew formulae from their install receipts, and casks from the Caskroom, each with its installed versions.
pub fn brew(prefix: &Path) -> Value {
    let mut formulae = Vec::new();
    for (name, versions) in versions(&prefix.join("Cellar")) {
        let receipt = versions
            .last()
            .and_then(|version| {
                small_text(
                    &prefix
                        .join("Cellar")
                        .join(&name)
                        .join(version)
                        .join("INSTALL_RECEIPT.json"),
                )
            })
            .and_then(|text| serde_json::from_str::<Value>(&text).ok())
            .unwrap_or(Value::Null);
        let dependencies: Vec<Value> = receipt
            .get("runtime_dependencies")
            .and_then(Value::as_array)
            .map(|rows| {
                rows.iter()
                    .filter_map(|row| row.get("full_name").cloned())
                    .collect()
            })
            .unwrap_or_default();
        formulae.push(json!({
            "name": name,
            "versions": versions,
            "tap": receipt.pointer("/source/tap"),
            "on_request": receipt.get("installed_on_request"),
            "dependencies": dependencies,
        }));
    }
    let casks: Vec<Value> = versions(&prefix.join("Caskroom"))
        .into_iter()
        .map(|(name, versions)| json!({"name": name, "versions": versions}))
        .collect();
    json!({"formulae": formulae, "casks": casks})
}

/// The `packages` fact of one manager from the first candidate path that exists, or None for an unknown manager or
/// when no candidate exists.
pub fn observe(manager: &str, candidates: &[String]) -> Option<Value> {
    // A Vite+ or Homebrew store counts only with its program, as the manager's own scan chooses it; a cache-only
    // `~/.vite-plus` is not a store.
    let program = match manager {
        "vp" => Some("bin/vp"),
        "brew" => Some("bin/brew"),
        _ => None,
    };
    let scope = candidates
        .iter()
        .map(Path::new)
        .find(|path| program.map_or(path.exists(), |program| path.join(program).is_file()))?;
    let mut data = match manager {
        "apt" => apt(),
        "composer" => composer(scope),
        "vp" => vp(scope),
        "brew" => brew(scope),
        _ => return None,
    };
    data["scope"] = json!(scope.to_string_lossy());
    Some(data)
}

/// `<root>/<name>/<version>` directories as name → sorted versions.
fn versions(root: &Path) -> BTreeMap<String, Vec<String>> {
    let mut found = BTreeMap::new();
    for entry in std::fs::read_dir(root).into_iter().flatten().flatten() {
        if !entry.file_type().is_ok_and(|kind| kind.is_dir()) {
            continue;
        }
        let mut list: Vec<String> = std::fs::read_dir(entry.path())
            .into_iter()
            .flatten()
            .flatten()
            .filter(|version| version.file_type().is_ok_and(|kind| kind.is_dir()))
            .map(|version| version.file_name().to_string_lossy().into_owned())
            .filter(|version| !version.starts_with('.'))
            .collect();
        list.sort();
        if !list.is_empty() {
            found.insert(entry.file_name().to_string_lossy().into_owned(), list);
        }
    }
    found
}

fn small_text(path: &Path) -> Option<String> {
    let metadata = std::fs::symlink_metadata(path).ok()?;
    (metadata.is_file() && metadata.len() <= 1_048_576)
        .then(|| std::fs::read_to_string(path).ok())?
}

/// `{name, version}` rows from JSON rows, by name, the first one of a name kept.
fn named_versions(rows: &[Value], name: &str, version: &str) -> Vec<Value> {
    let mut packages = BTreeMap::new();
    for row in rows {
        if let (Some(name), Some(version)) = (
            row.get(name).and_then(Value::as_str),
            row.get(version).and_then(Value::as_str),
        ) {
            packages
                .entry(name.to_owned())
                .or_insert_with(|| version.to_owned());
        }
    }
    packages
        .into_iter()
        .map(|(name, version)| json!({"name": name, "version": version}))
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn apt_keeps_installed_packages_once_by_name() {
        let status = "Package: jq\nStatus: install ok installed\nVersion: 1.7\n\nPackage: gone\nStatus: deinstall ok config-files\nVersion: 1\n\nPackage: libc6\nStatus: install ok installed\nArchitecture: amd64\nVersion: 2.39\n\nPackage: libc6\nStatus: install ok installed\nArchitecture: i386\nVersion: 2.39\n";

        assert_eq!(
            apt_packages(status),
            vec![
                json!({"name": "jq", "version": "1.7"}),
                json!({"name": "libc6", "version": "2.39"})
            ]
        );
    }

    fn dir(name: &str) -> std::path::PathBuf {
        let path =
            std::env::temp_dir().join(format!("orbit-packages-{name}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&path);
        std::fs::create_dir_all(&path).unwrap();
        path
    }

    #[test]
    fn composer_reports_roots_and_installed_versions() {
        let scope = dir("composer");
        std::fs::create_dir_all(scope.join("vendor/composer")).unwrap();
        std::fs::write(
            scope.join("composer.json"),
            r#"{"require":{"laravel/installer":"^5"}}"#,
        )
        .unwrap();
        std::fs::write(scope.join("vendor/composer/installed.json"),
            r#"{"packages":[{"name":"laravel/installer","version":"v5.32.0"},{"name":"symfony/console","version":"v7.3.0"}]}"#).unwrap();

        assert_eq!(
            composer(&scope),
            json!({"roots": ["laravel/installer"], "packages": [
            {"name": "laravel/installer", "version": "v5.32.0"}, {"name": "symfony/console", "version": "v7.3.0"}]})
        );
        std::fs::remove_dir_all(&scope).unwrap();
    }

    #[test]
    fn vp_reports_each_global_package_once() {
        let home = dir("vp");
        std::fs::create_dir_all(home.join("bins")).unwrap();
        std::fs::write(home.join("bins/claude.json"), r#"{"name":"claude","package":"@anthropic-ai/claude-code","version":"2.1.286","source":"vp"}"#).unwrap();
        std::fs::write(
            home.join("bins/node.json"),
            r#"{"name":"node","package":"node","version":"24","source":"runtime"}"#,
        )
        .unwrap();

        assert_eq!(
            vp(&home),
            json!({"packages": [{"name": "@anthropic-ai/claude-code", "version": "2.1.286"}]})
        );
        std::fs::remove_dir_all(&home).unwrap();
    }

    #[test]
    fn a_vp_store_counts_only_with_its_program() {
        let cache = dir("vp-cache");
        let store = dir("vp-store");
        std::fs::create_dir_all(store.join("bin")).unwrap();
        std::fs::write(store.join("bin/vp"), "").unwrap();
        let candidates = [
            cache.to_string_lossy().into_owned(),
            store.to_string_lossy().into_owned(),
        ];

        assert_eq!(
            observe("vp", &candidates).unwrap()["scope"],
            json!(store.to_string_lossy())
        );
        assert!(observe("unknown", &candidates).is_none());
        std::fs::remove_dir_all(&cache).unwrap();
        std::fs::remove_dir_all(&store).unwrap();
    }

    #[test]
    fn brew_reads_formula_receipts_and_casks() {
        let prefix = dir("brew");
        std::fs::create_dir_all(prefix.join("Cellar/jq/1.8.2")).unwrap();
        std::fs::write(prefix.join("Cellar/jq/1.8.2/INSTALL_RECEIPT.json"),
            r#"{"installed_on_request":true,"source":{"tap":"homebrew/core"},"runtime_dependencies":[{"full_name":"oniguruma"}]}"#).unwrap();
        std::fs::create_dir_all(prefix.join("Caskroom/firefox/140.0")).unwrap();

        assert_eq!(
            brew(&prefix),
            json!({
                "formulae": [{"name": "jq", "versions": ["1.8.2"], "tap": "homebrew/core", "on_request": true, "dependencies": ["oniguruma"]}],
                "casks": [{"name": "firefox", "versions": ["140.0"]}],
            })
        );
        std::fs::remove_dir_all(&prefix).unwrap();
    }
}

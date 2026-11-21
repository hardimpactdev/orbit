//! `instance.prepare`: a stopped copy's files before its first start, so its first boot already has them.

use std::collections::BTreeSet;

use serde::Deserialize;
use serde_json::Value;

use crate::{Failure, Incus, System};

/// The one Node file a copy may trust: the Orbit root the Node itself pins.
const TRUST_SOURCES: [&str; 1] = ["/etc/orbit/agent/ca.pem"];
const OPENSSL: &str = "/usr/bin/openssl";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct PrepareInput {
    project: String,
    name: String,
    /// The Process units and ids the copy keeps; every other Orbit Process, Schedule and Node service unit of the seed goes.
    #[serde(default)]
    units: Vec<String>,
    #[serde(default)]
    process_ids: Vec<String>,
    /// Placed in order. The file T3's unit waits for comes last.
    #[serde(default)]
    files: Vec<Item>,
    /// The same list as a blob: a clone's files are kilobytes, and an order holds at most 4 KB.
    #[serde(default)]
    files_blob: Option<crate::BlobRef>,
    /// The app-account swap file: when the seed holds it as this create renders it, the seed's own swap already ran.
    #[serde(default)]
    app_account: Option<String>,
}

/// What the default's seed holds (`swamp/ansible/instance_seed_files.py`): the state of the files every clone needs, the default's
/// Orbit units that no clone keeps, and whether its workspace is clean. Absent in a seed taken before it.
const MANIFEST: &str = "/etc/orbit/seed-manifest.json";

/// One thing to place in the copy.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields, rename_all = "snake_case")]
enum Item {
    /// A whole file. `content` is inline text, or `blob` a content reference for a secret.
    File {
        path: String,
        uid: u32,
        gid: u32,
        mode: String,
        #[serde(default)]
        content: Option<String>,
        #[serde(default)]
        blob: Option<crate::BlobRef>,
        /// Missing folders above the file are made for its owner: 0755 for root, 0700 for anyone else.
        #[serde(default)]
        parents: bool,
    },
    /// A JSON file merged over the copy's own, object by object: an entry Orbit does not name, such as a provider instance
    /// of the agent's own, stays. Each string equal to `key_value` holds the placeholder model `key`.
    Json {
        path: String,
        uid: u32,
        gid: u32,
        mode: String,
        json: Value,
        #[serde(default)]
        key_value: Option<String>,
        #[serde(default)]
        key: Option<String>,
    },
    /// A symbolic link, replaced when it exists. With an owner, missing folders above it are made for that user first.
    Link {
        path: String,
        target: String,
        #[serde(default)]
        owner: Option<Owner>,
    },
    /// The Orbit root from `source`, linked by its name and its OpenSSL hash in `hash_directory`, and appended to that
    /// folder's bundle once. A link to an older root under another hash goes.
    Trust { source: String, path: String, hash_directory: String, uid: u32, gid: u32, mode: String },
    /// A folder made again with exactly these files, owned by root (files 0644, folders 0755), and an empty `.skills`
    /// folder: the agent instructions a Project gives its clones. A file the Project removed is gone.
    Folder { path: String, files: Vec<FolderFile> },
    /// An entry in a user's NSS store, when the user's home exists in the copy and the trust module's library is the
    /// version the entry needs.
    Nss {
        home: String,
        directories: Vec<String>,
        path: String,
        entry: String,
        uid: u32,
        gid: u32,
        mode: String,
        directory_mode: String,
        library: String,
        version: String,
    },
}

/// One file of a [`Item::Folder`], by its path inside the folder.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct FolderFile {
    path: String,
    /// The file's content inline, inside a `files_blob`, or a blob of its own.
    #[serde(default)]
    content: Option<String>,
    #[serde(default)]
    blob: Option<crate::BlobRef>,
}

/// The user and group that own a link's missing folders.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Owner {
    uid: u32,
    gid: u32,
}

/// Places a stopped copy's files: the seed's Orbit units that are not the copy's go, the machine id is the copy's own, then
/// each item in order. A running copy is left alone: its first boot is over. The seed's manifest, read once, names the units to
/// remove and what the seed already holds: an item the seed holds as rendered is not placed again.
/// Answers `{"changes", "manifest", "app_account_current", "workspace_clean", "fleet_tunnel"}`: whether the seed's manifest was
/// there, whether its app-account swap ran with this create's swap file, whether its workspace was clean, and whether it holds
/// Orbit's operator tunnel boot unit, so a nested fleet's copy has its tunnel at every boot (`instance.fleet_ready`). A running copy
/// answers `false` for the unit: its first boot is over.
///
/// # Errors
///
/// `instance.prepare_input_invalid`, `instance.clone_trust_unavailable`, `instance.clone_trust_bundle_refused`,
/// `instance.clone_browser_trust_refused`, `instance.clone_browser_trust_mismatch`, `instance.clone_files_refused`,
/// `instance.agent_folder_refused`,
/// `schedule.container_clone_cleanup_failed` or `instance.prepare_failed`.
pub fn prepare(system: &impl System, input: &Value) -> Result<String, Failure> {
    let mut input: PrepareInput =
        serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.prepare_input_invalid", error.to_string()))?;
    if let Some(blob) = input.files_blob.take() {
        if !input.files.is_empty() {
            return Err(Failure::new("instance.prepare_input_invalid", "files and files_blob both given"));
        }
        let bytes = system.blob(&blob.blob.sha256).map_err(|detail| Failure::new("instance.prepare_failed", detail))?;
        input.files = serde_json::from_slice(&bytes).map_err(|error| Failure::new("instance.prepare_input_invalid", error.to_string()))?;
    }
    check(&input).map_err(|detail| Failure::new("instance.prepare_input_invalid", detail))?;
    let incus = Incus { system, code: "instance.prepare_failed" };
    let copy = incus
        .query(&format!("/1.0/instances/{}?project={}", input.name, input.project))?
        .ok_or_else(|| Failure::new("instance.prepare_failed", "the copy does not exist"))?;
    let files = Files { incus: &incus, project: &input.project, name: &input.name };
    let answer = |changes: u32, manifest: Option<&Value>, swapped: bool, tunnel: bool| {
        let clean = manifest.and_then(|manifest| manifest.get("workspace_clean")).and_then(Value::as_bool).unwrap_or(false);
        serde_json::json!({"changes": changes, "manifest": manifest.is_some(), "app_account_current": swapped, "workspace_clean": clean,
            "fleet_tunnel": tunnel})
        .to_string()
    };
    if copy.get("status").and_then(Value::as_str) != Some("Stopped") {
        return Ok(answer(0, None, false, false));
    }
    let manifest: Option<Value> = files.pull(MANIFEST)?.and_then(|bytes| serde_json::from_slice(&bytes).ok());
    let mut changes = match manifest.as_ref().and_then(|manifest| manifest.get("units")).and_then(Value::as_array) {
        Some(listed) => remove(&files, &input, listed)?,
        None => purge(&files, &input)?,
    };
    let uuid = copy.pointer("/config/volatile.uuid").and_then(Value::as_str).unwrap_or_default().replace('-', "");
    if uuid.len() != 32 {
        return Err(Failure::new("instance.prepare_failed", "the copy has no volatile.uuid"));
    }
    files.push("/etc/machine-id", 0, 0, "0444", format!("{uuid}\n").as_bytes(), Folders::Present)?;
    changes += 1;
    let mut swapped = manifest.is_some();
    for item in &input.files {
        if manifest.as_ref().is_some_and(|manifest| held(system, manifest, item)) {
            continue;
        }
        if let (Item::File { path, .. }, Some(swap)) = (item, &input.app_account) {
            // The seed's swap ran with another file: this create's swap runs once more.
            swapped &= path != swap;
        }
        changes += place(system, &files, item)?;
    }
    Ok(answer(changes, manifest.as_ref(), swapped && input.app_account.is_some(), fleet_tunnel(&files)?))
}

/// Whether the copy holds Orbit's operator tunnel boot unit: the unit with Orbit's marker, which Orbit writes enabled and the purge
/// keeps. A hand-made unit of the same name, and a seed taken before the unit, has none. `instance.fleet_ready` checks its link.
fn fleet_tunnel(files: &Files<'_, impl System>) -> Result<bool, Failure> {
    let content = files.pull(&format!("/etc/systemd/system/{}", crate::fleet::TUNNEL_UNIT))?;
    Ok(content.is_some_and(|bytes| String::from_utf8_lossy(&bytes).starts_with(crate::fleet::MARKER)))
}

/// The seed's Orbit units that the manifest names, less the copy's own.
#[expect(clippy::case_sensitive_file_extension_comparisons, reason = "systemd folder names are lowercase")]
fn remove(files: &Files<'_, impl System>, input: &PrepareInput, listed: &[Value]) -> Result<u32, Failure> {
    let (mut links, mut rest) = (Vec::new(), Vec::new());
    for path in listed.iter().filter_map(Value::as_str) {
        let (folder, name) = path.rsplit_once('/').unwrap_or_default();
        // The operator tunnel's boot unit is the seed's, for every copy: it stays (instance.fleet_ready).
        let own = input.units.iter().any(|unit| unit == name)
            || (folder == "/etc/orbit/processes" && input.process_ids.iter().any(|id| id == name))
            || name == crate::fleet::TUNNEL_UNIT;
        if own || !path.starts_with("/etc/") || path.contains("..") {
            continue;
        }
        let target = format!("{}{path}", input.name);
        if folder.ends_with(".wants") { links.push(target) } else { rest.push(target) }
    }
    let count = u32::try_from(links.len() + rest.len()).unwrap_or(u32::MAX);
    if !links.is_empty() {
        files.incus.change(&[&["--project", input.project.as_str(), "file", "delete"][..], &links.iter().map(String::as_str).collect::<Vec<_>>()].concat())?;
    }
    if !rest.is_empty() {
        files.incus.change(&[&["--project", input.project.as_str(), "file", "delete", "--force"][..], &rest.iter().map(String::as_str).collect::<Vec<_>>()].concat())?;
    }
    Ok(count)
}

/// Whether the seed holds an item as this create renders it, by the manifest alone.
fn held(system: &impl System, manifest: &Value, item: &Item) -> bool {
    let file = |path: &str, bytes: &[u8], uid: u32, gid: u32, mode: &str| {
        manifest.pointer(&format!("/files/{}", pointer(path)))
            == Some(&serde_json::json!({"sha256": crate::sha256(bytes), "uid": uid, "gid": gid, "mode": four(mode)}))
    };
    let link = |path: &str, target: &str| manifest.pointer(&format!("/links/{}", pointer(path))).and_then(Value::as_str) == Some(target);
    match item {
        Item::File { path, uid, gid, mode, content: Some(content), .. } => file(path, content.as_bytes(), *uid, *gid, mode),
        Item::File { path, uid, gid, mode, blob: Some(blob), .. } => system.blob(&blob.blob.sha256).is_ok_and(|bytes| file(path, &bytes, *uid, *gid, mode)),
        Item::Link { path, target, .. } => link(path, target),
        Item::Folder { path, files } => {
            let Some(held) = manifest.pointer(&format!("/folders/{}", pointer(path))) else { return false };
            let wanted: Option<serde_json::Map<String, Value>> =
                files.iter().map(|entry| entry.content.as_ref().map(|content| (entry.path.clone(), Value::String(crate::sha256(content.as_bytes()))))).collect();
            held.get("skills") == Some(&Value::Bool(true)) && wanted.is_some_and(|wanted| held.get("files") == Some(&Value::Object(wanted)))
        }
        Item::Trust { source, path, hash_directory, uid, gid, mode } => {
            let Ok(content) = system.read(source) else { return false };
            let Some(hash) = subject_hash(system, &content) else { return false };
            let named = format!("{hash_directory}/{}.pem", path.rsplit('/').next().unwrap_or_default().trim_end_matches(".crt"));
            let bodies: BTreeSet<String> = certificates(&String::from_utf8_lossy(&content)).iter().map(|body| crate::sha256(body.as_bytes())).collect();
            let bundle = manifest.pointer(&format!("/bundles/{}", pointer(&format!("{hash_directory}/ca-certificates.crt")))).and_then(Value::as_array);
            file(path, &content, *uid, *gid, mode)
                && link(&named, path)
                && link(&format!("{hash_directory}/{hash}.0"), &named)
                && bundle.is_some_and(|held| bodies.iter().all(|body| held.iter().any(|value| value.as_str() == Some(body.as_str()))))
        }
        Item::Nss { path, entry, version, .. } => manifest.pointer(&format!("/nss/{}", pointer(path))).is_some_and(|held| {
            held.get("version").and_then(Value::as_str) == Some(version.as_str())
                && held.get("lines").and_then(Value::as_array).is_some_and(|lines| lines.iter().any(|line| line.as_str() == entry.lines().next()))
        }),
        _ => false,
    }
}

/// A path as one JSON pointer token.
fn pointer(path: &str) -> String {
    path.replace('~', "~0").replace('/', "~1")
}

fn check(input: &PrepareInput) -> Result<(), String> {
    crate::name(&input.project).then_some(()).ok_or("project is not a name")?;
    crate::name(&input.name).then_some(()).ok_or("name is not a name")?;
    let path = |value: &str| value.starts_with('/') && !value.split('/').any(|part| part == "..") && !value.bytes().any(|b| b.is_ascii_control());
    let mode = |value: &str| (3..=4).contains(&value.len()) && value.bytes().all(|b| (b'0'..=b'7').contains(&b));
    for item in &input.files {
        let (paths, modes): (Vec<&str>, Vec<&str>) = match item {
            Item::File { path, mode, content, blob, .. } => {
                if content.is_some() == blob.is_some() {
                    return Err(format!("{path} needs content or a blob"));
                }
                (vec![path], vec![mode])
            }
            Item::Json { path, mode, json, .. } => {
                if !json.is_object() {
                    return Err(format!("{path} json is not an object"));
                }
                (vec![path], vec![mode])
            }
            Item::Link { path, target, .. } => (vec![path, target], vec![]),
            Item::Trust { source, path, hash_directory, mode, .. } => {
                if !TRUST_SOURCES.contains(&source.as_str()) {
                    return Err(format!("{source} is not a trust source"));
                }
                (vec![path, hash_directory], vec![mode])
            }
            Item::Folder { path, files } => {
                for file in files {
                    let inside = &file.path;
                    if inside.is_empty() || inside.starts_with('/') || inside.split('/').any(|part| part.is_empty() || part == "." || part == "..") || inside.bytes().any(|b| b.is_ascii_control()) {
                        return Err(format!("{inside} is not a path inside {path}"));
                    }
                }
                (vec![path], vec![])
            }
            Item::Nss { home, directories, path, mode, directory_mode, .. } => {
                let mut paths = vec![home.as_str(), path.as_str()];
                paths.extend(directories.iter().map(String::as_str));
                (paths, vec![mode, directory_mode])
            }
        };
        if let Some(bad) = paths.iter().find(|value| !path(value)) {
            return Err(format!("{bad} is not an absolute path"));
        }
        if let Some(bad) = modes.iter().find(|value| !mode(value)) {
            return Err(format!("{bad} is not a mode"));
        }
    }
    Ok(())
}

/// What the Conn Server leaves in its settings file in place of a secret it moved into its own store.
const IMPORTED_SECRET: &str = "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}";

/// Orbit's settings over the held ones, object by object; Orbit's value wins at each leaf and list.
fn deep_merge(held: &Value, orbit: &Value) -> Value {
    match (held, orbit) {
        (Value::Object(held), Value::Object(orbit)) => {
            let mut merged = held.clone();
            for (name, value) in orbit {
                let next = deep_merge(held.get(name).unwrap_or(&Value::Null), value);
                merged.insert(name.clone(), next);
            }
            Value::Object(merged)
        }
        _ => orbit.clone(),
    }
}

/// The settings with each string equal to `value` replaced by `key` (or removed as a match when `key` is None).
fn with_model_key(settings: &Value, value: Option<&str>, key: Option<&str>) -> Value {
    match settings {
        Value::Object(map) => Value::Object(map.iter().map(|(name, item)| (name.clone(), with_model_key(item, value, key))).collect()),
        Value::Array(items) => Value::Array(items.iter().map(|item| with_model_key(item, value, key)).collect()),
        Value::String(text) if value == Some(text.as_str()) => key.map_or(Value::Null, |key| Value::String(key.to_owned())),
        other => other.clone(),
    }
}

/// The settings to write, or None when the held ones already hold Orbit's: with the model key where Orbit holds `value`, or
/// with the Conn Server's mark of a key it already moved into its store (`instance_clone.py` `merged_settings`). Orbit's
/// settings that need the key, with no key, refuse.
fn merged_settings(held: &Value, orbit: &Value, value: Option<&str>, key: Option<&str>) -> Result<Option<Value>, ()> {
    if value.is_some() && with_model_key(orbit, value, None) != *orbit && key.is_none_or(str::is_empty) {
        return Err(());
    }
    let merged = deep_merge(held, &with_model_key(orbit, value, key));
    let moved = deep_merge(held, &with_model_key(orbit, value, Some(IMPORTED_SECRET)));
    Ok((*held != merged && *held != moved).then_some(merged))
}

/// The seed's Orbit units, links and Schedule files that are not the copy's own.
#[expect(clippy::case_sensitive_file_extension_comparisons, reason = "systemd unit and Schedule file names are lowercase")]
fn purge(files: &Files<'_, impl System>, input: &PrepareInput) -> Result<u32, Failure> {
    let units: BTreeSet<&str> = input.units.iter().map(String::as_str).collect();
    let ids: BTreeSet<&str> = input.process_ids.iter().map(String::as_str).collect();
    // A folder whose absence would hide a failed read must exist; the others may be missing.
    let folders: [(&str, bool); 6] = [
        ("/etc/systemd/system/multi-user.target.wants", false),
        ("/etc/systemd/system", true),
        ("/etc/orbit/processes", false),
        ("/etc/systemd/system/sockets.target.wants", false),
        ("/etc/systemd/system/timers.target.wants", true),
        ("/etc/orbit/schedules", true),
    ];
    let (mut links, mut remove) = (Vec::new(), Vec::new());
    for (index, (folder, required)) in folders.iter().enumerate() {
        let listed = match files.list(folder) {
            Ok(Some(listed)) => listed,
            Err(failure) if *required => return Err(Failure::new("schedule.container_clone_cleanup_failed", failure.detail)),
            Ok(None) | Err(_) => continue,
        };
        for item in listed {
            let service = item.ends_with(".service");
            let goes = match index {
                0 => item.starts_with("orbit-") && service && item != crate::fleet::TUNNEL_UNIT,
                1 => {
                    (item.starts_with("orbit-process-") && service && !units.contains(item.as_str()))
                        || (item.starts_with("orbit-schedule-") && (service || item.ends_with(".timer")))
                        || item.starts_with("orbit-node-service-")
                }
                2 => !ids.contains(item.as_str()),
                3 => item.starts_with("orbit-node-service-"),
                4 => item.starts_with("orbit-schedule-") && item.ends_with(".timer"),
                _ => item.ends_with(".sh") || item.ends_with(".complete"),
            };
            if goes {
                let path = format!("{}{folder}/{item}", input.name);
                if folder.ends_with(".wants") { links.push(path) } else { remove.push(path) }
            }
        }
    }
    let count = u32::try_from(links.len() + remove.len()).unwrap_or(u32::MAX);
    // Links first and without --force: Incus resolves the path for --force, so a link whose unit is gone would fail.
    if !links.is_empty() {
        files.incus.change(&[&["--project", input.project.as_str(), "file", "delete"][..], &links.iter().map(String::as_str).collect::<Vec<_>>()].concat())?;
    }
    if !remove.is_empty() {
        files
            .incus
            .change(&[&["--project", input.project.as_str(), "file", "delete", "--force"][..], &remove.iter().map(String::as_str).collect::<Vec<_>>()].concat())?;
    }
    Ok(count)
}

fn place(system: &impl System, files: &Files<'_, impl System>, item: &Item) -> Result<u32, Failure> {
    match item {
        Item::File { path, uid, gid, mode, content, blob, parents } => {
            let bytes = match (content, blob) {
                (Some(text), _) => text.clone().into_bytes(),
                (None, Some(blob)) => system.blob(&blob.blob.sha256).map_err(|detail| Failure::new("instance.prepare_failed", detail))?,
                (None, None) => Vec::new(),
            };
            let made = if *parents { files.parents(path, *uid, *gid)? } else { 0 };
            files.push(path, *uid, *gid, mode, &bytes, if *parents { Folders::Present } else { Folders::Root })?;
            Ok(made + 1)
        }
        Item::Json { path, uid, gid, mode, json, key_value, key } => {
            let held = files.read(path, "instance.clone_files_refused")?;
            let old = match &held {
                Some(held) => serde_json::from_slice::<Value>(&held.content).map_err(|error| Failure::new("instance.prepare_failed", format!("{path}: {error}")))?,
                None => serde_json::json!({}),
            };
            let merged = merged_settings(&old, json, key_value.as_deref(), key.as_deref()).map_err(|()| Failure::new("instance.clone_t3_files_refused", path.clone()))?;
            let owned = held.as_ref().is_some_and(|held| (held.uid, held.gid) == (*uid, *gid) && held.mode == four(mode));
            // A file that already holds Orbit's settings with the owner and mode stays as it is.
            if held.is_some() && merged.is_none() && owned {
                return Ok(0);
            }
            let made = files.parents(path, *uid, *gid)?;
            let text = format!("{}\n", merged.unwrap_or(old));
            files.push(path, *uid, *gid, mode, text.as_bytes(), Folders::Present)?;
            Ok(made + 1)
        }
        Item::Link { path, target, owner } => {
            let made = match owner {
                Some(owner) => files.parents(path, owner.uid, owner.gid)?,
                None => 0,
            };
            files.incus.change(&["--project", files.project, "file", "create", "--type=symlink", "--force", &format!("{}{path}", files.name), target])?;
            Ok(made + 1)
        }
        Item::Folder { path, files: entries } => {
            if files.kind(path, "instance.agent_folder_refused")?.is_some_and(|kind| kind != "directory") {
                return Err(Failure::new("instance.agent_folder_refused", path.clone()));
            }
            let _ = files.incus.change(&["--project", files.project, "file", "delete", "--force", &format!("{}{path}", files.name)]);
            for entry in entries {
                let content = match (&entry.content, &entry.blob) {
                    (Some(text), _) => text.clone().into_bytes(),
                    (None, Some(blob)) => system.blob(&blob.blob.sha256).map_err(|detail| Failure::new("instance.prepare_failed", detail))?,
                    (None, None) => return Err(Failure::new("instance.prepare_input_invalid", format!("{} has no content", entry.path))),
                };
                files.push(&format!("{path}/{}", entry.path), 0, 0, "0644", &content, Folders::Root)?;
            }
            files.incus.change(&["--project", files.project, "file", "create", "--type=directory", "--uid", "0", "--gid", "0", "--mode", "0755", &format!("{}{path}/.skills", files.name)])?;
            Ok(u32::try_from(entries.len()).unwrap_or(u32::MAX) + 1)
        }
        Item::Trust { source, path, hash_directory, uid, gid, mode } => trust(system, files, source, path, hash_directory, (*uid, *gid, mode)),
        Item::Nss { home, directories, path, entry, uid, gid, mode, directory_mode, library, version } => {
            if files.kind(home, "instance.clone_browser_trust_refused")?.is_none() {
                return Ok(0);
            }
            let mut kinds = Vec::new();
            for folder in std::iter::once(home).chain(directories) {
                let kind = files.kind(folder, "instance.clone_browser_trust_refused")?;
                let allowed = if folder == home { kind.as_deref() == Some("directory") } else { kind.is_none() || kind.as_deref() == Some("directory") };
                if !allowed {
                    return Err(Failure::new("instance.clone_browser_trust_refused", folder.clone()));
                }
                kinds.push((folder, kind));
            }
            let store = files.read(path, "instance.clone_browser_trust_refused")?;
            let status = files.pull("/var/lib/dpkg/status")?.unwrap_or_default();
            if installed(&String::from_utf8_lossy(&status), library).as_deref() != Some(version.as_str()) {
                return Err(Failure::new("instance.clone_browser_trust_mismatch", library.clone()));
            }
            let mut changes = 0;
            for (folder, kind) in kinds.iter().skip(1) {
                if kind.is_none() {
                    files.incus.change(&[
                        "--project", files.project, "file", "create", "--type=directory", "--uid", &uid.to_string(), "--gid", &gid.to_string(),
                        "--mode", directory_mode, &format!("{}{folder}", files.name),
                    ])?;
                    changes += 1;
                }
            }
            let held = store.map(|store| String::from_utf8_lossy(&store.content).into_owned());
            let first = entry.lines().next().unwrap_or_default();
            if held.as_deref().is_some_and(|held| held.lines().any(|line| line == first)) {
                return Ok(changes);
            }
            let content = match held.as_deref() {
                None | Some("") => entry.clone(),
                Some(held) if held.ends_with("\n\n") => format!("{held}{entry}"),
                Some(held) if held.ends_with('\n') => format!("{held}\n{entry}"),
                Some(held) => format!("{held}\n\n{entry}"),
            };
            files.push(path, *uid, *gid, mode, content.as_bytes(), Folders::Present)?;
            Ok(changes + 1)
        }
    }
}

/// The Orbit root in the copy: the file, its name link and hash link, a stale hash link gone, and the bundle holding it.
fn trust(system: &impl System, files: &Files<'_, impl System>, source: &str, path: &str, folder: &str, (uid, gid, mode): (u32, u32, &String)) -> Result<u32, Failure> {
    let unavailable = |detail: String| Failure::new("instance.clone_trust_unavailable", detail);
    let content = system.read(source).map_err(unavailable)?;
    let hash = subject_hash(system, &content).ok_or_else(|| unavailable(format!("{source} has no subject hash")))?;
    let mut changes = 0;
    if let Some(old) = files.pull(path)? {
        if let Some(stale) = subject_hash(system, &old).filter(|stale| *stale != hash) {
            match files.incus.change(&["--project", files.project, "file", "delete", &format!("{}{folder}/{stale}.0", files.name)]) {
                Ok(()) => changes += 1,
                Err(failure) if failure.detail.contains("does not exist") || failure.detail.to_ascii_lowercase().contains("not found") => {}
                Err(failure) => return Err(Failure::new("instance.clone_trust_unavailable", failure.detail)),
            }
        }
    }
    files.push(path, uid, gid, mode, &content, Folders::Root)?;
    let stem = path.rsplit('/').next().unwrap_or_default().trim_end_matches(".crt");
    let named = format!("{folder}/{stem}.pem");
    for (link, target) in [(named.clone(), path.to_owned()), (format!("{folder}/{hash}.0"), named)] {
        files.incus.change(&["--project", files.project, "file", "create", "--type=symlink", "--force", &format!("{}{link}", files.name), &target])?;
    }
    changes += 3;
    let bundle = format!("{folder}/ca-certificates.crt");
    let held = files.read(&bundle, "instance.clone_trust_bundle_refused")?.ok_or_else(|| Failure::new("instance.clone_trust_bundle_refused", bundle.clone()))?;
    let text = String::from_utf8_lossy(&held.content);
    let root = String::from_utf8_lossy(&content);
    if !certificates(&root).is_subset(&certificates(&text)) {
        let mut joined = text.into_owned();
        if !joined.is_empty() && !joined.ends_with('\n') {
            joined.push('\n');
        }
        joined.push_str(&root);
        if !root.ends_with('\n') {
            joined.push('\n');
        }
        files.push(&bundle, held.uid, held.gid, &held.mode, joined.as_bytes(), Folders::Present)?;
        changes += 1;
    }
    Ok(changes)
}

fn subject_hash(system: &impl System, pem: &[u8]) -> Option<String> {
    let hash = system.run(10, &[OPENSSL.to_owned(), "x509".to_owned(), "-noout".to_owned(), "-subject_hash".to_owned()], Some(pem)).ok()?;
    let hash = hash.trim();
    (hash.len() == 8 && hash.bytes().all(|byte| byte.is_ascii_hexdigit())).then(|| hash.to_owned())
}

/// The base64 bodies of every certificate in a PEM text.
fn certificates(text: &str) -> BTreeSet<String> {
    text.split("-----BEGIN CERTIFICATE-----")
        .skip(1)
        .filter_map(|rest| rest.split_once("-----END CERTIFICATE-----").map(|(body, _)| body.split_whitespace().collect()))
        .collect()
}

/// The version of an installed amd64 package in a dpkg status file.
fn installed(status: &str, package: &str) -> Option<String> {
    status.split("\n\n").find_map(|stanza| {
        let field = |name: &str| stanza.lines().find_map(|line| line.strip_prefix(name).and_then(|rest| rest.strip_prefix(": ")));
        (field("Package") == Some(package) && field("Architecture") == Some("amd64") && field("Status") == Some("install ok installed"))
            .then(|| field("Version").map(str::to_owned))
            .flatten()
    })
}

/// Who owns the missing folders above a pushed file.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum Folders {
    /// Incus makes them, owned by root.
    Root,
    /// They exist already.
    Present,
}

/// A mode as four octal digits.
fn four(mode: &str) -> String {
    let padded = format!("{mode:0>4}");
    padded.chars().skip(padded.chars().count().saturating_sub(4)).collect()
}

/// A regular file of the copy with its owner and mode.
pub(crate) struct Held {
    pub(crate) content: Vec<u8>,
    pub(crate) uid: u32,
    pub(crate) gid: u32,
    pub(crate) mode: String,
}

/// The copy's files through the Incus files API.
pub(crate) struct Files<'a, S: System> {
    pub(crate) incus: &'a Incus<'a, S>,
    pub(crate) project: &'a str,
    pub(crate) name: &'a str,
}

impl<S: System> Files<'_, S> {
    fn url(&self, path: &str) -> String {
        format!("http://incus/1.0/instances/{}/files?project={}&path={}", self.name, self.project, crate::encode(path))
    }

    /// What `path` is in the copy, or None when it is missing.
    pub(crate) fn kind(&self, path: &str, refused: &'static str) -> Result<Option<String>, Failure> {
        let head = self
            .incus
            .curl(&["--output", "/dev/null", "--write-out", "%{http_code} %header{x-incus-type}", &self.url(path)])
            .map_err(|detail| Failure::new(refused, detail))?;
        let (code, kind) = head.trim().split_once(' ').unwrap_or((head.trim(), ""));
        match code {
            "404" => Ok(None),
            "200" => Ok(Some(if kind.is_empty() { "unknown".to_owned() } else { kind.to_owned() })),
            _ => Err(Failure::new(refused, format!("{path}: {code}"))),
        }
    }

    /// A regular file of the copy, or None when it is missing. A link or any other type refuses.
    pub(crate) fn read(&self, path: &str, refused: &'static str) -> Result<Option<Held>, Failure> {
        let Some(kind) = self.kind(path, refused)? else { return Ok(None) };
        if kind != "file" {
            return Err(Failure::new(refused, format!("{path} is a {kind}")));
        }
        let head = self
            .incus
            .curl(&["--output", "/dev/null", "--write-out", "%header{x-incus-uid} %header{x-incus-gid} %header{x-incus-mode}", &self.url(path)])
            .map_err(|detail| Failure::new(refused, detail))?;
        let fields: Vec<&str> = head.split_whitespace().collect();
        let [uid, gid, mode] = fields.as_slice() else { return Err(Failure::new(refused, format!("{path}: {head}"))) };
        let (Ok(uid), Ok(gid)) = (uid.parse(), gid.parse()) else { return Err(Failure::new(refused, format!("{path}: {head}"))) };
        let content = self.pull(path)?.unwrap_or_default();
        Ok(Some(Held { content, uid, gid, mode: four(mode) }))
    }

    pub(crate) fn pull(&self, path: &str) -> Result<Option<Vec<u8>>, Failure> {
        match self.incus.system.run(60, &[crate::INCUS.to_owned(), "--project".to_owned(), self.project.to_owned(), "file".to_owned(), "pull".to_owned(), format!("{}{path}", self.name), "-".to_owned()], None) {
            Ok(text) => Ok(Some(text.into_bytes())),
            Err(detail) if detail.contains("does not exist") || detail.to_ascii_lowercase().contains("not found") => Ok(None),
            Err(detail) => Err(Failure::new(self.incus.code, format!("pull {path}: {detail}"))),
        }
    }

    fn list(&self, folder: &str) -> Result<Option<Vec<String>>, Failure> {
        let listed = self.incus.query(&format!("/1.0/instances/{}/files?project={}&path={}", self.name, self.project, crate::encode(folder)))?;
        Ok(listed.map(|value| value.as_array().map(|rows| rows.iter().filter_map(Value::as_str).map(str::to_owned).collect()).unwrap_or_default()))
    }

    pub(crate) fn push(&self, path: &str, uid: u32, gid: u32, mode: &str, content: &[u8], folders: Folders) -> Result<(), Failure> {
        let target = format!("{}{path}", self.name);
        let (uid, gid) = (uid.to_string(), gid.to_string());
        let mut argv = vec!["--project", self.project, "file", "push"];
        if folders == Folders::Root {
            argv.push("--create-dirs");
        }
        argv.extend(["--uid", &uid, "--gid", &gid, "--mode", mode, "-", &target]);
        self.incus.write(&argv, content)
    }

    /// The missing folders above `path`, below `/`, `/home` and `/etc`, made for the file's owner.
    pub(crate) fn parents(&self, path: &str, uid: u32, gid: u32) -> Result<u32, Failure> {
        let mut missing = Vec::new();
        let mut parent = path;
        while let Some((above, _)) = parent.rsplit_once('/') {
            if above.is_empty() || above == "/home" || above == "/etc" {
                break;
            }
            match self.kind(above, "instance.clone_files_refused")?.as_deref() {
                None => missing.push(above),
                Some("directory") => break,
                Some(_) => return Err(Failure::new("instance.clone_files_refused", above.to_owned())),
            }
            parent = above;
        }
        let mode = if uid == 0 { "0755" } else { "0700" };
        for folder in missing.iter().rev() {
            self.incus.change(&[
                "--project", self.project, "file", "create", "--type=directory", "--uid", &uid.to_string(), "--gid", &gid.to_string(), "--mode", mode,
                &format!("{}{folder}", self.name),
            ])?;
        }
        Ok(u32::try_from(missing.len()).unwrap_or(u32::MAX))
    }
}

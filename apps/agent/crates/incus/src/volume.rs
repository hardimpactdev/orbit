//! `incus.volume.ensure`: one custom storage volume in one pool and project, copied from a source volume or one of its
//! snapshots, or absent.

use std::collections::BTreeMap;

use serde::Deserialize;
use serde_json::{json, Value};

use crate::NodeSystem as Client;
use crate::{connect, input, not_converged, plain, Failure, State, NAME};

/// The volume key that records what a volume was copied from: `<project>/<pool>/<volume>[/<snapshot>]`.
pub(crate) const SOURCE_KEY: &str = "user.source";
/// The volume key that marks a copy whose success no order has seen yet. The create sets it, and the order that sees the
/// copy's operation succeed removes it.
pub(crate) const COPYING_KEY: &str = "user.source.copying";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    #[serde(default)]
    socket: Option<String>,
    project: String,
    pool: String,
    name: String,
    state: State,
    timeout_ms: u64,
    #[serde(default)]
    source: Option<Source>,
    #[serde(default)]
    config: BTreeMap<String, String>,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Source {
    #[serde(default)]
    project: Option<String>,
    pool: String,
    volume: String,
    #[serde(default)]
    snapshot: Option<String>,
}

/// Ensures one custom storage volume.
///
/// Present: copies the volume, without its snapshots, from the source volume or snapshot, recording that source in
/// `user.source`, and sets each given configuration key that differs. A volume of that name from another source, or with no
/// record, refuses and is never overwritten. Absent: deletes exactly that volume only when nothing uses it. Answers the
/// number of changes, 0 on a repeat.
///
/// # Errors
///
/// `incus.input_invalid`, `incus.project_missing`, `incus.project_storage_not_owned`, `incus.volume_type_unsupported`,
/// `incus.volume_source_missing`, `incus.source_incomplete`, `incus.volume_source_conflict`, `incus.copy_in_progress`,
/// `incus.copy_incomplete`, `incus.volume_in_use`, `incus.volume_has_snapshots`, `incus.volume_has_backups`,
/// `incus.operation_timeout`, `incus.api_failed` or `incus.not_converged`.
pub fn volume_ensure(raw: &Value) -> Result<u32, Failure> {
    let input: Input = input(raw)?;
    if input.config.contains_key(SOURCE_KEY)
        || input.config.contains_key(COPYING_KEY)
        || input.config.keys().any(|key| key.starts_with("volatile."))
    {
        return Err(Failure::new(
            "incus.input_invalid",
            "config may not set user.source or a volatile key",
        ));
    }
    let path = format!(
        "/1.0/storage-pools/{}/volumes/custom/{}?project={}",
        input.pool, input.name, input.project
    );
    match (input.state, &input.source) {
        (State::Absent, None) if input.config.is_empty() => absent(&input, &path),
        (State::Present, Some(source)) => present(&input, source, &path),
        _ => Err(Failure::new(
            "incus.input_invalid",
            "present takes a source; absent takes no source and no config",
        )),
    }
}

fn absent(input: &Input, path: &str) -> Result<u32, Failure> {
    let client = connect(input.socket.as_deref(), input.timeout_ms, &[&input.project, &input.pool, &input.name])?;
    owns_storage(&client, &input.project)?;
    let Some((volume, _)) = client.get(path)? else { return Ok(0) };
    refuse_unsupported(&volume, &input.name)?;
    if volume["config"].get(COPYING_KEY).is_some() && !client.copies(&input.project)?.is_empty() {
        return Err(Failure::new("incus.copy_in_progress", format!("{} may still be being copied", input.name)));
    }
    if volume["used_by"].as_array().is_none_or(|used| !used.is_empty()) {
        return Err(Failure::new("incus.volume_in_use", format!("{} is used by {}", input.name, volume["used_by"])));
    }
    // A DELETE also removes the volume's snapshots and backups, which are resources of their own.
    for (kind, code) in [("snapshots", "incus.volume_has_snapshots"), ("backups", "incus.volume_has_backups")] {
        let list = format!("/1.0/storage-pools/{}/volumes/custom/{}/{kind}?project={}", input.pool, input.name, input.project);
        let (names, _) = client.get(&list)?.ok_or_else(|| not_converged(&input.name))?;
        if names.as_array().is_none_or(|names| !names.is_empty()) {
            return Err(Failure::new(code, format!("{} has {kind} {names}", input.name)));
        }
    }
    client.change("DELETE", path, None, None)?;
    match client.get(path)? {
        None => Ok(1),
        Some(_) => Err(not_converged(&input.name)),
    }
}

/// Refuses a project whose custom volumes Incus keeps in `default` (`features.storage.volumes` off), so every read and write
/// acts in the project the order names and never silently in another.
fn owns_storage(client: &Client, project: &str) -> Result<(), Failure> {
    let (found, _) = client
        .get(&format!("/1.0/projects/{project}"))?
        .ok_or_else(|| Failure::new("incus.project_missing", project.to_owned()))?;
    if found["config"]["features.storage.volumes"] == "true" {
        Ok(())
    } else {
        Err(Failure::new("incus.project_storage_not_owned", format!("{project} keeps its volumes in default")))
    }
}

fn present(input: &Input, source: &Source, path: &str) -> Result<u32, Failure> {
    let project = source.project.as_deref().unwrap_or(&input.project);
    let mut names = vec![input.project.as_str(), &input.pool, &input.name, project, &source.pool, &source.volume];
    names.extend(source.snapshot.as_deref());
    let client = connect(input.socket.as_deref(), input.timeout_ms, &names)?;
    if input.config.iter().any(|(key, value)| !NAME.is_match(key) || !plain(value)) {
        return Err(Failure::new("incus.input_invalid", "a config key is not a plain key, or a value is empty or padded"));
    }
    if project == input.project && source.pool == input.pool && source.volume == input.name {
        return Err(Failure::new("incus.input_invalid", "a volume cannot be its own source"));
    }
    owns_storage(&client, &input.project)?;
    if project != input.project {
        owns_storage(&client, project)?;
    }
    let record = match &source.snapshot {
        Some(snapshot) => format!("{project}/{}/{}/{snapshot}", source.pool, source.volume),
        None => format!("{project}/{}/{}", source.pool, source.volume),
    };
    let mut changes = 0;
    // Every refusal reads only: the destination's content type and provenance, then the source, are checked before any
    // write to the destination, a recovery's delete included.
    let existing = client.get(path)?;
    if let Some((found, _)) = &existing {
        refuse_unsupported(found, &input.name)?;
        if found["config"][SOURCE_KEY] != json!(record) {
            return Err(Failure::new(
                "incus.volume_source_conflict",
                format!("{} records source {} instead of {record}", input.name, found["config"][SOURCE_KEY]),
            ));
        }
    }
    let settings = checked_source(&client, source, project, &record)?;
    if let Some((found, _)) = existing {
        if found["config"].get(COPYING_KEY).is_some() {
            // No order saw this copy succeed. Wait for the project's running copies, then a volume still marked is an
            // incomplete copy that this primitive made: delete it and copy again.
            settle(&client, &input.project, &input.name)?;
            if let Some((found, _)) = client.get(path)? {
                if found["config"].get(COPYING_KEY).is_some() {
                    discard(&client, input, path, &found)?;
                    changes += 1;
                }
            }
        }
    }
    let (found, etag) = if let Some(found) = client.get(path)? {
        found
    } else {
        copy(&client, input, source, project, &record, path, &settings).map_err(|failure| {
            if changes > 0 && failure.code == crate::api::TIMEOUT {
                Failure::new("incus.copy_incomplete", format!("{}: the copy again did not finish within the deadline", input.name))
            } else {
                failure
            }
        })?;
        changes += 1;
        client.get(path)?.ok_or_else(|| not_converged(&input.name))?
    };
    if found["config"].get(COPYING_KEY).is_some() || found["config"][SOURCE_KEY] != json!(record) {
        return Err(not_converged(&input.name));
    }
    if input.config.iter().all(|(key, value)| found["config"][key.as_str()] == json!(value)) {
        return Ok(changes);
    }
    put(&client, path, &found, etag.as_deref(), &input.config)?;
    let (volume, _) = client.get(path)?.ok_or_else(|| not_converged(&input.name))?;
    if input.config.iter().all(|(key, value)| volume["config"][key.as_str()] == json!(value))
        && volume["config"][SOURCE_KEY] == json!(record)
        && volume["description"] == found["description"]
    {
        Ok(changes + 1)
    } else {
        Err(not_converged(&input.name))
    }
}

/// Writes the volume's configuration as read, with its own `volatile.` keys, without the copy mark and with the given keys
/// set, and keeps its description. A PUT replaces the writable state, so it carries it whole, under the `ETag` read.
fn put(client: &Client, path: &str, found: &Value, etag: Option<&str>, keys: &BTreeMap<String, String>) -> Result<(), Failure> {
    let mut config = found["config"].as_object().cloned().unwrap_or_default();
    config.remove(COPYING_KEY);
    for (key, value) in keys {
        config.insert(key.clone(), json!(value));
    }
    let body = json!({"config": config, "description": found["description"].as_str().unwrap_or("")});
    client.change("PUT", path, Some(&body), etag)
}

/// Refuses a volume this primitive does not handle: an ISO volume, which Incus imports rather than copies.
fn refuse_unsupported(volume: &Value, name: &str) -> Result<(), Failure> {
    if volume["content_type"] == "iso" {
        return Err(Failure::new("incus.volume_type_unsupported", format!("{name} is an ISO volume")));
    }
    Ok(())
}

/// Waits for the project's running volume copies within the deadline; one still running refuses `incus.copy_in_progress`.
fn settle(client: &Client, project: &str, name: &str) -> Result<(), Failure> {
    for operation in client.copies(project)? {
        client.wait(&operation).map_err(|failure| {
            if failure.code == crate::api::TIMEOUT {
                Failure::new("incus.copy_in_progress", format!("{name} may still be being copied"))
            } else {
                failure
            }
        })?;
    }
    Ok(())
}

/// Deletes an incomplete copy before it is made again. It touches nothing that another resource holds: a marked volume
/// that is attached or has snapshots or backups refuses `incus.copy_incomplete`, as does a delete past the deadline.
fn discard(client: &Client, input: &Input, path: &str, found: &Value) -> Result<(), Failure> {
    let incomplete = |detail: String| Failure::new("incus.copy_incomplete", detail);
    if found["used_by"].as_array().is_none_or(|used| !used.is_empty()) {
        return Err(incomplete(format!("{} is an incomplete copy in use by {}", input.name, found["used_by"])));
    }
    for kind in ["snapshots", "backups"] {
        let list = format!("/1.0/storage-pools/{}/volumes/custom/{}/{kind}?project={}", input.pool, input.name, input.project);
        let (names, _) = client.get(&list)?.ok_or_else(|| not_converged(&input.name))?;
        if names.as_array().is_none_or(|names| !names.is_empty()) {
            return Err(incomplete(format!("{} is an incomplete copy with {kind} {names}", input.name)));
        }
    }
    client.change("DELETE", path, None, None).map_err(|failure| {
        if failure.code == crate::api::TIMEOUT {
            incomplete(format!("{}: the incomplete copy was not deleted within the deadline", input.name))
        } else {
            failure
        }
    })?;
    match client.get(path)? {
        None => Ok(()),
        Some(_) => Err(not_converged(&input.name)),
    }
}

/// Reads the source volume, and its snapshot when the input names one, and answers the settings a copy takes. A missing
/// volume or snapshot refuses `incus.volume_source_missing`, an ISO volume `incus.volume_type_unsupported`, and a volume or
/// snapshot that is itself a copy no order saw succeed `incus.source_incomplete`: it may hold only part of its data.
fn checked_source(client: &Client, source: &Source, project: &str, record: &str) -> Result<Value, Failure> {
    let parent = format!("/1.0/storage-pools/{}/volumes/custom/{}?project={project}", source.pool, source.volume);
    let missing = || Failure::new("incus.volume_source_missing", record.to_owned());
    let incomplete = || Failure::new("incus.source_incomplete", format!("{record} is an incomplete copy"));
    let (volume, _) = client.get(&parent)?.ok_or_else(missing)?;
    refuse_unsupported(&volume, &source.volume)?;
    if volume["config"].get(COPYING_KEY).is_some() {
        return Err(incomplete());
    }
    let found = match &source.snapshot {
        Some(snapshot) => {
            let from = format!("/1.0/storage-pools/{}/volumes/custom/{}/snapshots/{snapshot}?project={project}", source.pool, source.volume);
            client.get(&from)?.ok_or_else(missing)?.0
        }
        None => volume,
    };
    if found["config"].get(COPYING_KEY).is_some() {
        return Err(incomplete());
    }
    Ok(found["config"].clone())
}

/// Copies the volume, without its snapshots, from the checked source, then removes the copy mark once the copy's
/// operation has succeeded. The copy keeps the source's settings, apart from its volatile keys, its provenance and its
/// mark, sets the given keys over them and records the source.
fn copy(client: &Client, input: &Input, source: &Source, project: &str, record: &str, path: &str, settings: &Value) -> Result<(), Failure> {
    let mut config = settings.as_object().cloned().unwrap_or_default();
    config.retain(|key, _| !key.starts_with("volatile.") && key != SOURCE_KEY && key != COPYING_KEY);
    for (key, value) in &input.config {
        config.insert(key.clone(), json!(value));
    }
    config.insert(SOURCE_KEY.to_owned(), json!(record));
    config.insert(COPYING_KEY.to_owned(), json!("true"));
    let name = source.snapshot.as_ref().map_or_else(|| source.volume.clone(), |snapshot| format!("{}/{snapshot}", source.volume));
    let create = json!({
        "name": input.name,
        "type": "custom",
        "config": config,
        "source": {"type": "copy", "pool": source.pool, "name": name, "project": project, "volume_only": true},
    });
    client.change("POST", &format!("/1.0/storage-pools/{}/volumes/custom?project={}", input.pool, input.project), Some(&create), None)?;
    // The copy's operation succeeded in this order: only now does the mark go.
    let (made, etag) = client.get(path)?.ok_or_else(|| not_converged(&input.name))?;
    put(client, path, &made, etag.as_deref(), &BTreeMap::new())
}

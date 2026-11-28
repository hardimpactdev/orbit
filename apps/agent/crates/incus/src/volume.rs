//! `incus.volume.ensure`: one custom storage volume in one pool and project, copied from a source volume or one of its
//! snapshots, or absent.

use std::collections::BTreeMap;

use serde::Deserialize;
use serde_json::{json, Value};

use crate::client::Client;
use crate::{connect, input, not_converged, Failure, State, NAME};

/// The volume key that records what a volume was copied from: `<project>/<pool>/<volume>[/<snapshot>]`.
pub(crate) const SOURCE_KEY: &str = "user.source";

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
/// `incus.input_invalid`, `incus.volume_source_missing`, `incus.volume_source_conflict`, `incus.volume_in_use`,
/// `incus.unreachable`, `incus.timeout`, `incus.api_failed` or `incus.not_converged`.
pub fn volume_ensure(raw: &Value) -> Result<u32, Failure> {
    let input: Input = input(raw)?;
    if input.config.contains_key(SOURCE_KEY)
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
    let client = connect(
        input.socket.as_deref(),
        input.timeout_ms,
        &[&input.project, &input.pool, &input.name],
    )?;
    let Some((volume, _)) = client.get(path)? else {
        return Ok(0);
    };
    if volume["used_by"]
        .as_array()
        .is_none_or(|used| !used.is_empty())
    {
        return Err(Failure::new(
            "incus.volume_in_use",
            format!("{} is used by {}", input.name, volume["used_by"]),
        ));
    }
    client.change("DELETE", path, None, None)?;
    match client.get(path)? {
        None => Ok(1),
        Some(_) => Err(not_converged(&input.name)),
    }
}

fn present(input: &Input, source: &Source, path: &str) -> Result<u32, Failure> {
    let project = source.project.as_deref().unwrap_or(&input.project);
    let mut names = vec![
        input.project.as_str(),
        &input.pool,
        &input.name,
        project,
        &source.pool,
        &source.volume,
    ];
    names.extend(source.snapshot.as_deref());
    let client = connect(input.socket.as_deref(), input.timeout_ms, &names)?;
    if input.config.keys().any(|key| !NAME.is_match(key)) {
        return Err(Failure::new(
            "incus.input_invalid",
            "a config key is not a plain key",
        ));
    }
    if project == input.project && source.pool == input.pool && source.volume == input.name {
        return Err(Failure::new(
            "incus.input_invalid",
            "a volume cannot be its own source",
        ));
    }
    let record = match &source.snapshot {
        Some(snapshot) => format!("{project}/{}/{}/{snapshot}", source.pool, source.volume),
        None => format!("{project}/{}/{}", source.pool, source.volume),
    };
    let mut changes = 0;
    let found = if let Some((volume, _)) = client.get(path)? {
        volume
    } else {
        create(&client, input, source, project, &record)?;
        changes += 1;
        client.get(path)?.ok_or_else(|| not_converged(&input.name))?.0
    };
    if found["config"][SOURCE_KEY] != json!(record) {
        return Err(Failure::new(
            "incus.volume_source_conflict",
            format!(
                "{} records source {} instead of {record}",
                input.name, found["config"][SOURCE_KEY]
            ),
        ));
    }
    let differs: BTreeMap<&String, &String> = input
        .config
        .iter()
        .filter(|(key, value)| found["config"][key.as_str()] != json!(value))
        .collect();
    if differs.is_empty() {
        return Ok(changes);
    }
    // A PATCH merges the given keys into the volume's configuration and leaves every other key as it is.
    client.change("PATCH", path, Some(&json!({"config": differs})), None)?;
    let (volume, _) = client
        .get(path)?
        .ok_or_else(|| not_converged(&input.name))?;
    if input
        .config
        .iter()
        .all(|(key, value)| volume["config"][key.as_str()] == json!(value))
        && volume["config"][SOURCE_KEY] == json!(record)
    {
        Ok(changes + 1)
    } else {
        Err(not_converged(&input.name))
    }
}

/// Copies the volume, without its snapshots, from a source that must exist, recording the source in its configuration.
fn create(client: &Client, input: &Input, source: &Source, project: &str, record: &str) -> Result<(), Failure> {
    let from = match &source.snapshot {
        Some(snapshot) => format!("/1.0/storage-pools/{}/volumes/custom/{}/snapshots/{snapshot}?project={project}", source.pool, source.volume),
        None => format!("/1.0/storage-pools/{}/volumes/custom/{}?project={project}", source.pool, source.volume),
    };
    if client.get(&from)?.is_none() {
        return Err(Failure::new("incus.volume_source_missing", record));
    }
    let mut config = input.config.clone();
    config.insert(SOURCE_KEY.to_owned(), record.to_owned());
    let name = source.snapshot.as_ref().map_or_else(|| source.volume.clone(), |snapshot| format!("{}/{snapshot}", source.volume));
    let create = json!({
        "name": input.name,
        "type": "custom",
        "config": config,
        "source": {"type": "copy", "pool": source.pool, "name": name, "project": project, "volume_only": true},
    });
    client.change("POST", &format!("/1.0/storage-pools/{}/volumes/custom?project={}", input.pool, input.project), Some(&create), None)
}

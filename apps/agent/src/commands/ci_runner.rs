//! `ci-runner.host`: the CI role's reserved Incus host resources (ADR 0213): the `orbit-ci` project, the `orbitci0`
//! bridge with its egress ACL, the `orbit-ci` ZFS or btrfs pool, and the project's default profile rooted on it. It never
//! creates, registers, moves, or deletes a runner: a runner on another pool keeps it through a pinned root device until
//! `tools/ci-runners/setup.sh move` moves it while idle. Removal refuses while the project holds any instance, stopped
//! ones included.

use super::incus::{bounded, Output};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use std::time::{Duration, Instant};

const PROJECT: &str = "orbit-ci";
const NETWORK: &str = "orbitci0";
const ACL: &str = "orbit-ci-egress";
const POOL: &str = "orbit-ci";
/// The Node's approved Instance pool; its driver and ZFS pool select the CI pool's storage.
const SOURCE_POOL: &str = "orbit-instances";
const OWNER: &str = "user.orbit.ci-runner";

const PROJECT_CONFIG: [(&str, &str); 14] = [
    ("features.images", "true"),
    ("features.profiles", "true"),
    ("features.storage.volumes", "true"),
    ("features.storage.buckets", "true"),
    ("features.networks", "false"),
    ("restricted", "true"),
    ("restricted.networks.access", NETWORK),
    ("restricted.containers.nesting", "block"),
    ("restricted.containers.privilege", "unprivileged"),
    ("restricted.virtual-machines.lowlevel", "block"),
    ("restricted.containers.lowlevel", "allow"),
    // The one host disk a runner may mount: the Node's runtime folder, read-only (tools/ci-runners/setup.sh).
    ("restricted.devices.disk", "allow"),
    ("restricted.devices.disk.paths", "/opt/orbit-runtime"),
    ("limits.virtual-machines", "0"),
];

const NETWORK_CONFIG: [(&str, &str); 5] = [
    ("ipv4.nat", "true"),
    ("ipv6.address", "none"),
    ("security.acls", ACL),
    ("security.acls.default.egress.action", "reject"),
    ("security.acls.default.ingress.action", "reject"),
];

command_errors! {
    pub enum CiHostError {
        Failed { reason: String } => "ci_runner.host_failed", "The CI host step failed.";
    }
}

fn failed(reason: impl Into<String>) -> CiHostError {
    CiHostError::Failed {
        reason: reason.into(),
    }
}

fn profile() -> Value {
    json!({
        "description": "Orbit CI shard runner",
        "config": {"boot.autostart": "true", "limits.cpu": "6", "limits.memory": "12GiB",
                   "limits.processes": "8192", "security.nesting": "false", "security.privileged": "false"},
        "devices": {"eth0": {"name": "eth0", "network": NETWORK, "type": "nic"},
                    "root": {"path": "/", "pool": POOL, "type": "disk"}},
    })
}

fn acl_rules() -> Value {
    json!([
        {"action": "reject", "destination": "0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.168.0.0/16,198.18.0.0/15,224.0.0.0/3", "state": "enabled"},
        {"action": "reject", "destination": "::/0", "state": "enabled"},
        {"action": "allow", "destination": "0.0.0.0/0", "state": "enabled"},
    ])
}

/// Each Incus call keeps its own bound inside the command's deadline.
const CALL_TIMEOUT: Duration = Duration::from_secs(30);

/// The one host boundary: an `incus` invocation with optional standard input.
trait Host {
    fn incus(&mut self, arguments: &[&str], input: Option<&str>) -> Output;
}

struct Native<'a>(&'a Context);

impl Host for Native<'_> {
    fn incus(&mut self, arguments: &[&str], input: Option<&str>) -> Output {
        let deadline = self.0.deadline().min(Instant::now() + CALL_TIMEOUT);
        bounded(
            &Context::new(self.0.platform, deadline),
            "/usr/bin/incus",
            arguments,
            input,
        )
    }
}

/// Runs `incus`; `query` carries its own scope, every other command runs in the default project.
fn incus(
    host: &mut impl Host,
    arguments: &[&str],
    document: Option<&Value>,
) -> Result<String, CiHostError> {
    let mut argv = vec![];
    if arguments.first() != Some(&"query") {
        argv.extend(["--project", "default"]);
    }
    argv.extend(arguments);
    let output = host.incus(&argv, document.map(Value::to_string).as_deref());
    if output.code == 0 && !output.truncated {
        Ok(output.stdout)
    } else {
        Err(failed(format!(
            "incus {}: {}",
            arguments.join(" "),
            output.stderr.trim()
        )))
    }
}

fn query(host: &mut impl Host, path: &str) -> Result<Value, CiHostError> {
    serde_json::from_str(&incus(host, &["query", path], None)?)
        .map_err(|_| failed("incus answered no JSON"))
}

fn default_path(collection: &str, name: Option<&str>) -> String {
    let scope = if matches!(collection, "networks" | "network-acls") {
        "?project=default"
    } else {
        ""
    };
    match name {
        Some(name) => format!("/1.0/{collection}/{name}{scope}"),
        None => format!("/1.0/{collection}{scope}"),
    }
}

fn exists(host: &mut impl Host, collection: &str, name: &str) -> Result<bool, CiHostError> {
    let wanted = format!("/1.0/{collection}/{name}");
    Ok(query(host, &default_path(collection, None))?
        .as_array()
        .is_some_and(|paths| {
            paths.iter().any(|path| {
                path.as_str().and_then(|path| path.split('?').next()) == Some(wanted.as_str())
            })
        }))
}

fn includes(actual: &Value, expected: &[(&str, &str)]) -> bool {
    expected
        .iter()
        .all(|(key, value)| actual.get(key).and_then(Value::as_str) == Some(*value))
}

/// ACL rules without the empty fields Incus adds.
fn rules(value: &Value) -> Value {
    Value::Array(
        value
            .as_array()
            .into_iter()
            .flatten()
            .map(|rule| {
                Value::Object(
                    rule.as_object()
                        .into_iter()
                        .flatten()
                        .filter(|(_, item)| item.as_str() != Some(""))
                        .map(|(key, item)| (key.clone(), item.clone()))
                        .collect::<Map<_, _>>(),
                )
            })
            .collect(),
    )
}

fn matches(host: &mut impl Host) -> Result<bool, CiHostError> {
    for (collection, name) in [
        ("projects", PROJECT),
        ("networks", NETWORK),
        ("network-acls", ACL),
        ("storage-pools", POOL),
    ] {
        if !exists(host, collection, name)? {
            return Ok(false);
        }
    }
    let pool = query(host, &format!("/1.0/storage-pools/{POOL}"))?;
    let project = query(host, &format!("/1.0/projects/{PROJECT}"))?;
    let network = query(host, &default_path("networks", Some(NETWORK)))?;
    let acl = query(host, &default_path("network-acls", Some(ACL)))?;
    let profile_now = query(host, &format!("/1.0/profiles/default?project={PROJECT}"))?;
    let expected = profile();
    Ok(owned_pool(&pool).is_ok()
        && includes(&project["config"], &PROJECT_CONFIG)
        && network["type"] == "bridge"
        && network["managed"] == true
        && !matches!(
            network["config"]
                .get("ipv4.address")
                .and_then(Value::as_str),
            None | Some("" | "none" | "auto")
        )
        && includes(&network["config"], &NETWORK_CONFIG)
        && rules(&acl["egress"]) == acl_rules()
        && acl["ingress"] == json!([])
        && profile_now["config"] == expected["config"]
        && profile_now["devices"] == expected["devices"])
}

/// A CI pool is ZFS or btrfs, so a runner is never a copied folder, and carries the role's owner key.
fn owned_pool(pool: &Value) -> Result<(), CiHostError> {
    let driver = pool["driver"].as_str().unwrap_or_default();
    if !matches!(driver, "zfs" | "btrfs") {
        return Err(failed(format!(
            "the {POOL} pool is {driver}; CI runners need a zfs or btrfs pool"
        )));
    }
    if pool["config"].get(OWNER).and_then(Value::as_str) != Some("true") {
        return Err(failed(format!(
            "the {POOL} pool is not owned by the CI role"
        )));
    }
    Ok(())
}

/// Creates the CI pool beside the Node's Instance pool: a child dataset of its ZFS pool, or btrfs on a btrfs Node.
fn create_pool(host: &mut impl Host) -> Result<(), CiHostError> {
    let unavailable = || {
        failed(format!(
            "the CI pool needs the Node's {SOURCE_POOL} pool on zfs or btrfs"
        ))
    };
    if !exists(host, "storage-pools", SOURCE_POOL)? {
        return Err(unavailable());
    }
    let source = query(host, &format!("/1.0/storage-pools/{SOURCE_POOL}"))?;
    let owner = format!("{OWNER}=true");
    let location = match source["driver"].as_str() {
        Some("zfs") => {
            let zpool = source["config"]
                .get("source")
                .and_then(Value::as_str)
                .and_then(|dataset| dataset.split('/').next())
                .filter(|zpool| {
                    zpool.starts_with(|c: char| c.is_ascii_alphanumeric())
                        && zpool
                            .chars()
                            .all(|c| c.is_ascii_alphanumeric() || "_.:-".contains(c))
                })
                .ok_or_else(unavailable)?;
            ("zfs", format!("source={zpool}/{POOL}"))
        }
        Some("btrfs") => (
            "btrfs",
            format!("source=/var/lib/incus/storage-pools/{POOL}"),
        ),
        _ => return Err(unavailable()),
    };
    // Incus creates the missing dataset and refuses one that already holds data.
    incus(
        host,
        &["storage", "create", POOL, location.0, &location.1, &owner],
        None,
    )?;
    Ok(())
}

/// The root pool of each runner whose root comes from the profile and lies on another pool. Pinning it keeps a runner
/// where it is when the profile moves to the CI pool.
fn unpinned(host: &mut impl Host) -> Result<Vec<(String, String)>, CiHostError> {
    let mut found = vec![];
    for path in query(host, &format!("/1.0/instances?project={PROJECT}"))?
        .as_array()
        .into_iter()
        .flatten()
        .filter_map(Value::as_str)
    {
        let name = path
            .split('?')
            .next()
            .and_then(|path| path.strip_prefix("/1.0/instances/"))
            .ok_or_else(|| failed("incus answered an unexpected instance path"))?;
        let instance = query(host, &format!("/1.0/instances/{name}?project={PROJECT}"))?;
        let pool = instance["expanded_devices"]["root"]["pool"]
            .as_str()
            .unwrap_or_default();
        if instance["devices"].get("root").is_none() && !pool.is_empty() && pool != POOL {
            found.push((name.to_string(), pool.to_string()));
        }
    }
    Ok(found)
}

fn converge(host: &mut impl Host) -> Result<&'static str, CiHostError> {
    if !exists(host, "storage-pools", POOL)? {
        create_pool(host)?;
    }
    owned_pool(&query(host, &format!("/1.0/storage-pools/{POOL}"))?)?;
    if !exists(host, "projects", PROJECT)? {
        let features: Vec<String> = PROJECT_CONFIG
            .iter()
            .filter(|(key, _)| key.starts_with("features."))
            .flat_map(|(key, value)| ["-c".to_string(), format!("{key}={value}")])
            .collect();
        let mut arguments = vec!["project", "create", PROJECT];
        arguments.extend(features.iter().map(String::as_str));
        incus(host, &arguments, None)?;
    }
    // Existing setup.sh resources are adopted by their reserved names; a different isolation model is refused.
    if query(host, &format!("/1.0/projects/{PROJECT}"))?["config"]
        .get("features.networks")
        .and_then(Value::as_str)
        != Some("false")
    {
        return Err(failed("orbit-ci must share the default project networks"));
    }
    let settings: Vec<String> = PROJECT_CONFIG
        .iter()
        .map(|(key, value)| format!("{key}={value}"))
        .collect();
    let mut arguments = vec!["project", "set", PROJECT];
    arguments.extend(settings.iter().map(String::as_str));
    incus(host, &arguments, None)?;
    if !exists(host, "network-acls", ACL)? {
        incus(host, &["network", "acl", "create", ACL], None)?;
    }
    incus(
        host,
        &["network", "acl", "edit", ACL],
        Some(
            &json!({"description": "Orbit CI runner boundary", "egress": acl_rules(), "ingress": [], "config": {}}),
        ),
    )?;
    if !exists(host, "networks", NETWORK)? {
        incus(
            host,
            &[
                "network",
                "create",
                NETWORK,
                "--type=bridge",
                "ipv4.address=auto",
                "ipv4.nat=true",
                "ipv6.address=none",
            ],
            None,
        )?;
    }
    let network = query(host, &default_path("networks", Some(NETWORK)))?;
    if network["type"] != "bridge" || network["managed"] != true {
        return Err(failed("orbitci0 must be a managed bridge"));
    }
    let settings: Vec<String> = NETWORK_CONFIG
        .iter()
        .map(|(key, value)| format!("{key}={value}"))
        .collect();
    let mut arguments = vec!["network", "set", NETWORK];
    arguments.extend(settings.iter().map(String::as_str));
    incus(host, &arguments, None)?;
    for (name, pool) in unpinned(host)? {
        let pool = format!("pool={pool}");
        incus(
            host,
            &[
                "config",
                "device",
                "add",
                &name,
                "root",
                "disk",
                "path=/",
                &pool,
                "--project",
                PROJECT,
            ],
            None,
        )?;
    }
    incus(
        host,
        &["profile", "edit", "default", "--project", PROJECT],
        Some(&profile()),
    )?;
    if !matches(host)? {
        return Err(failed("CI host projection did not converge"));
    }
    Ok("converged")
}

fn remove(host: &mut impl Host, purge: bool) -> Result<&'static str, CiHostError> {
    if exists(host, "projects", PROJECT)? {
        if query(host, &format!("/1.0/instances?project={PROJECT}"))?
            .as_array()
            .is_some_and(|instances| !instances.is_empty())
        {
            return Ok("occupied");
        }
        if purge {
            // Incus refuses to delete a project that images, volumes, or other profiles still use.
            incus(host, &["project", "delete", PROJECT], None)?;
        }
    }
    if !purge {
        return Ok("retained");
    }
    if exists(host, "networks", NETWORK)? {
        incus(host, &["network", "delete", NETWORK], None)?;
    }
    if exists(host, "network-acls", ACL)? {
        incus(host, &["network", "acl", "delete", ACL], None)?;
    }
    Ok("removed")
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "action", rename_all = "snake_case", deny_unknown_fields)]
pub enum HostInput {
    Converge {},
    Inspect {},
    Remove {},
    Purge {},
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct HostOutcome {
    /// `converged`, `matches`, `drift`, `occupied`, `retained`, or `removed`.
    pub outcome: &'static str,
}

fn act(host: &mut impl Host, input: &HostInput) -> Result<HostOutcome, CiHostError> {
    let outcome = match input {
        HostInput::Converge {} => converge(host)?,
        HostInput::Inspect {} => {
            if matches(host)? {
                "matches"
            } else {
                "drift"
            }
        }
        HostInput::Remove {} => remove(host, false)?,
        HostInput::Purge {} => remove(host, true)?,
    };
    Ok(HostOutcome { outcome })
}

pub struct CiRunnerHost;

impl Command for CiRunnerHost {
    const NAME: &'static str = "ci-runner.host";
    const SUMMARY: &'static str =
        "Converge, inspect, or remove the CI role's reserved Incus host resources.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(180);
    type Input = HostInput;
    type Output = HostOutcome;
    type Error = CiHostError;

    /// Incus answers root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(_: &HostInput) -> Option<String> {
        Some("ci-runner-host".into())
    }

    fn run(input: HostInput, context: &Context) -> Result<HostOutcome, CiHostError> {
        act(&mut Native(context), &input)
    }
}

#[cfg(test)]
#[path = "ci_runner_tests.rs"]
mod tests;

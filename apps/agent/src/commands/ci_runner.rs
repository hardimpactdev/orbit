//! `ci-runners.host`: the CI role's reserved Incus host resources (ADR 0213): the `orbit-ci` project, the `orbitci0`
//! bridge with its egress ACL, and the project's default profile. It never creates, registers, changes, or deletes a
//! runner. Removal refuses while the project holds any instance, stopped ones included.

use super::incus::{bounded, Output};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use std::time::{Duration, Instant};

const PROJECT: &str = "orbit-ci";
const NETWORK: &str = "orbitci0";
const ACL: &str = "orbit-ci-egress";
const POOL: &str = "default";

const PROJECT_CONFIG: [(&str, &str); 12] = [
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
        Failed { reason: String } => "ci_runners.host_failed", "The CI host step failed.";
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
    let project = query(host, &format!("/1.0/projects/{PROJECT}"))?;
    let network = query(host, &default_path("networks", Some(NETWORK)))?;
    let acl = query(host, &default_path("network-acls", Some(ACL)))?;
    let profile_now = query(host, &format!("/1.0/profiles/default?project={PROJECT}"))?;
    let expected = profile();
    Ok(includes(&project["config"], &PROJECT_CONFIG)
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

fn converge(host: &mut impl Host) -> Result<&'static str, CiHostError> {
    if !exists(host, "storage-pools", POOL)? {
        incus(host, &["storage", "create", POOL, "dir"], None)?;
    }
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

#[derive(Deserialize)]
#[serde(tag = "action", rename_all = "snake_case", deny_unknown_fields)]
pub enum HostInput {
    Converge {},
    Inspect {},
    Remove {},
    Purge {},
}

#[derive(Debug, Serialize, PartialEq)]
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
    const NAME: &'static str = "ci-runners.host";
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

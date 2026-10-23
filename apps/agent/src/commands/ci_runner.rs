//! `ci-runners.host`: the CI role's reserved Incus host resources (ADR 0213): the `orbit-ci` project, the `orbitci0`
//! bridge with its egress ACL, and the project's default profile. It never creates, registers, changes, or deletes a
//! runner. Removal refuses while the project holds any instance, stopped ones included.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use std::{
    io::Write,
    process::{Command as Process, Stdio},
    time::Duration,
};

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

/// Runs `incus`; `query` carries its own scope, every other command runs in the default project.
fn incus(arguments: &[&str], document: Option<&Value>) -> Result<String, CiHostError> {
    let mut command = Process::new("timeout");
    command.args(["30", "incus"]);
    if arguments.first() != Some(&"query") {
        command.args(["--project", "default"]);
    }
    let mut child = command
        .args(arguments)
        .stdin(if document.is_some() {
            Stdio::piped()
        } else {
            Stdio::null()
        })
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|error| failed(error.to_string()))?;
    if let (Some(document), Some(mut stdin)) = (document, child.stdin.take()) {
        let _ = stdin.write_all(document.to_string().as_bytes());
    }
    let output = child
        .wait_with_output()
        .map_err(|error| failed(error.to_string()))?;
    if output.status.success() {
        Ok(String::from_utf8_lossy(&output.stdout).into_owned())
    } else {
        Err(failed(format!(
            "incus {}: {}",
            arguments.join(" "),
            String::from_utf8_lossy(&output.stderr).trim()
        )))
    }
}

fn query(path: &str) -> Result<Value, CiHostError> {
    serde_json::from_str(&incus(&["query", path], None)?)
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

fn exists(collection: &str, name: &str) -> Result<bool, CiHostError> {
    let wanted = format!("/1.0/{collection}/{name}");
    Ok(query(&default_path(collection, None))?
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

pub fn matches() -> Result<bool, CiHostError> {
    for (collection, name) in [
        ("projects", PROJECT),
        ("networks", NETWORK),
        ("network-acls", ACL),
        ("storage-pools", POOL),
    ] {
        if !exists(collection, name)? {
            return Ok(false);
        }
    }
    let project = query(&format!("/1.0/projects/{PROJECT}"))?;
    let network = query(&default_path("networks", Some(NETWORK)))?;
    let acl = query(&default_path("network-acls", Some(ACL)))?;
    let profile_now = query(&format!("/1.0/profiles/default?project={PROJECT}"))?;
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

fn converge() -> Result<&'static str, CiHostError> {
    if !exists("storage-pools", POOL)? {
        incus(&["storage", "create", POOL, "dir"], None)?;
    }
    if !exists("projects", PROJECT)? {
        let features: Vec<String> = PROJECT_CONFIG
            .iter()
            .filter(|(key, _)| key.starts_with("features."))
            .flat_map(|(key, value)| ["-c".to_string(), format!("{key}={value}")])
            .collect();
        let mut arguments = vec!["project", "create", PROJECT];
        arguments.extend(features.iter().map(String::as_str));
        incus(&arguments, None)?;
    }
    // Existing setup.sh resources are adopted by their reserved names; a different isolation model is refused.
    if query(&format!("/1.0/projects/{PROJECT}"))?["config"]
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
    incus(&arguments, None)?;
    if !exists("network-acls", ACL)? {
        incus(&["network", "acl", "create", ACL], None)?;
    }
    incus(
        &["network", "acl", "edit", ACL],
        Some(
            &json!({"description": "Orbit CI runner boundary", "egress": acl_rules(), "ingress": [], "config": {}}),
        ),
    )?;
    if !exists("networks", NETWORK)? {
        incus(
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
    let network = query(&default_path("networks", Some(NETWORK)))?;
    if network["type"] != "bridge" || network["managed"] != true {
        return Err(failed("orbitci0 must be a managed bridge"));
    }
    let settings: Vec<String> = NETWORK_CONFIG
        .iter()
        .map(|(key, value)| format!("{key}={value}"))
        .collect();
    let mut arguments = vec!["network", "set", NETWORK];
    arguments.extend(settings.iter().map(String::as_str));
    incus(&arguments, None)?;
    incus(
        &["profile", "edit", "default", "--project", PROJECT],
        Some(&profile()),
    )?;
    if !matches()? {
        return Err(failed("CI host projection did not converge"));
    }
    Ok("converged")
}

fn remove(purge: bool) -> Result<&'static str, CiHostError> {
    if exists("projects", PROJECT)? {
        if query(&format!("/1.0/instances?project={PROJECT}"))?
            .as_array()
            .is_some_and(|instances| !instances.is_empty())
        {
            return Ok("occupied");
        }
        if purge {
            // Incus refuses to delete a project that images, volumes, or other profiles still use.
            incus(&["project", "delete", PROJECT], None)?;
        }
    }
    if !purge {
        return Ok("retained");
    }
    if exists("networks", NETWORK)? {
        incus(&["network", "delete", NETWORK], None)?;
    }
    if exists("network-acls", ACL)? {
        incus(&["network", "acl", "delete", ACL], None)?;
    }
    Ok("removed")
}

#[derive(Deserialize)]
#[serde(tag = "action", rename_all = "snake_case", deny_unknown_fields)]
pub enum HostInput {
    Converge,
    Inspect,
    Remove,
    Purge,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct HostOutcome {
    /// `converged`, `matches`, `drift`, `occupied`, `retained`, or `removed`.
    pub outcome: &'static str,
}

pub fn host(input: &HostInput) -> Result<HostOutcome, CiHostError> {
    let outcome = match input {
        HostInput::Converge => converge()?,
        HostInput::Inspect => {
            if matches()? {
                "matches"
            } else {
                "drift"
            }
        }
        HostInput::Remove => remove(false)?,
        HostInput::Purge => remove(true)?,
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

    fn run(input: HostInput, _: &Context) -> Result<HostOutcome, CiHostError> {
        host(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn acl_rules_compare_without_the_empty_fields_incus_adds() {
        let answered = json!([
            {"action": "reject", "destination": "0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.168.0.0/16,198.18.0.0/15,224.0.0.0/3", "state": "enabled", "source": "", "description": ""},
            {"action": "reject", "destination": "::/0", "state": "enabled", "protocol": ""},
            {"action": "allow", "destination": "0.0.0.0/0", "state": "enabled"},
        ]);
        assert_eq!(rules(&answered), acl_rules());
        assert_ne!(rules(&json!([])), acl_rules());
    }

    #[test]
    fn config_matches_only_when_every_reserved_key_has_its_value() {
        let mut config = Map::new();
        for (key, value) in NETWORK_CONFIG {
            config.insert(key.into(), Value::String(value.into()));
        }
        config.insert("ipv4.address".into(), json!("10.9.0.1/24"));
        assert!(includes(&Value::Object(config.clone()), &NETWORK_CONFIG));
        config.insert("security.acls".into(), json!("other"));
        assert!(!includes(&Value::Object(config), &NETWORK_CONFIG));
    }

    #[test]
    fn default_scope_applies_to_networks_and_acls_only() {
        assert_eq!(
            default_path("networks", Some(NETWORK)),
            "/1.0/networks/orbitci0?project=default"
        );
        assert_eq!(default_path("projects", None), "/1.0/projects");
    }
}

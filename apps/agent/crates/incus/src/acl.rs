//! `incus.acl.ensure`: one network ACL in one project, with exactly the given rules, description and configuration, or absent.

use std::collections::BTreeMap;

use serde::Deserialize;
use serde_json::{json, Map, Value};

use crate::{connect, input, not_converged, plain, Failure, State, NAME};

/// The fields of one ACL rule, as Incus keeps them: each a string, empty when unset.
const RULE_FIELDS: [&str; 10] = [
    "action",
    "state",
    "description",
    "source",
    "destination",
    "protocol",
    "source_port",
    "destination_port",
    "icmp_type",
    "icmp_code",
];

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    #[serde(default)]
    socket: Option<String>,
    project: String,
    name: String,
    state: State,
    timeout_ms: u64,
    #[serde(default)]
    description: String,
    #[serde(default)]
    config: BTreeMap<String, String>,
    #[serde(default)]
    ingress: Vec<Rule>,
    #[serde(default)]
    egress: Vec<Rule>,
}

#[derive(Debug, Deserialize, serde::Serialize)]
#[serde(deny_unknown_fields)]
struct Rule {
    action: String,
    state: String,
    #[serde(default)]
    description: String,
    #[serde(default)]
    source: String,
    #[serde(default)]
    destination: String,
    #[serde(default)]
    protocol: String,
    #[serde(default)]
    source_port: String,
    #[serde(default)]
    destination_port: String,
    #[serde(default)]
    icmp_type: String,
    #[serde(default)]
    icmp_code: String,
}

/// Ensures one network ACL.
///
/// Present: creates the ACL, or replaces its description, configuration and rules when any differs, under the `ETag` it
/// read. Absent: deletes it only when nothing uses it. Answers the number of changes, 0 on a repeat.
///
/// # Errors
///
/// `incus.input_invalid`, `incus.acl_in_use`, `incus.operation_timeout`, `incus.api_failed` or
/// `incus.not_converged`.
pub fn acl_ensure(raw: &Value) -> Result<u32, Failure> {
    let input: Input = input(raw)?;
    for rule in input.ingress.iter().chain(&input.egress) {
        if !["allow", "allow-stateless", "drop", "reject"].contains(&rule.action.as_str())
            || !["enabled", "disabled", "logged"].contains(&rule.state.as_str())
        {
            return Err(Failure::new(
                "incus.input_invalid",
                "a rule's action or state is not one Incus knows",
            ));
        }
    }
    // Incus stores a rule's fields and the configuration trimmed, with an empty value as unset; a form it would store
    // otherwise never reads back equal, so it refuses before any request.
    let list = |value: &str| value.is_empty() || value.split(',').all(plain);
    let rules_plain = input.ingress.iter().chain(&input.egress).all(|rule| {
        (rule.description.is_empty() || plain(&rule.description))
            && [&rule.source, &rule.destination, &rule.source_port, &rule.destination_port].into_iter().all(|value| list(value))
            && [&rule.protocol, &rule.icmp_type, &rule.icmp_code].into_iter().all(|value| value.is_empty() || plain(value))
    });
    if !rules_plain || (!input.description.is_empty() && !plain(&input.description)) || input.config.iter().any(|(key, value)| !NAME.is_match(key) || !plain(value)) {
        return Err(Failure::new("incus.input_invalid", "a value is padded, empty in a list, or an empty config value"));
    }
    if input.state == State::Absent
        && (!input.config.is_empty()
            || !input.ingress.is_empty()
            || !input.egress.is_empty()
            || !input.description.is_empty())
    {
        return Err(Failure::new(
            "incus.input_invalid",
            "absent takes no description, config or rules",
        ));
    }
    let client = connect(
        input.socket.as_deref(),
        input.timeout_ms,
        &[&input.project, &input.name],
    )?;
    let path = format!("/1.0/network-acls/{}?project={}", input.name, input.project);
    let found = client.get(&path)?;
    match input.state {
        State::Absent => {
            let Some((acl, _)) = found else { return Ok(0) };
            if acl["used_by"]
                .as_array()
                .is_some_and(|used| !used.is_empty())
            {
                return Err(Failure::new(
                    "incus.acl_in_use",
                    format!("{} is used by {}", input.name, acl["used_by"]),
                ));
            }
            client.change("DELETE", &path, None, None)?;
            match client.get(&path)? {
                None => Ok(1),
                Some(_) => Err(not_converged(&input.name)),
            }
        }
        State::Present => {
            let desired = desired(&input);
            let change = match &found {
                Some((acl, _)) if same(acl, &desired) => return Ok(0),
                Some((_, etag)) => client.change("PUT", &path, Some(&desired), etag.as_deref()),
                None => {
                    let mut create = desired.clone();
                    create["name"] = json!(input.name);
                    client.change(
                        "POST",
                        &format!("/1.0/network-acls?project={}", input.project),
                        Some(&create),
                        None,
                    )
                }
            };
            change?;
            match client.get(&path)? {
                Some((acl, _)) if same(&acl, &desired) => Ok(1),
                _ => Err(not_converged(&input.name)),
            }
        }
    }
}

fn desired(input: &Input) -> Value {
    json!({
        "description": input.description,
        "config": input.config,
        "ingress": input.ingress,
        "egress": input.egress,
    })
}

/// Whether the ACL Incus holds has the desired description, configuration and rules, each rule field by field.
fn same(acl: &Value, desired: &Value) -> bool {
    let rules = |value: &Value| -> Vec<Map<String, Value>> {
        value
            .as_array()
            .map(|rules| {
                rules
                    .iter()
                    .map(|rule| {
                        RULE_FIELDS
                            .iter()
                            .map(|field| {
                                (
                                    (*field).to_owned(),
                                    rule.get(*field).cloned().unwrap_or_else(|| json!("")),
                                )
                            })
                            .collect()
                    })
                    .collect()
            })
            .unwrap_or_default()
    };
    let config = |value: &Value| value.as_object().cloned().unwrap_or_default();
    acl["description"].as_str().unwrap_or("") == desired["description"].as_str().unwrap_or("")
        && config(&acl["config"]) == config(&desired["config"])
        && rules(&acl["ingress"]) == rules(&desired["ingress"])
        && rules(&acl["egress"]) == rules(&desired["egress"])
}

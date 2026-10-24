use super::inventory::{self, Backend, Ownership, Row, Shape};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "firewall.input_invalid", "The firewall intent is invalid.";
        Status {} => "firewall.status_failed", "The Node firewall could not be inspected.";
        StatusUnrecognized {} => "firewall.status_unrecognized", "UFW returned an unrecognized status.";
        Collision {} => "firewall.rule_collision", "The managed comment identifies conflicting UFW rules.";
        Apply {} => "firewall.apply_failed", "The firewall rule could not be applied.";
        Reconcile {} => "firewall.reconcile_failed", "The preceding firewall rule could not be replaced.";
        Verify {} => "firewall.verify_failed", "The firewall rule did not match after activation.";
        Remove {} => "firewall.remove_failed", "The firewall rule could not be removed.";
        RemoveVerify {} => "firewall.remove_verify_failed", "The firewall rule remained after removal.";
    }
}

trait Host {
    fn ufw(&mut self, args: &[String]) -> io::Result<(i32, String)>;
    fn stored(&mut self) -> io::Result<Vec<Row>>;
}
struct Native;
impl Host for Native {
    fn ufw(&mut self, args: &[String]) -> io::Result<(i32, String)> {
        let output = Process::new("/usr/sbin/ufw")
            .args(args)
            .stdin(Stdio::null())
            .output()?;
        if output.stdout.len() > 1024 * 1024 || output.stderr.len() > 65536 {
            return Err(io::Error::other("UFW observation exceeded its bound"));
        }
        Ok((
            output.status.code().unwrap_or(-1),
            String::from_utf8(output.stdout).map_err(io::Error::other)?,
        ))
    }
    fn stored(&mut self) -> io::Result<Vec<Row>> {
        let mut rows = vec![];
        for (path, family) in [
            ("/etc/ufw/user.rules", "v4"),
            ("/etc/ufw/user6.rules", "v6"),
        ] {
            let content = fs::read_to_string(path)?;
            if content.len() > 1024 * 1024 {
                return Err(io::Error::other("UFW rules exceeded their bound"));
            }
            rows.extend(inventory::stored(&content, family));
        }
        Ok(rows)
    }
}

fn status(host: &mut impl Host) -> Result<(Backend, Vec<Row>), Error> {
    let (code, output) = match host.ufw(&["status".into(), "numbered".into()]) {
        Err(e) if e.kind() == io::ErrorKind::NotFound => return Ok((Backend::Absent, vec![])),
        result => result.map_err(|_| Error::Status {})?,
    };
    if code != 0 {
        return Err(Error::Status {});
    }
    inventory::live(&output).ok_or(Error::StatusUnrecognized {})
}
fn change(host: &mut impl Host, args: Vec<String>, error: Error) -> Result<(), Error> {
    match host.ufw(&args) {
        Ok((0, _)) => Ok(()),
        _ => Err(error),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectInput {
    pub expected: Vec<Shape>,
    pub exclusive_families: Vec<String>,
    pub stored: bool,
}
#[derive(Debug, Serialize)]
pub struct Inspection {
    backend: Backend,
    ownerships: Vec<Ownership>,
    matches: bool,
    live: Vec<Shape>,
}
fn inspect(input: &InspectInput, host: &mut impl Host) -> Result<Inspection, Error> {
    if input.expected.len() > 4096
        || input.expected.iter().any(|s| !s.valid())
        || input.exclusive_families.len() > 128
        || input.exclusive_families.iter().any(|s| {
            !s.starts_with("orbit:")
                || s.len() > 256
                || !s.bytes().all(|b| b.is_ascii_graphic() && b != b'#')
        })
    {
        return Err(Error::Invalid {});
    }
    let (backend, mut rows) = status(host)?;
    if input.stored {
        rows = host.stored().map_err(|_| Error::Status {})?;
    }
    let ownerships: Vec<_> = input
        .expected
        .iter()
        .map(|s| inventory::ownership(&rows, s))
        .collect();
    let exclusive = rows
        .iter()
        .filter(|r| {
            input
                .exclusive_families
                .iter()
                .any(|p| inventory::family_comment(&r.comment, p))
        })
        .all(|r| {
            r.shape
                .as_ref()
                .is_some_and(|s| input.expected.iter().any(|e| e.matches(s)))
        });
    let matches = backend == Backend::Active
        && exclusive
        && ownerships.iter().all(|o| *o == Ownership::Exact);
    let live = rows
        .into_iter()
        .filter_map(|r| r.shape)
        .filter(|s| s.family.as_deref() != Some("v6"))
        .collect();
    Ok(Inspection {
        backend,
        ownerships,
        matches,
        live,
    })
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "firewall.inspect";
    const SUMMARY: &'static str = "Inspect typed firewall shapes and exact managed ownership.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = InspectInput;
    type Output = Inspection;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn run(input: InspectInput, _: &Context) -> Result<Inspection, Error> {
        inspect(&input, &mut Native)
    }
}

#[derive(Deserialize, PartialEq)]
#[serde(rename_all = "snake_case")]
enum Operation {
    Converge,
    Remove,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RuleInput {
    node_id: u64,
    name: String,
    action: String,
    source: String,
    port: String,
    protocol: String,
    operation: Operation,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct RuleOutput {
    backend: Backend,
}

fn rule_shape(input: &RuleInput) -> Result<Shape, Error> {
    let name = input.name.as_bytes();
    if input.node_id == 0
        || name.is_empty()
        || name.len() > 63
        || !name[0].is_ascii_alphanumeric()
        || !name[name.len() - 1].is_ascii_alphanumeric()
        || !name
            .iter()
            .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b"._-".contains(b))
        || !["tcp", "udp"].contains(&input.protocol.as_str())
        || inventory::normalize_port(&input.port).as_ref() != Some(&input.port)
    {
        return Err(Error::Invalid {});
    }
    let shape = Shape {
        comment: format!("orbit:node:{}:firewall:{}", input.node_id, input.name),
        action: input.action.clone(),
        direction: "in".into(),
        source: input.source.clone(),
        destination: "any".into(),
        port: input.port.clone(),
        protocol: input.protocol.clone(),
        in_interface: None,
        out_interface: None,
        family: if input.source == "any" {
            None
        } else {
            Some(
                if input.source.contains(':') {
                    "v6"
                } else {
                    "v4"
                }
                .into(),
            )
        },
    };
    if !shape.valid() {
        return Err(Error::Invalid {});
    }
    Ok(shape)
}
fn same_named_shape(expected: &Shape, observed: &Shape) -> bool {
    expected.action == observed.action
        && expected.source == observed.source
        && expected.port == observed.port
        && expected.protocol == observed.protocol
}
fn named_owned<'a>(rows: &'a [Row], expected: &Shape) -> Result<Vec<&'a Shape>, Error> {
    let mut shapes = vec![];
    let mut families = std::collections::BTreeSet::new();
    for row in rows.iter().filter(|r| r.comment == expected.comment) {
        let shape = row.shape.as_ref().ok_or(Error::Collision {})?;
        if shape.direction != "in"
            || shape.destination != "any"
            || shape.port == "any"
            || shape.protocol == "any"
            || shape.in_interface.is_some()
            || shape.out_interface.is_some()
            || !families.insert(&shape.family)
        {
            return Err(Error::Collision {});
        }
        shapes.push(shape);
    }
    if (shapes.len() > 1 && shapes.iter().any(|s| !same_named_shape(expected, s)))
        || shapes.len() > if expected.family.is_none() { 2 } else { 1 }
    {
        return Err(Error::Collision {});
    }
    Ok(shapes)
}
fn rule_arguments(shape: &Shape, remove: bool, observed: bool) -> Vec<String> {
    let mut args = if remove {
        vec!["delete".into()]
    } else if shape.action == "allow" {
        vec!["prepend".into()]
    } else {
        vec![]
    };
    let source = if observed && shape.source == "any" {
        if shape.family.as_deref() == Some("v6") {
            "::/0"
        } else {
            "0.0.0.0/0"
        }
    } else {
        &shape.source
    };
    let destination = if observed && shape.family.as_deref() == Some("v6") {
        "::/0"
    } else {
        "any"
    };
    args.extend(
        [
            shape.action.as_str(),
            "in",
            "from",
            source,
            "to",
            destination,
            "port",
            &shape.port,
            "proto",
            &shape.protocol,
            "comment",
            &shape.comment,
        ]
        .map(str::to_owned),
    );
    args
}
fn named_rule(input: &RuleInput, host: &mut impl Host) -> Result<RuleOutput, Error> {
    let expected = rule_shape(input)?;
    let (backend, rows) = status(host)?;
    if backend == Backend::Inactive {
        return Ok(RuleOutput { backend });
    }
    if backend != Backend::Active {
        return Err(Error::Status {});
    }
    let owned = named_owned(&rows, &expected)?;
    if input.operation == Operation::Remove {
        if owned.is_empty() {
            return Ok(RuleOutput {
                backend: Backend::Absent,
            });
        }
        let exact = owned.iter().all(|s| same_named_shape(&expected, s));
        change(
            host,
            rule_arguments(if exact { &expected } else { owned[0] }, true, !exact),
            Error::Remove {},
        )?;
        let (_, rows) = status(host)?;
        if !named_owned(&rows, &expected)?.is_empty() {
            return Err(Error::RemoveVerify {});
        }
        return Ok(RuleOutput {
            backend: Backend::Absent,
        });
    }
    if inventory::ownership(&rows, &expected) == Ownership::Exact {
        return Ok(RuleOutput { backend });
    }
    if !owned.is_empty() && !owned.iter().all(|s| same_named_shape(&expected, s)) {
        change(
            host,
            rule_arguments(owned[0], true, true),
            Error::Reconcile {},
        )?;
    }
    change(
        host,
        rule_arguments(&expected, false, false),
        Error::Apply {},
    )?;
    let (backend, rows) = status(host)?;
    named_owned(&rows, &expected)?;
    if backend != Backend::Active || inventory::ownership(&rows, &expected) != Ownership::Exact {
        return Err(Error::Verify {});
    }
    Ok(RuleOutput { backend })
}

pub struct Rule;
impl Command for Rule {
    const NAME: &'static str = "firewall.rule";
    const SUMMARY: &'static str = "Converge or remove one owned named firewall rule.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["firewall"];
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = RuleInput;
    type Output = RuleOutput;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn target(input: &RuleInput) -> Option<String> {
        Some(format!("firewall:{}:{}", input.node_id, input.name))
    }
    fn run(input: RuleInput, _: &Context) -> Result<RuleOutput, Error> {
        named_rule(&input, &mut Native)
    }
}

#[cfg(test)]
#[path = "tests.rs"]
mod tests;

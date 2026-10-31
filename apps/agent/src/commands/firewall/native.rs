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
        Convergence {} => "node.firewall_convergence_failed", "The managed firewall rules could not be reconciled while preserving SSH access.";
        Recovery {} => "router.lan_ingress_recovery_failed", "The preceding Router LAN firewall rules could not be restored.";
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

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectInput {
    pub expected: Vec<Shape>,
    pub exclusive_families: Vec<String>,
    pub stored: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
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

#[cfg(test)]
#[path = "tests.rs"]
mod tests;

#[path = "rules.rs"]
pub mod rules;

#[path = "route.rs"]
pub mod route;

#[path = "gateway.rs"]
pub mod gateway;

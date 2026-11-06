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

#[cfg(test)]
#[path = "tests.rs"]
mod tests;

#[path = "rules.rs"]
pub mod rules;

#[path = "route.rs"]
pub mod route;

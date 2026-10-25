//! Observe an untrusted target from the Gateway or its managed jump Node.
use super::incus::{bounded, Output};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{net::IpAddr, time::Duration};

command_errors! {
    pub enum Error {
        Invalid {} => "nodes.host_key_input_invalid", "The host key scan needs one host and a nonzero port.";
        Failed { stage: String, exit_code: i32, stderr: String } => "nodes.host_key_scan_failed", "The SSH host key could not be scanned or fingerprinted.";
        Malformed {} => "nodes.host_key_invalid", "The SSH host returned no valid supported host key.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    host: String,
    port: u16,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct HostKey {
    r#type: String,
    value: String,
    fingerprint: String,
}

trait Host {
    fn run(&mut self, program: &str, args: &[&str], input: Option<&str>) -> Output;
}
struct Native<'a>(&'a Context);
impl Host for Native<'_> {
    fn run(&mut self, program: &str, args: &[&str], input: Option<&str>) -> Output {
        bounded(self.0, program, args, input)
    }
}

fn checked(output: Output, stage: &str) -> Result<String, Error> {
    if output.code != 0 || output.truncated {
        return Err(Error::Failed {
            stage: stage.into(),
            exit_code: output.code,
            stderr: if output.truncated {
                "The command output is incomplete.".into()
            } else {
                super::recipe::diagnostic(&output.stderr)
            },
        });
    }
    Ok(output.stdout)
}

fn scan(input: Input, host: &mut impl Host) -> Result<HostKey, Error> {
    let name = input.host.strip_suffix('.').unwrap_or(&input.host);
    let valid_host = input.host.parse::<IpAddr>().is_ok()
        || (!name.is_empty()
            && input.host.len() <= 253
            && name.split('.').all(|label| {
                !label.is_empty()
                    && label.len() <= 63
                    && !label.starts_with('-')
                    && !label.ends_with('-')
                    && label
                        .bytes()
                        .all(|b| b.is_ascii_alphanumeric() || b == b'-')
            }));
    if input.port == 0 || !valid_host {
        return Err(Error::Invalid {});
    }
    let output = checked(
        host.run(
            "/usr/bin/ssh-keyscan",
            &["-T", "10", "-p", &input.port.to_string(), "--", &input.host],
            None,
        ),
        "scan",
    )?;
    let (line, key_type, value) = ["ssh-ed25519", "ecdsa-sha2-nistp256", "ssh-rsa"]
        .iter()
        .find_map(|wanted| {
            output.lines().find_map(|line| {
                let fields: Vec<_> = line.split_whitespace().collect();
                (fields.len() == 3 && !fields[0].starts_with('#') && fields[1] == *wanted)
                    .then(|| (line, fields[1], fields[2]))
            })
        })
        .ok_or(Error::Malformed {})?;
    let fingerprint = checked(
        host.run(
            "/usr/bin/ssh-keygen",
            &["-lf", "-", "-E", "sha256"],
            Some(&format!("{line}\n")),
        ),
        "fingerprint",
    )?;
    let fingerprint = fingerprint
        .split_whitespace()
        .nth(1)
        .filter(|value| {
            value.strip_prefix("SHA256:").is_some_and(|digest| {
                digest.len() == 43
                    && digest
                        .bytes()
                        .all(|b| b.is_ascii_alphanumeric() || b"+/".contains(&b))
            })
        })
        .ok_or(Error::Malformed {})?;
    Ok(HostKey {
        r#type: key_type.into(),
        value: value.into(),
        fingerprint: fingerprint.into(),
    })
}

pub struct Scan;
impl Command for Scan {
    const NAME: &'static str = "nodes.scan-host-key";
    const SUMMARY: &'static str = "Scan and fingerprint a target before trusting its SSH identity.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(25);
    type Input = Input;
    type Output = HostKey;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Input, context: &Context) -> Result<HostKey, Error> {
        scan(input, &mut Native(context))
    }
}

#[cfg(test)]
mod tests;

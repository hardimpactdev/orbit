//! The Gateway's suffix-only resolver route. Managed peers retain their own DNS policy.
use super::{peer_dns, recipe::Files};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    net::IpAddr,
    os::unix::fs::MetadataExt,
    path::Path,
    process::{Command as Process, Stdio},
    time::Duration,
};

const DROP_IN: &str = "/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf";
const PEER_STATE: &str = "/etc/wireguard/orbit.dns-link";
const LOCK: &str = "/run/lock/orbit-wireguard-peer.lock";
command_errors! {
    pub enum RouteError {
        Invalid {} => "node.gateway_dns_invalid", "The Gateway DNS route or its ownership is invalid.";
        Busy {} => "node.gateway_dns_busy", "Another WireGuard change holds the peer lock.";
        Failed { step: String } => "vpn.dns_resolver_failed", "The Gateway private DNS route did not complete.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "action", rename_all = "snake_case", deny_unknown_fields)]
pub enum Input {
    Converge { address: String, domain: String },
    Remove {},
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Routed {
    pub configured: bool,
    pub changed: bool,
}
trait Host {
    fn run(&mut self, name: &str, args: &[&str]) -> bool;
}
struct Native;
impl Host for Native {
    fn run(&mut self, name: &str, args: &[&str]) -> bool {
        Process::new(name)
            .args(args)
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .is_ok_and(|status| status.success())
    }
}
fn failure(step: &str) -> RouteError {
    RouteError::Failed { step: step.into() }
}
fn directory(files: &Files, path: &Path) -> Result<(), RouteError> {
    if path == files.root {
        return Ok(());
    }
    directory(files, path.parent().ok_or(RouteError::Invalid {})?)?;
    files
        .directory(path, files.uid, files.gid)
        .map_err(|_| RouteError::Invalid {})
}
fn drop_in(address: &str, domain: &str) -> Result<String, RouteError> {
    let hostname = domain.strip_suffix('.').unwrap_or(domain);
    if address.parse::<IpAddr>().is_err()
        || hostname.is_empty()
        || hostname.len() > 253
        || hostname.split('.').any(|label| {
            label.is_empty()
                || label.len() > 63
                || !label.as_bytes()[0].is_ascii_alphanumeric()
                || !label.as_bytes()[label.len() - 1].is_ascii_alphanumeric()
                || !label
                    .bytes()
                    .all(|byte| byte.is_ascii_alphanumeric() || byte == b'-')
        })
    {
        return Err(RouteError::Invalid {});
    }
    Ok(format!(
        r#"# Managed by Orbit.
[Service]
ExecStartPost=-/bin/sh -c 'test -e {PEER_STATE} || {{ resolvectl domain orbit "~{domain}" && resolvectl default-route orbit false && resolvectl dns orbit {address}; }}'
"#
    ))
}
fn apply(input: &Input, files: &Files, host: &mut impl Host) -> Result<Routed, RouteError> {
    let expected = match input {
        Input::Converge { address, domain } => Some(drop_in(address, domain)?),
        Input::Remove {} => None,
    };
    let path = files.path(DROP_IN);
    directory(files, path.parent().unwrap())?;
    directory(files, files.path(LOCK).parent().unwrap())?;
    let _lock = peer_dns::hold(&files.path(LOCK), Duration::from_secs(30), true)
        .map_err(|_| RouteError::Invalid {})?
        .ok_or(RouteError::Busy {})?;
    let prior = match fs::symlink_metadata(&path) {
        Ok(meta)
            if meta.is_file()
                && meta.uid() == files.uid
                && meta.gid() == files.gid
                && meta.mode() & 0o022 == 0 =>
        {
            let text = fs::read_to_string(&path).map_err(|_| RouteError::Invalid {})?;
            if !text.starts_with("# Managed by Orbit.\n[Service]\n") {
                return Err(RouteError::Invalid {});
            }
            Some(text)
        }
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => None,
        _ => return Err(RouteError::Invalid {}),
    };
    let changed = prior != expected;
    match expected {
        Some(ref text) if changed => files
            .publish(&path, text.as_bytes(), 0o644, files.uid, files.gid, false)
            .map_err(|_| failure("publish route"))?,
        None if changed => fs::remove_file(&path).map_err(|_| failure("remove route"))?,
        _ => {}
    }
    if changed && !host.run("systemctl", &["daemon-reload"]) {
        return Err(failure("reload units"));
    }
    if matches!(input, Input::Remove {}) && prior.is_none() {
        return Ok(Routed {
            configured: false,
            changed,
        });
    }
    if fs::symlink_metadata(files.path(PEER_STATE)).is_ok()
        || !host.run("ip", &["link", "show", "dev", "orbit"])
    {
        return Ok(Routed {
            configured: expected.is_some(),
            changed,
        });
    }
    let success = match input {
        Input::Converge { address, domain } => {
            host.run("resolvectl", &["domain", "orbit", &format!("~{domain}")])
                && host.run("resolvectl", &["default-route", "orbit", "false"])
                && host.run("resolvectl", &["dns", "orbit", address])
        }
        Input::Remove {} => host.run("resolvectl", &["revert", "orbit"]),
    };
    if !success {
        return Err(failure("route resolver"));
    }
    Ok(Routed {
        configured: expected.is_some(),
        changed,
    })
}
pub struct Route;
impl Command for Route {
    const NAME: &'static str = "wireguard.gateway-dns";
    const SUMMARY: &'static str =
        "Publish or remove the Gateway's suffix-only private DNS resolver route.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Routed;
    type Error = RouteError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, _: &Context) -> Result<Routed, RouteError> {
        apply(&input, &Files::system(), &mut Native)
    }
}
#[cfg(test)]
#[path = "node_gateway_dns_tests.rs"]
mod tests;

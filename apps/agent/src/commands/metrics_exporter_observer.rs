//! Read-only node exporter observation. Swamp owns all exporter mutations.
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    net::Ipv4Addr,
    os::unix::fs::MetadataExt,
    path::Path,
    process::{Command as Process, Stdio},
    time::Duration,
};
const CONFIG: &str = "/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf";
const MARKER: &str = "# Managed by Orbit: metrics\n";
const COMMENT: &str = "orbit:metrics-node-exporter";
command_errors! {
    pub enum Error {
        Invalid {} => "metrics.exporter_address_invalid", "Exporter observation needs Node and Metrics IPv4 addresses.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    address: String,
    metrics_address: String,
}
#[derive(schemars::JsonSchema, Serialize)]
pub struct Output {
    actual: &'static str,
}
fn firewall(text: &str, address: Ipv4Addr) -> Result<Option<Ipv4Addr>, ()> {
    if !text
        .lines()
        .any(|line| line.trim().eq_ignore_ascii_case("Status: active"))
    {
        return Err(());
    }
    let mut found = None;
    for line in text.lines() {
        let Some((body, comment)) = line.rsplit_once('#') else {
            continue;
        };
        if comment.trim() != COMMENT {
            continue;
        }
        if found.is_some() {
            return Err(());
        }
        let body = body.trim().strip_prefix('[').ok_or(())?;
        let (number, body) = body.split_once(']').ok_or(())?;
        if number.trim().parse::<u32>().map_err(|_| ())? == 0 {
            return Err(());
        }
        let words: Vec<_> = body.split_whitespace().collect();
        let (destination, port, source) = match words.as_slice() {
            [dest, port, "on", "orbit", "ALLOW", "IN", source]
            | [dest, port, "ALLOW", "IN", source, "on", "orbit"] => (*dest, *port, *source),
            _ => return Err(()),
        };
        let host = |value: &str| {
            value
                .strip_suffix("/32")
                .unwrap_or(value)
                .parse::<Ipv4Addr>()
                .map_err(|_| ())
        };
        if host(destination)? != address || port != "9100/tcp" {
            return Err(());
        }
        found = Some(host(source)?);
    }
    Ok(found)
}
fn command(program: &str, args: &[&str]) -> Option<(i32, String)> {
    let output = Process::new("/usr/bin/timeout")
        .args(["--kill-after=2s", "10s", program])
        .args(args)
        .env("LC_ALL", "C")
        .stdin(Stdio::null())
        .stderr(Stdio::null())
        .output()
        .ok()?;
    if output.stdout.len() > 65536 {
        return None;
    }
    Some((
        output.status.code()?,
        String::from_utf8(output.stdout).ok()?,
    ))
}
fn configuration() -> Result<bool, ()> {
    let path = Path::new(CONFIG);
    for parent in path.ancestors().skip(1) {
        if let Ok(meta) = fs::symlink_metadata(parent) {
            if !meta.is_dir() || meta.uid() != 0 || meta.mode() & 0o022 != 0 {
                return Err(());
            }
        }
    }
    let meta = match fs::symlink_metadata(path) {
        Ok(meta) => meta,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(false),
        Err(_) => return Err(()),
    };
    if !meta.is_file() || meta.uid() != 0 || meta.mode() & 0o022 != 0 || meta.len() > 65536 {
        return Err(());
    }
    fs::read_to_string(path)
        .map(|value| value.starts_with(MARKER))
        .map_err(|_| ())
        .and_then(|marked| if marked { Ok(true) } else { Err(()) })
}
fn actual(
    configuration: Result<bool, ()>,
    rule: Result<Option<Ipv4Addr>, ()>,
    active: Option<bool>,
    metrics: Ipv4Addr,
) -> &'static str {
    match (configuration, rule) {
        (Err(_), _) | (_, Err(_)) => "drift",
        (Ok(false), Ok(None)) => "inactive",
        (Ok(false), _) => "drift",
        (Ok(true), Ok(source)) if source != Some(metrics) => "drift",
        _ => match active {
            Some(true) => "active",
            Some(false) => "inactive",
            None => "unknown",
        },
    }
}
pub struct ExporterState;
impl Command for ExporterState {
    const NAME: &'static str = "metrics.exporter-state";
    const SUMMARY: &'static str =
        "Observe node exporter ownership, firewall, and service state without changing it.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        let address = input
            .address
            .parse::<Ipv4Addr>()
            .map_err(|_| Error::Invalid {})?;
        let metrics = input
            .metrics_address
            .parse::<Ipv4Addr>()
            .map_err(|_| Error::Invalid {})?;
        let Some((0, text)) = command("/usr/sbin/ufw", &["status", "numbered"]) else {
            return Ok(Output { actual: "unknown" });
        };
        let active = match command(
            "/usr/bin/systemctl",
            &["is-active", "prometheus-node-exporter"],
        ) {
            Some((0, value)) if value.trim() == "active" => Some(true),
            Some((3 | 4, _)) => Some(false),
            _ => None,
        };
        Ok(Output {
            actual: actual(configuration(), firewall(&text, address), active, metrics),
        })
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn ownership_rule_shape_and_source_are_observed_without_effects() {
        let node = "10.44.0.13".parse().unwrap();
        let metrics = "10.44.0.9".parse().unwrap();
        let line =
            "[ 3] 10.44.0.13 9100/tcp on orbit ALLOW IN 10.44.0.9 # orbit:metrics-node-exporter";
        let status = format!("Status: active\n{line}\n");
        assert_eq!(firewall(&status, node), Ok(Some(metrics)));
        assert_eq!(
            actual(Ok(true), firewall(&status, node), Some(true), metrics),
            "active"
        );
        for invalid in [
            format!("{status}{line}\n"),
            status.replace("on orbit", "on eth0"),
            status.replace("9100/tcp", "9200/tcp"),
        ] {
            assert_eq!(firewall(&invalid, node), Err(()));
        }
        assert_eq!(actual(Err(()), Ok(None), Some(true), metrics), "drift");
        assert_eq!(
            actual(Ok(false), Ok(None), Some(false), metrics),
            "inactive"
        );
        assert_eq!(
            actual(Ok(true), Ok(Some(metrics)), None, metrics),
            "unknown"
        );
    }
    #[test]
    fn observation_input_has_no_execution_action_or_mutation_selector() {
        assert!(serde_json::from_value::<Input>(serde_json::json!({"address":"10.44.0.13","metrics_address":"10.44.0.9","action":{"operation":"remove"}})).is_err());
        assert!(!ExporterState::MUTATES);
    }
}

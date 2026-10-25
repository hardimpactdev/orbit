//! The reserved task VM range's filter on the WireGuard hub.
use super::incus::{bounded, Output};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::{
    fs,
    io::{self, Write},
    net::{Ipv4Addr, SocketAddrV4},
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    time::Duration,
};

const TABLE: &str = "orbit_task_vms";
const UNIT: &str = "orbit-task-vms-hub.service";
const MARKER: &str = "# Managed by Orbit (task VMs). Filters only the reserved range ";
const UNIT_TEXT: &str = "[Unit]\nDescription=Orbit task VM filter on the WireGuard hub\nDefaultDependencies=no\nAfter=local-fs.target nftables.service\nBefore=wg-quick@orbit.service\n\n[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/usr/sbin/nft -f /etc/orbit/task-vms/hub.nft\n\n[Install]\nWantedBy=wg-quick@orbit.service multi-user.target\n";

command_errors! {
    pub enum Error {
        Failed { stage: String, reason: String } => "task_vm.setup_failed", "The task VM hub filter setup failed.";
    }
}
fn failed(stage: &str, reason: impl Into<String>) -> Error {
    Error::Failed {
        stage: stage.into(),
        reason: reason.into(),
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    range: String,
    dns: Ipv4Addr,
    gateway: Ipv4Addr,
    pi_port: u16,
    reverb: SocketAddrV4,
    model: SocketAddrV4,
    routers: Vec<Ipv4Addr>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Prepared {
    ready: bool,
}

fn render(input: &Input) -> Result<String, Error> {
    let subnet = input
        .range
        .split_once('/')
        .and_then(|(address, prefix)| {
            Some((
                address.parse::<Ipv4Addr>().ok()?,
                prefix.parse::<u32>().ok()?,
            ))
        })
        .filter(|(address, prefix)| {
            (16..=30).contains(prefix)
                && u32::from(*address) & (u32::MAX << (32 - prefix)) == u32::from(*address)
        });
    if subnet.is_none()
        || input.pi_port == 0
        || input.reverb.port() == 0
        || input.model.port() == 0
        || input.routers.is_empty()
    {
        return Err(failed(
            "input",
            "The network, endpoints, ports, or routers are invalid.",
        ));
    }
    let (address, prefix) = subnet.unwrap();
    let range = format!("{address}/{prefix}");
    let dns = input.dns;
    let gateway = input.gateway;
    let pi = input.pi_port;
    let reverb = input.reverb.ip();
    let reverb_port = input.reverb.port();
    let model = input.model.ip();
    let model_port = input.model.port();
    let routers = input
        .routers
        .iter()
        .map(ToString::to_string)
        .collect::<Vec<_>>()
        .join(",");
    Ok(format!(
        "{MARKER}{range}.\n\
table inet {TABLE}\n\
delete table inet {TABLE}\n\n\
table inet {TABLE} {{
    chain forward {{
        type filter hook forward priority -5; policy accept;
        meta nfproto != ipv4 accept
        ip saddr != {range} ip daddr != {range} accept
        ct state established,related accept
        ip saddr {range} ip daddr {gateway} tcp dport 443 accept
        ip saddr {range} ip daddr {reverb} tcp dport {reverb_port} accept
        ip saddr {range} ip daddr {model} tcp dport {model_port} accept
        ip saddr {gateway} ip daddr {range} tcp dport {{ 22, {pi} }} accept
        ip saddr {{ {routers} }} ip daddr {range} tcp dport {{ 80, 443, 5173 }} accept
        ip saddr {range} drop
        ip daddr {range} drop
    }}

    chain input {{
        type filter hook input priority -5; policy accept;
        meta nfproto != ipv4 accept
        ip saddr != {range} accept
        ct state established,related accept
        ip daddr {gateway} tcp dport 443 accept
        ip daddr {reverb} tcp dport {reverb_port} accept
        ip daddr {model} tcp dport {model_port} accept
        ip daddr {dns} udp dport 53 accept
        ip daddr {dns} tcp dport 53 accept
        drop
    }}
}}
"
    ))
}

#[derive(Clone, Copy, Debug, PartialEq)]
enum Tool {
    Nft,
    Systemctl,
}
trait Host {
    fn run(&mut self, tool: Tool, args: &[&str], input: Option<&str>) -> Output;
}
struct Native<'a>(&'a Context);
impl Host for Native<'_> {
    fn run(&mut self, tool: Tool, args: &[&str], input: Option<&str>) -> Output {
        bounded(
            self.0,
            match tool {
                Tool::Nft => "/usr/sbin/nft",
                Tool::Systemctl => "/usr/bin/systemctl",
            },
            args,
            input,
        )
    }
}
fn call(
    host: &mut impl Host,
    tool: Tool,
    args: &[&str],
    input: Option<&str>,
    stage: &str,
) -> Result<String, Error> {
    let output = host.run(tool, args, input);
    if output.code != 0 || output.truncated {
        return Err(failed(
            stage,
            if output.truncated {
                "Host output is incomplete.".into()
            } else {
                output.stderr.chars().take(2000).collect::<String>()
            },
        ));
    }
    Ok(output.stdout)
}
fn service_state(host: &mut impl Host, enabled: bool) -> Result<bool, Error> {
    let output = host.run(
        Tool::Systemctl,
        &[if enabled { "is-enabled" } else { "is-active" }, UNIT],
        None,
    );
    match (enabled, output.code, output.stdout.trim(), output.truncated) {
        (true, 0, "enabled", false) | (false, 0, "active", false) => Ok(true),
        (true, 1, "disabled" | "not-found", false)
        | (true, 4, "not-found", false)
        | (false, 3 | 4, "inactive" | "failed" | "unknown", false) => Ok(false),
        _ => Err(failed(
            "inspect",
            "The hub filter service state cannot be read.",
        )),
    }
}
fn live_table(host: &mut impl Host) -> Result<Option<String>, Error> {
    let tables: Value = serde_json::from_str(&call(
        host,
        Tool::Nft,
        &["-j", "list", "tables"],
        None,
        "inspect",
    )?)
    .map_err(|_| failed("inspect", "The nft table list is invalid."))?;
    let rows = tables
        .get("nftables")
        .and_then(Value::as_array)
        .ok_or_else(|| failed("inspect", "The nft table list is missing."))?;
    let mut exists = false;
    for row in rows {
        if row.get("metainfo").is_some() {
            continue;
        }
        let table = row
            .get("table")
            .ok_or_else(|| failed("inspect", "The nft table entry is invalid."))?;
        let family = table
            .get("family")
            .and_then(Value::as_str)
            .ok_or_else(|| failed("inspect", "The nft family is missing."))?;
        let name = table
            .get("name")
            .and_then(Value::as_str)
            .ok_or_else(|| failed("inspect", "The nft table name is missing."))?;
        if family == "inet" && name == TABLE {
            exists = true;
        }
    }
    if exists {
        Ok(Some(call(
            host,
            Tool::Nft,
            &["list", "table", "inet", TABLE],
            None,
            "inspect",
        )?))
    } else {
        Ok(None)
    }
}
struct FileState {
    bytes: Vec<u8>,
    mode: u32,
    uid: u32,
    gid: u32,
}
fn snapshot(path: &Path, uid: u32, unit: bool) -> Result<Option<FileState>, Error> {
    let metadata = match fs::symlink_metadata(path) {
        Ok(metadata) => metadata,
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(None),
        Err(error) => return Err(failed("inspect", error.to_string())),
    };
    if !metadata.is_file() || metadata.uid() != uid {
        return Err(failed(
            "ownership",
            "The hub filter path is not an owned regular file.",
        ));
    }
    let bytes = fs::read(path).map_err(|e| failed("inspect", e.to_string()))?;
    let owned = if unit {
        bytes == UNIT_TEXT.as_bytes()
    } else {
        bytes.starts_with(MARKER.as_bytes())
    };
    if !owned {
        return Err(failed(
            "ownership",
            "The hub filter file has foreign content.",
        ));
    }
    Ok(Some(FileState {
        bytes,
        mode: metadata.mode() & 0o7777,
        uid: metadata.uid(),
        gid: metadata.gid(),
    }))
}
fn write(path: &Path, bytes: &[u8], mode: u32) -> io::Result<()> {
    let parent = path.parent().unwrap();
    fs::create_dir_all(parent)?;
    let temporary = parent.join(format!(".orbit-hub-{:016x}", rand::random::<u64>()));
    let result = (|| {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(mode)
            .open(&temporary)?;
        file.write_all(bytes)?;
        file.set_permissions(fs::Permissions::from_mode(mode))?;
        file.sync_all()?;
        fs::rename(&temporary, path)
    })();
    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result
}
fn restore_file(path: &Path, state: &Option<FileState>) -> io::Result<()> {
    match state {
        Some(state) => {
            write(path, &state.bytes, state.mode)?;
            super::caddy::chown(path, state.uid, state.gid)
        }
        None => match fs::remove_file(path) {
            Err(e) if e.kind() != io::ErrorKind::NotFound => Err(e),
            _ => Ok(()),
        },
    }
}
struct Paths {
    rules: PathBuf,
    unit: PathBuf,
    uid: u32,
}
fn prepare(input: &Input, host: &mut impl Host, paths: &Paths) -> Result<Prepared, Error> {
    let rules = render(input)?;
    let old_rules = snapshot(&paths.rules, paths.uid, false)?;
    let old_unit = snapshot(&paths.unit, paths.uid, true)?;
    let old_table = live_table(host)?;
    if old_table.is_some() && old_rules.is_none() {
        return Err(failed(
            "ownership",
            "The live hub table has no owned rules file.",
        ));
    }
    let enabled = service_state(host, true)?;
    let active = service_state(host, false)?;
    call(
        host,
        Tool::Nft,
        &["-c", "-f", "-"],
        Some(&rules),
        "validate",
    )?;
    let result = (|| {
        write(&paths.rules, rules.as_bytes(), 0o644)
            .map_err(|e| failed("publish", e.to_string()))?;
        write(&paths.unit, UNIT_TEXT.as_bytes(), 0o644)
            .map_err(|e| failed("publish", e.to_string()))?;
        call(host, Tool::Systemctl, &["daemon-reload"], None, "activate")?;
        call(
            host,
            Tool::Systemctl,
            &["enable", "--quiet", UNIT],
            None,
            "activate",
        )?;
        call(host, Tool::Systemctl, &["restart", UNIT], None, "activate")?;
        call(
            host,
            Tool::Nft,
            &["list", "table", "inet", TABLE],
            None,
            "verify",
        )?;
        Ok(Prepared { ready: true })
    })();
    if let Err(error) = result {
        // Attempt every restoration even if one fails. A service error must not strand new files or rules.
        let mut recovery_failed = false;
        if paths.unit.exists() {
            if !enabled {
                recovery_failed |= call(
                    host,
                    Tool::Systemctl,
                    &["disable", "--quiet", UNIT],
                    None,
                    "recovery",
                )
                .is_err();
            }
            if !active {
                recovery_failed |=
                    call(host, Tool::Systemctl, &["stop", UNIT], None, "recovery").is_err();
            }
        }
        let rules_restored = restore_file(&paths.rules, &old_rules).is_ok();
        let unit_restored = restore_file(&paths.unit, &old_unit).is_ok();
        recovery_failed |= !rules_restored || !unit_restored;
        recovery_failed |=
            call(host, Tool::Systemctl, &["daemon-reload"], None, "recovery").is_err();
        if rules_restored && unit_restored {
            if enabled {
                recovery_failed |= call(
                    host,
                    Tool::Systemctl,
                    &["enable", "--quiet", UNIT],
                    None,
                    "recovery",
                )
                .is_err();
            }
            if active {
                recovery_failed |=
                    call(host, Tool::Systemctl, &["restart", UNIT], None, "recovery").is_err();
            }
        }
        let restore = format!(
            "table inet {TABLE}\ndelete table inet {TABLE}\n{}",
            old_table.as_deref().unwrap_or("")
        );
        recovery_failed |= call(host, Tool::Nft, &["-f", "-"], Some(&restore), "recovery").is_err();
        if recovery_failed {
            return Err(failed(
                "recovery",
                "The previous hub filter could not be fully restored.",
            ));
        }
        return Err(error);
    }
    result
}

pub struct PrepareHub;
impl Command for PrepareHub {
    const NAME: &'static str = "firewall.task-vm-hub";
    const SUMMARY: &'static str =
        "Converge the reserved task VM range filter on the WireGuard hub.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    const RESOURCES: &'static [&'static str] = &["firewall"];
    type Input = Input;
    type Output = Prepared;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("task-vm-hub".into())
    }
    fn run(input: Input, context: &Context) -> Result<Prepared, Error> {
        prepare(
            &input,
            &mut Native(context),
            &Paths {
                rules: "/etc/orbit/task-vms/hub.nft".into(),
                unit: format!("/etc/systemd/system/{UNIT}").into(),
                uid: 0,
            },
        )
    }
}

#[cfg(test)]
mod tests;

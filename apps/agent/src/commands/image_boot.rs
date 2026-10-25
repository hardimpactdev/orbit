//! Fixed image boot preparation and smoke checks, shared by guest transports.
use super::incus::{bounded, Output};
use crate::{
    command::{identity, Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    path::{Path, PathBuf},
    time::Duration,
};
const MASKS: &[&str] = &[
    "systemd-firstboot.service",
    "apt-daily.timer",
    "apt-daily-upgrade.timer",
    "motd-news.timer",
    "unattended-upgrades.service",
];
const NETWORK: &str = "/etc/systemd/network/00-orbit-task-vm.network";
const WAIT: &str = "/etc/systemd/system/systemd-networkd-wait-online.service.d/zz-orbit-ipv4.conf";
const NETWORK_TEXT: &[u8] = b"[Match]\nType=ether\nName=en*\n\n[Network]\nDHCP=ipv4\nLinkLocalAddressing=no\n\n[DHCP]\nRouteMetric=100\nUseMTU=true\n";
const WAIT_TEXT: &[u8] = b"[Service]\nExecStart=\nExecStart=/usr/lib/systemd/systemd-networkd-wait-online --any --ipv4 --dns --operational-state=routable\n";
command_errors! {
    pub enum Error {
        Failed { stage: String, reason: String } => "task_vm.image_check_failed", "The image check or boot preparation failed.";
    }
}
fn failed(stage: &str, reason: impl ToString) -> Error {
    Error::Failed {
        stage: stage.into(),
        reason: reason.to_string(),
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct BootInput {
    lean: bool,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CheckInput {
    lean_boot: bool,
    firewall: bool,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Ready {
    ready: bool,
}
trait Host {
    fn run(&mut self, program: &'static str, args: &[&str]) -> Output;
    fn account(&self) -> Result<(u32, u32, String), String>;
}
struct Native<'a>(&'a Context);
impl Host for Native<'_> {
    fn run(&mut self, program: &'static str, args: &[&str]) -> Output {
        bounded(self.0, program, args, None)
    }
    fn account(&self) -> Result<(u32, u32, String), String> {
        identity::account("orbit")
    }
}
fn owner(host: &impl Host) -> Result<PathBuf, Error> {
    let (uid, gid, home) = host.account().map_err(|e| failed("owner", e))?;
    if uid != 1000 || gid != 1000 || !Path::new(&home).is_absolute() {
        return Err(failed(
            "owner",
            "The orbit account must have UID and GID 1000 and an absolute home.",
        ));
    }
    Ok(PathBuf::from(home))
}
fn checked(
    host: &mut impl Host,
    program: &'static str,
    args: &[&str],
    stage: &str,
) -> Result<String, Error> {
    let result = host.run(program, args);
    if result.code != 0 || result.truncated {
        return Err(failed(
            stage,
            if result.truncated {
                "Output is incomplete.".into()
            } else {
                result.stderr.chars().take(2000).collect::<String>()
            },
        ));
    }
    Ok(result.stdout)
}
fn check(host: &mut impl Host, input: &CheckInput) -> Result<Ready, Error> {
    let home = owner(host)?;
    if !fs::metadata(home.join(".ssh/authorized_keys")).is_ok_and(|m| m.is_file() && m.len() > 0) {
        return Err(failed(
            "authorized key",
            "The image has no Gateway authorized key.",
        ));
    }
    if input.lean_boot {
        super::guest::fingerprint(host.run(
            "/usr/bin/ssh-keygen",
            &["-l", "-f", "/etc/ssh/ssh_host_ed25519_key.pub"],
        ))
        .map_err(|_| {
            failed(
                "host identity",
                "The smoke VM has no valid ed25519 host key.",
            )
        })?;
        let boots = checked(
            host,
            "/usr/bin/journalctl",
            &["--list-boots", "--no-pager", "--quiet"],
            "boot count",
        )?;
        if boots.lines().filter(|line| !line.trim().is_empty()).count() != 1 {
            return Err(failed(
                "boot count",
                "The smoke VM did not complete exactly one boot.",
            ));
        }
        let state = host.run(
            "/usr/bin/systemctl",
            &["is-enabled", "systemd-firstboot.service"],
        );
        if state.truncated || state.stdout.trim() != "masked" {
            return Err(failed(
                "first boot",
                "The first-boot service is not masked.",
            ));
        }
        if checked(
            host,
            "/usr/bin/systemctl",
            &["is-active", "docker.service"],
            "Docker",
        )?
        .trim()
            != "active"
        {
            return Err(failed("Docker", "Docker is not active."));
        }
        let unit = checked(
            host,
            "/usr/bin/systemctl",
            &["cat", "systemd-networkd-wait-online.service"],
            "wait-online",
        )?;
        if !unit.lines().last().is_some_and(|s| s.contains("--ipv4")) {
            return Err(failed(
                "wait-online",
                "The final wait-online setting does not select IPv4.",
            ));
        }
        let routes = checked(
            host,
            "/usr/sbin/ip",
            &["-j", "-4", "route", "show", "default"],
            "network",
        )?;
        let routes: Vec<serde_json::Value> = serde_json::from_str(&routes)
            .map_err(|_| failed("network", "Default routes are not JSON."))?;
        let interface = routes
            .first()
            .and_then(|v| v.get("dev"))
            .and_then(serde_json::Value::as_str)
            .filter(|v| !v.is_empty())
            .ok_or_else(|| failed("network", "There is no default IPv4 interface."))?;
        let status = checked(
            host,
            "/usr/bin/networkctl",
            &["status", "--no-pager", "--", interface],
            "network",
        )?;
        if !status.contains("00-orbit-task-vm.network") {
            return Err(failed(
                "network",
                "The default interface does not use the image network file.",
            ));
        }
    }
    if input.firewall {
        active_firewall(host)?;
    }
    Ok(Ready { ready: true })
}
fn boot(root: &Path, host: &mut impl Host, input: &BootInput) -> Result<Ready, Error> {
    if input.lean {
        for (path, bytes) in [(NETWORK, NETWORK_TEXT), (WAIT, WAIT_TEXT)] {
            super::image_identity::write_fixed(&root.join(path.trim_start_matches('/')), bytes)
                .map_err(|e| failed("network files", e))?;
        }
    }
    let mut args = vec!["mask"];
    args.extend(MASKS.iter().copied());
    checked(host, "/usr/bin/systemctl", &args, "service masks")?;
    Ok(Ready { ready: true })
}
fn firewall(host: &mut impl Host) -> Result<Ready, Error> {
    for args in [
        &["default", "deny", "incoming"][..],
        &[
            "allow",
            "in",
            "proto",
            "tcp",
            "to",
            "any",
            "port",
            "22",
            "comment",
            "orbit:public-ssh-recovery",
        ],
        &["--force", "enable"],
    ] {
        checked(host, "/usr/sbin/ufw", args, "firewall")?;
    }
    active_firewall(host)?;
    Ok(Ready { ready: true })
}
fn active_firewall(host: &mut impl Host) -> Result<(), Error> {
    if !checked(host, "/usr/sbin/ufw", &["status"], "firewall")?
        .lines()
        .any(|line| line == "Status: active")
    {
        return Err(failed("firewall", "The image firewall is inactive."));
    }
    Ok(())
}
pub struct PrepareFirewall;
impl Command for PrepareFirewall {
    const NAME: &'static str = "image.firewall";
    const SUMMARY: &'static str =
        "Enable the image firewall with only public SSH allowed by default.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["firewall"];
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = Empty;
    type Output = Ready;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Empty) -> Option<String> {
        Some("image-identity".into())
    }
    fn run(_: Empty, context: &Context) -> Result<Ready, Error> {
        firewall(&mut Native(context))
    }
}
pub struct PrepareBoot;
impl Command for PrepareBoot {
    const NAME: &'static str = "image.boot";
    const SUMMARY: &'static str =
        "Prepare fixed service masks and optional Incus DHCPv4 boot settings.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = BootInput;
    type Output = Ready;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &BootInput) -> Option<String> {
        Some("image-identity".into())
    }
    fn run(input: BootInput, context: &Context) -> Result<Ready, Error> {
        boot(Path::new("/"), &mut Native(context), &input)
    }
}
pub struct CheckImage;
impl Command for CheckImage {
    const NAME: &'static str = "image.check";
    const SUMMARY: &'static str =
        "Check image account, authorized key, and provider boot requirements.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = CheckInput;
    type Output = Ready;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: CheckInput, context: &Context) -> Result<Ready, Error> {
        check(&mut Native(context), &input)
    }
}
pub struct CheckOwner;
impl Command for CheckOwner {
    const NAME: &'static str = "image.owner";
    const SUMMARY: &'static str = "Check the image builder's pinned orbit UID and GID.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = Empty;
    type Output = Ready;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(_: Empty, context: &Context) -> Result<Ready, Error> {
        owner(&Native(context))?;
        Ok(Ready { ready: true })
    }
}
#[cfg(test)]
mod tests;

//! The WireGuard hub on the Node with the `vpn` role: `wireguard.project-hub` validates and installs the hub
//! configuration the Gateway rendered and activates `wg-quick@orbit`, restoring the previous configuration and
//! service state when activation fails; `wireguard.inspect-hub` reads whether the interface runs and the digests of
//! the hub and VPN DNS record files, for Doctor. The Gateway renders both files and compares the digests.
//!
//! [`Host`] also carries the paths and tools of [peer DNS](super::peer_dns), which shares `/etc/wireguard`.

use super::caddy::chown;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use sha2::Digest;
use std::{
    fs,
    io::{self, Write},
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

/// Where the WireGuard commands find their files and tools. Tests point it at a sandbox and stub tools.
pub struct Host {
    pub wireguard: PathBuf,
    pub dns_records: PathBuf,
    /// The lock every peer WireGuard and DNS change on the Node shares, shell programs included.
    pub peer_lock: PathBuf,
    /// The fixture tool directory, or `None` for fixed system executable paths.
    pub bin: Option<PathBuf>,
    pub root_uid: u32,
    pub root_gid: u32,
    pub lock_wait: Duration,
    pub inspect_lock_wait: Duration,
    /// How long peer DNS repair waits for networkd and resolved to expose an uplink server again.
    pub provider_wait: Duration,
    /// This rename, from and to, fails, to test a failed publication.
    #[cfg(test)]
    pub fail_rename: Option<(PathBuf, PathBuf)>,
}

impl Host {
    pub fn system() -> Self {
        Self {
            wireguard: PathBuf::from("/etc/wireguard"),
            dns_records: PathBuf::from("/etc/dnsmasq.d/orbit-records.conf"),
            peer_lock: PathBuf::from("/run/lock/orbit-wireguard-peer.lock"),
            bin: None,
            root_uid: 0,
            root_gid: 0,
            lock_wait: Duration::from_secs(30),
            inspect_lock_wait: Duration::from_secs(5),
            provider_wait: Duration::from_secs(15),
            #[cfg(test)]
            fail_rename: None,
        }
    }

    pub fn path(&self, name: &str) -> PathBuf {
        self.wireguard.join(name)
    }

    fn program(&self, name: &str) -> PathBuf {
        self.bin.as_ref().map_or_else(
            || {
                PathBuf::from(if name == "sysctl" {
                    "/usr/sbin"
                } else {
                    "/usr/bin"
                })
                .join(name)
            },
            |bin| bin.join(name),
        )
    }

    pub fn tool(&self, name: &str) -> Process {
        let mut command = Process::new(self.program(name));
        command.stdin(Stdio::null());
        command
    }

    /// Whether a tool exits 0. Its output is discarded.
    pub fn succeeds(&self, name: &str, arguments: &[&str]) -> bool {
        let result = self
            .tool(name)
            .args(arguments)
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status();
        #[cfg(test)]
        if let Err(error) = &result {
            eprintln!("Could not start WireGuard fixture tool {name}: {error}");
        }
        result.is_ok_and(|status| status.success())
    }

    /// A tool's standard output without trailing newlines, as `$(...)` reads it, when it exits 0.
    pub fn read(&self, name: &str, arguments: &[&str]) -> Option<String> {
        captured(self.tool(name).args(arguments))
    }

    /// Like [`Host::read`], under `timeout --kill-after=<kill>s <seconds>s`.
    pub fn read_bounded(
        &self,
        seconds: u32,
        kill: u32,
        name: &str,
        arguments: &[&str],
    ) -> Option<String> {
        captured(
            Process::new("/usr/bin/timeout")
                .arg(format!("--kill-after={kill}s"))
                .arg(format!("{seconds}s"))
                .arg(self.program(name))
                .args(arguments)
                .stdin(Stdio::null()),
        )
    }

    pub fn rename(&self, from: &Path, to: &Path) -> io::Result<()> {
        #[cfg(test)]
        if self
            .fail_rename
            .as_ref()
            .is_some_and(|(source, target)| source == from && target == to)
        {
            return Err(io::Error::other("the test fails this rename"));
        }
        fs::rename(from, to)
    }

    /// Writes a root-owned 0600 file, creating it 0600 so the bytes are never readable to others.
    pub fn write_private(&self, path: &Path, content: &[u8]) -> io::Result<()> {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create(true)
            .truncate(true)
            .mode(0o600)
            .custom_flags(libc::O_NOFOLLOW)
            .open(path)?;
        file.write_all(content)?;
        chown(path, self.root_uid, self.root_gid)?;
        fs::set_permissions(path, fs::Permissions::from_mode(0o600))
    }
}

fn captured(command: &mut Process) -> Option<String> {
    let output = command.stderr(Stdio::null()).output().ok()?;
    if !output.status.success() {
        return None;
    }
    let mut text = String::from_utf8_lossy(&output.stdout).into_owned();
    while text.ends_with('\n') {
        text.pop();
    }
    Some(text)
}

/// `rm -f`: a missing file is fine.
pub fn remove(path: &Path) -> io::Result<()> {
    match fs::remove_file(path) {
        Err(error) if error.kind() != io::ErrorKind::NotFound => Err(error),
        _ => Ok(()),
    }
}

/// `[ -e path ] || [ -L path ]`.
pub fn present(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok()
}

/// `[ -f path ]`, which follows a link.
pub fn regular(path: &Path) -> bool {
    fs::metadata(path).is_ok_and(|metadata| metadata.is_file())
}

/// `[ -L path ]`.
pub fn link(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| metadata.file_type().is_symlink())
}

/// `cp -a --no-dereference`: a link stays a link; a file keeps its mode and owner.
pub fn copy_exact(from: &Path, to: &Path) -> io::Result<()> {
    let metadata = fs::symlink_metadata(from)?;
    remove(to)?;
    if metadata.file_type().is_symlink() {
        std::os::unix::fs::symlink(fs::read_link(from)?, to)?;
        return rustix::fs::chownat(
            rustix::fs::CWD,
            to,
            Some(rustix::fs::Uid::from_raw(metadata.uid())),
            Some(rustix::fs::Gid::from_raw(metadata.gid())),
            rustix::fs::AtFlags::SYMLINK_NOFOLLOW,
        )
        .map_err(io::Error::from);
    }
    fs::copy(from, to)?;
    chown(to, metadata.uid(), metadata.gid())?;
    fs::set_permissions(to, fs::Permissions::from_mode(metadata.mode() & 0o7777))
}

// ---- project the hub ---------------------------------------------------------------------------------------------

command_errors! {
    pub enum HubError {
        InstallFailed {} => "wireguard.hub_install_failed", "The hub configuration could not be installed.";
        ForwardingInstallFailed {} => "wireguard.forwarding_install_failed", "The forwarding configuration could not be installed.";
        ForwardingInvalid {} => "wireguard.forwarding_config_invalid", "The forwarding configuration could not be applied.";
        ConfigInvalid {} => "wireguard.hub_config_invalid", "wg-quick does not accept the hub configuration.";
        StartFailed {} => "wireguard.hub_start_failed", "wg-quick@orbit did not run the hub configuration; the previous one is back.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ProjectInput {
    /// The rendered hub configuration, private key included.
    pub config: String,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Projected {}

const SERVICE: &str = "wg-quick@orbit";

pub fn project(input: &ProjectInput, host: &Host) -> Result<Projected, HubError> {
    publish_hub(input, host, None)
}

fn publish_hub(
    input: &ProjectInput,
    host: &Host,
    sysctl: Option<&Path>,
) -> Result<Projected, HubError> {
    if input.config.is_empty()
        || input.config.len() > 1024 * 1024
        || input.config.lines().any(|line| {
            let key = line.split('=').next().unwrap_or("").trim();
            ["PreUp", "PostUp", "PreDown", "PostDown"]
                .iter()
                .any(|hook| key.eq_ignore_ascii_case(hook))
        })
    {
        return Err(HubError::ConfigInvalid {});
    }
    let candidate = host.path("orbit-candidate.conf");
    let live = host.path("orbit.conf");
    let backup = host.path(".orbit.conf.rollback");
    let install = |_| HubError::InstallFailed {};
    if !host.wireguard.is_dir() {
        fs::create_dir_all(&host.wireguard).map_err(install)?;
    }
    fs::set_permissions(&host.wireguard, fs::Permissions::from_mode(0o700)).map_err(install)?;
    if let Err(error) = host.write_private(&candidate, input.config.as_bytes()) {
        let _ = remove(&candidate);
        return Err(install(error));
    }
    if !host.succeeds("wg-quick", &["strip", &candidate.to_string_lossy()]) {
        let _ = remove(&candidate);
        return Err(HubError::ConfigInvalid {});
    }
    let forwarding = sysctl.map(|directory| {
        (
            directory.join(".90-orbit-wireguard-forwarding.conf.candidate"),
            directory.join("90-orbit-wireguard-forwarding.conf"),
        )
    });
    let publish = (|| {
        if let Some((candidate, _)) = &forwarding {
            host.write_private(candidate, b"net.ipv4.ip_forward=1\n")
                .and_then(|()| fs::set_permissions(candidate, fs::Permissions::from_mode(0o644)))
                .map_err(|_| HubError::ForwardingInstallFailed {})?;
            if !host.succeeds("sysctl", &["-p", &candidate.to_string_lossy()]) {
                return Err(HubError::ForwardingInvalid {});
            }
        }
        remove(&backup).map_err(install)?;
        if regular(&live) {
            copy_exact(&live, &backup).map_err(install)?;
        }
        if let Some((candidate, live)) = &forwarding {
            host.rename(candidate, live)
                .map_err(|_| HubError::ForwardingInstallFailed {})?;
        }
        host.rename(&candidate, &live).map_err(install)?;
        Ok(())
    })();
    if let Err(error) = publish {
        let _ = remove(&candidate);
        let _ = remove(&backup);
        if let Some((candidate, _)) = &forwarding {
            let _ = remove(candidate);
        }
        return Err(error);
    }
    activate(host, &live, &backup, sysctl.is_some())
}

/// Starts or live-syncs `wg-quick@orbit` on the installed configuration; on failure puts the backup and the previous
/// enabled and active state back.
fn activate(host: &Host, live: &Path, backup: &Path, restart: bool) -> Result<Projected, HubError> {
    let was_active = host.succeeds("systemctl", &["is-active", "--quiet", SERVICE]);
    let was_enabled = host.succeeds("systemctl", &["is-enabled", "--quiet", SERVICE]);
    let mut runtime: Option<PathBuf> = None;
    let sync = |runtime: &Path| -> bool {
        let Ok(file) = fs::OpenOptions::new()
            .write(true)
            .truncate(true)
            .open(runtime)
        else {
            return false;
        };
        host.tool("wg-quick")
            .args(["strip"])
            .arg(live)
            .stdout(file)
            .stderr(Stdio::null())
            .status()
            .is_ok_and(|status| status.success())
            && host.succeeds("wg", &["syncconf", "orbit", &runtime.to_string_lossy()])
    };
    let restore = |runtime: Option<&Path>| {
        if regular(backup) {
            let _ = host.rename(backup, live);
            if was_active {
                if restart {
                    host.succeeds("systemctl", &["restart", SERVICE]);
                } else if let Some(runtime) = runtime {
                    if !sync(runtime) {
                        host.succeeds("systemctl", &["restart", SERVICE]);
                    }
                }
            } else {
                host.succeeds("systemctl", &["stop", SERVICE]);
            }
        } else {
            let _ = remove(live);
            host.succeeds("systemctl", &["stop", SERVICE]);
        }
        host.succeeds(
            "systemctl",
            &[if was_enabled { "enable" } else { "disable" }, SERVICE],
        );
    };
    let result = (|| {
        if !host.succeeds("systemctl", &["enable", SERVICE]) {
            return Err(());
        }
        runtime = Some(runtime_file(host).map_err(|_| ())?);
        let started = if restart {
            host.succeeds("systemctl", &["restart", SERVICE])
        } else if was_active {
            sync(runtime.as_deref().expect("created above"))
        } else {
            host.succeeds("systemctl", &["start", SERVICE])
        };
        if !started
            || !host.succeeds("systemctl", &["is-active", "--quiet", SERVICE])
            || !host.succeeds("systemctl", &["is-enabled", "--quiet", SERVICE])
        {
            return Err(());
        }
        Ok(())
    })();
    if result.is_err() {
        restore(runtime.as_deref());
    }
    if let Some(runtime) = &runtime {
        let _ = remove(runtime);
    }
    result.map_err(|()| HubError::StartFailed {})?;
    let _ = remove(backup);
    Ok(Projected {})
}

/// A new private file for the stripped configuration that `wg syncconf` reads.
fn runtime_file(host: &Host) -> io::Result<PathBuf> {
    for _ in 0..16 {
        let path = host.path(&format!(
            "orbit-wireguard.{:012x}",
            rand::random::<u64>() >> 16
        ));
        match fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&path)
        {
            Ok(_) => return Ok(path),
            Err(error) if error.kind() == io::ErrorKind::AlreadyExists => continue,
            Err(error) => return Err(error),
        }
    }
    Err(io::Error::other("no free runtime path"))
}

pub struct ProjectHub;

impl Command for ProjectHub {
    const NAME: &'static str = "wireguard.project-hub";
    const SUMMARY: &'static str =
        "Install the WireGuard hub configuration and run it, restoring the previous one on failure.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = ProjectInput;
    type Output = Projected;
    type Error = HubError;

    /// `/etc/wireguard` and `wg-quick@orbit` belong to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(_: &ProjectInput) -> Option<String> {
        Some("wireguard-hub".into())
    }

    fn run(input: ProjectInput, _: &Context) -> Result<Projected, HubError> {
        project(&input, &Host::system())
    }
}

pub struct BootstrapHub;
impl Command for BootstrapHub {
    const NAME: &'static str = "wireguard.bootstrap-hub";
    const SUMMARY: &'static str = "Validate the Gateway hub, enable forwarding and restart WireGuard with configuration recovery.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = ProjectInput;
    type Output = Projected;
    type Error = HubError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &ProjectInput) -> Option<String> {
        Some("wireguard-hub".into())
    }
    fn run(input: ProjectInput, _: &Context) -> Result<Projected, HubError> {
        publish_hub(&input, &Host::system(), Some(Path::new("/etc/sysctl.d")))
    }
}

// ---- inspect the hub ---------------------------------------------------------------------------------------------

command_errors! {
    pub enum InspectHubError {
        Unreadable {} => "wireguard.hub_unreadable", "A hub file could not be read.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectHubInput {}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct HubState {
    pub active: bool,
    /// The SHA-256 of `/etc/wireguard/orbit.conf`, or `None` when it is not a file.
    pub config_sha256: Option<String>,
    /// The SHA-256 of the VPN DNS records, `/etc/dnsmasq.d/orbit-records.conf`, or `None` when it is not a file.
    pub dns_records_sha256: Option<String>,
}

fn digest(path: &Path) -> Result<Option<String>, InspectHubError> {
    if !regular(path) {
        return Ok(None);
    }
    let bytes = fs::read(path).map_err(|_| InspectHubError::Unreadable {})?;
    Ok(Some(format!("{:x}", sha2::Sha256::digest(bytes))))
}

pub fn inspect(host: &Host) -> Result<HubState, InspectHubError> {
    Ok(HubState {
        active: host.succeeds("systemctl", &["is-active", "--quiet", SERVICE]),
        config_sha256: digest(&host.path("orbit.conf"))?,
        dns_records_sha256: digest(&host.dns_records)?,
    })
}

pub struct InspectHub;

impl Command for InspectHub {
    const NAME: &'static str = "wireguard.inspect-hub";
    const SUMMARY: &'static str =
        "Read whether the hub interface runs and the digests of its configuration and VPN DNS records.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = InspectHubInput;
    type Output = HubState;
    type Error = InspectHubError;

    /// The hub configuration holds its private key and only root reads it.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(_: InspectHubInput, _: &Context) -> Result<HubState, InspectHubError> {
        inspect(&Host::system())
    }
}

#[cfg(test)]
#[path = "wireguard_tests.rs"]
mod tests;

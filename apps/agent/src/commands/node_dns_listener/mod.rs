//! Installs the private DNS listener's content-addressed release and fixed units.
//! Publication owns the catalog and first activation; an active listener keeps its sockets while it restarts.

use super::recipe::Files;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    fs,
    os::unix::fs::MetadataExt,
    path::{Component, Path},
    process::{Command as Process, Stdio},
    time::Duration,
};

const ROOT: &str = "/var/lib/orbit/private-dns/releases";
const UNIT: &str = "/etc/systemd/system/orbit-private-dns.service";
const SOCKET: &str = "/etc/systemd/system/orbit-private-dns.socket";

command_errors! {
    pub enum InstallError {
        Invalid { check: String } => "node.private_dns_listener_invalid", "The private DNS listener input or ownership is invalid.";
        Failed { step: String } => "node.private_dns_listener_failed", "The private DNS listener could not be installed.";
    }
}
fn invalid(check: &str) -> InstallError {
    InstallError::Invalid {
        check: check.into(),
    }
}
fn failed(step: &str) -> InstallError {
    InstallError::Failed { step: step.into() }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReleaseFile {
    pub path: String,
    pub contents: String,
    pub sha256: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub release_id: String,
    pub files: Vec<ReleaseFile>,
    pub service: String,
    pub socket: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Installed {
    pub installed: bool,
    pub changed: bool,
}

trait Host {
    fn validate(&mut self, release: &Path, service: &Path, socket: &Path) -> bool;
    fn active(&mut self) -> bool;
    fn reload(&mut self) -> bool;
    fn restart(&mut self) -> bool;
}
struct Native;
fn process(name: &str, arguments: &[&std::ffi::OsStr]) -> bool {
    Process::new(name)
        .args(arguments)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok_and(|status| status.success())
}
impl Host for Native {
    fn validate(&mut self, release: &Path, service: &Path, socket: &Path) -> bool {
        process(
            "/usr/bin/php8.5",
            &[
                release.join("serve.php").as_os_str(),
                "--self-test".as_ref(),
            ],
        ) && process(
            "systemd-analyze",
            &["verify".as_ref(), socket.as_os_str(), service.as_os_str()],
        )
    }
    fn active(&mut self) -> bool {
        process(
            "systemctl",
            &[
                "is-active".as_ref(),
                "--quiet".as_ref(),
                "orbit-private-dns.service".as_ref(),
            ],
        )
    }
    fn reload(&mut self) -> bool {
        process("systemctl", &["daemon-reload".as_ref()])
    }
    fn restart(&mut self) -> bool {
        process(
            "systemctl",
            &["restart".as_ref(), "orbit-private-dns.service".as_ref()],
        )
    }
}

fn validate(input: &Input) -> Result<String, InstallError> {
    if input.files.is_empty()
        || input.files.len() > 128
        || input
            .files
            .iter()
            .map(|file| file.contents.len())
            .sum::<usize>()
            > 3 * 1024 * 1024
        || input.release_id.len() != 16
        || !input
            .release_id
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
    {
        return Err(invalid("bounded release and id"));
    }
    let mut id = String::new();
    let mut manifest = String::new();
    let mut previous: Option<&str> = None;
    let mut entry = false;
    for file in &input.files {
        if previous.is_some_and(|path| path >= file.path.as_str())
            || file.path.contains('\0')
            || !(file.path == "serve.php" || file.path.starts_with("app/"))
            || !file.path.ends_with(".php")
            || Path::new(&file.path)
                .components()
                .any(|part| !matches!(part, Component::Normal(_)))
            || file.sha256 != format!("{:x}", Sha256::digest(file.contents.as_bytes()))
        {
            return Err(invalid("ordered relative release files and digests"));
        }
        previous = Some(&file.path);
        entry |= file.path == "serve.php";
        id.push_str(&format!("{}\0{}\n", file.path, file.sha256));
        manifest.push_str(&format!("{}  {}\n", file.sha256, file.path));
    }
    if !entry || input.release_id != format!("{:x}", Sha256::digest(id.as_bytes()))[..16] {
        return Err(invalid("content-addressed release"));
    }
    let release = format!("{ROOT}/{}", input.release_id);
    // Accept only the fixed renderer shape; no extra ExecStartPre or install directives.
    let address = input
        .socket
        .lines()
        .find_map(|line| line.strip_prefix("ListenDatagram="))
        .and_then(|value| value.strip_suffix(":53"))
        .and_then(|value| value.parse::<std::net::Ipv4Addr>().ok())
        .ok_or_else(|| invalid("listener address"))?;
    let service = format!(concat!(
        "[Unit]\nDescription=Orbit private DNS\n",
        "After=network-online.target wg-quick@orbit.service dnsmasq.service orbit-private-dns.socket\n",
        "Wants=network-online.target\nRequires=orbit-private-dns.socket\n\n",
        "[Service]\nType=simple\nUser=root\nWorkingDirectory={}\n",
        "ExecStart=\"/usr/bin/php8.5\" \"{}/serve.php\" \"--listen={}\" \"--port=53\" ",
        "\"--catalog=/var/lib/orbit/private-dns/catalog.json\" \"--upstream=127.0.0.55:53\"\n",
        "Sockets=orbit-private-dns.socket\nKillSignal=SIGTERM\nTimeoutStopSec=5\nRestart=on-failure\nRestartSec=2\n\n",
        "[Install]\nWantedBy=multi-user.target\n"), release, release, address);
    let socket = format!(concat!(
        "[Unit]\nDescription=Orbit private DNS sockets\n\n[Socket]\nListenDatagram={}:53\nListenStream={}:53\n",
        "FreeBind=yes\nService=orbit-private-dns.service\n\n[Install]\nWantedBy=sockets.target\n"), address, address);
    if input.service != service || input.socket != socket {
        return Err(invalid("fixed listener units"));
    }
    Ok(manifest)
}

fn directory(files: &Files, path: &Path) -> Result<(), InstallError> {
    if path == files.root {
        return Ok(());
    }
    let parent = path.parent().ok_or_else(|| invalid("directory parent"))?;
    directory(files, parent)?;
    files
        .directory(path, files.uid, files.gid)
        .map_err(|_| invalid("owned directory without symlinks"))
}
fn current(files: &Files, path: &Path, contents: &[u8]) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| {
        metadata.is_file()
            && metadata.uid() == files.uid
            && metadata.gid() == files.gid
            && metadata.mode() & 0o7777 == 0o644
    }) && fs::read(path).ok().as_deref() == Some(contents)
}
#[derive(Clone)]
struct Previous {
    bytes: Vec<u8>,
    mode: u32,
}
fn previous(files: &Files, path: &Path, marker: &[u8]) -> Result<Option<Previous>, InstallError> {
    match fs::symlink_metadata(path) {
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(None),
        Ok(metadata)
            if metadata.is_file() && metadata.uid() == files.uid && metadata.gid() == files.gid =>
        {
            let bytes = fs::read(path).map_err(|_| failed("read units"))?;
            if !bytes.starts_with(marker) {
                return Err(invalid("Orbit-owned units"));
            }
            Ok(Some(Previous {
                bytes,
                mode: metadata.mode() & 0o7777,
            }))
        }
        _ => Err(invalid("regular owned units")),
    }
}
fn restore(files: &Files, path: &Path, prior: &Option<Previous>) -> bool {
    if let Some(prior) = prior {
        files
            .publish(path, &prior.bytes, prior.mode, files.uid, files.gid, false)
            .is_ok()
    } else {
        fs::remove_file(path).is_ok() || !path.exists()
    }
}

fn install(files: &Files, host: &mut impl Host, input: &Input) -> Result<Installed, InstallError> {
    let manifest = validate(input)?;
    let service = files.path(UNIT);
    let socket = files.path(SOCKET);
    let old_service = previous(files, &service, b"[Unit]\nDescription=Orbit private DNS\n")?;
    let old_socket = previous(
        files,
        &socket,
        b"[Unit]\nDescription=Orbit private DNS sockets\n",
    )?;
    let root = files.path(ROOT);
    directory(files, &root)?;
    directory(files, service.parent().unwrap())?;
    let release = root.join(&input.release_id);
    if fs::symlink_metadata(&release)
        .is_ok_and(|metadata| !metadata.is_dir() || metadata.uid() != files.uid)
    {
        return Err(invalid("owned release directory"));
    }
    let release_changed = !current(files, &release.join(".manifest"), manifest.as_bytes())
        || input
            .files
            .iter()
            .any(|file| !current(files, &release.join(&file.path), file.contents.as_bytes()));
    let changed = release_changed
        || !current(files, &service, input.service.as_bytes())
        || !current(files, &socket, input.socket.as_bytes());
    let staged = root.join(format!(
        ".{}-{:016x}",
        input.release_id,
        rand::random::<u64>()
    ));
    directory(files, &staged)?;
    let staged_service = staged.join("orbit-private-dns.service");
    let staged_socket = staged.join("orbit-private-dns.socket");
    let backup = root.join(format!(
        ".{}-{:016x}-previous",
        input.release_id,
        rand::random::<u64>()
    ));
    let result = (|| {
        for file in &input.files {
            let path = staged.join(&file.path);
            directory(files, path.parent().unwrap())?;
            files
                .publish(
                    &path,
                    file.contents.as_bytes(),
                    0o644,
                    files.uid,
                    files.gid,
                    false,
                )
                .map_err(|_| failed("stage release"))?;
        }
        files
            .publish(
                &staged.join(".manifest"),
                manifest.as_bytes(),
                0o644,
                files.uid,
                files.gid,
                false,
            )
            .map_err(|_| failed("stage manifest"))?;
        files
            .publish(
                &staged_service,
                input.service.as_bytes(),
                0o644,
                files.uid,
                files.gid,
                false,
            )
            .map_err(|_| failed("stage service"))?;
        files
            .publish(
                &staged_socket,
                input.socket.as_bytes(),
                0o644,
                files.uid,
                files.gid,
                false,
            )
            .map_err(|_| failed("stage socket"))?;
        if !host.validate(&staged, &staged_service, &staged_socket) {
            return Err(failed("validate release and units"));
        }
        if !changed {
            return Ok(Installed {
                installed: true,
                changed: false,
            });
        }
        let active = host.active();
        fs::remove_file(&staged_service).map_err(|_| failed("stage cleanup"))?;
        fs::remove_file(&staged_socket).map_err(|_| failed("stage cleanup"))?;
        if release_changed {
            if release.exists() {
                fs::rename(&release, &backup).map_err(|_| failed("retain prior release"))?;
            }
            if fs::rename(&staged, &release).is_err() {
                if backup.exists() {
                    let _ = fs::rename(&backup, &release);
                }
                return Err(failed("publish release"));
            }
        }
        let published = files
            .publish(
                &service,
                input.service.as_bytes(),
                0o644,
                files.uid,
                files.gid,
                false,
            )
            .is_ok()
            && files
                .publish(
                    &socket,
                    input.socket.as_bytes(),
                    0o644,
                    files.uid,
                    files.gid,
                    false,
                )
                .is_ok()
            && host.reload()
            && (!active || host.restart());
        if !published {
            let restored =
                restore(files, &service, &old_service) & restore(files, &socket, &old_socket);
            if backup.exists() {
                let _ = fs::remove_dir_all(&release);
                let _ = fs::rename(&backup, &release);
            }
            let reloaded = host.reload();
            let restarted = !active || host.restart();
            return Err(failed(if restored && reloaded && restarted {
                "activate units"
            } else {
                "restore units"
            }));
        }
        Ok(Installed {
            installed: true,
            changed: true,
        })
    })();
    let _ = fs::remove_dir_all(&staged);
    if result.is_ok() {
        let _ = fs::remove_dir_all(&backup);
    }
    result
}

pub struct Install;
impl Command for Install {
    const NAME: &'static str = "node.install-private-dns-listener";
    const SUMMARY: &'static str =
        "Install the private DNS listener release and units without publishing catalog data.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    const RESOURCES: &'static [&'static str] = &["private-dns"];
    type Input = Input;
    type Output = Installed;
    type Error = InstallError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, _: &Context) -> Result<Installed, InstallError> {
        install(&Files::system(), &mut Native, &input)
    }
}

#[cfg(test)]
mod tests;

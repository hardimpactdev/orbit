//! Peer publication and its retained admission transaction. The Gateway owns hub intent and continuation.
use super::{
    peer_dns,
    recipe::Files,
    wireguard::{present, remove, Host},
};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::{self, Read, Write},
    net::{IpAddr, Ipv4Addr},
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::Stdio,
    time::{Duration, Instant},
};

const STATE: &str = ".orbit.peer-transaction";
const CANDIDATE: &str = "orbit-candidate.conf";
const SERVICE: &str = "wg-quick@orbit";
const SNAPSHOT_LIMIT: usize = 2 * 1024 * 1024;
const SETTINGS: [&str; 8] = [
    "dns",
    "domain",
    "default-route",
    "llmnr",
    "mdns",
    "dnssec",
    "dnsovertls",
    "nta",
];
command_errors! {
    pub enum Error {
        Busy {} => "wireguard.peer_busy", "Another WireGuard operation holds the peer lock.";
        KeyMissing {} => "wireguard.peer_key_missing", "The existing peer key is missing.";
        KeyFailed {} => "wireguard.peer_key_failed", "The peer key could not be prepared.";
        Unsupported {} => "wireguard.peer_state_unsupported", "The peer input or existing state cannot be changed safely.";
        Pending {} => "wireguard.peer_recovery_pending", "An earlier peer transaction needs recovery.";
        Uplink {} => "wireguard.peer_dns_uplink_missing", "No usable non-Orbit DNS server is configured.";
        Failed {} => "wireguard.peer_config_failed", "The peer configuration could not be activated.";
        Commit {} => "wireguard.peer_commit_failed", "The peer transaction could not be committed.";
        Recovery {} => "wireguard.peer_rollback_failed", "The prior peer state could not be restored; its recovery record is retained.";
    }
}
type Result<T> = std::result::Result<T, Error>;
fn require(ok: bool) -> Result<()> {
    if ok {
        Ok(())
    } else {
        Err(Error::Unsupported {})
    }
}
fn key(value: &str) -> bool {
    value.len() == 44
        && value.ends_with('=')
        && value.as_bytes()[..43]
            .iter()
            .all(|b| b.is_ascii_alphanumeric() || *b == b'+' || *b == b'/')
}
fn domain(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 253
        && value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b == b'-' || b == b'.')
}
fn link(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 255
        && value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"_.:+-".contains(&b))
}
fn metadata(path: &Path) -> Result<Option<fs::Metadata>> {
    match fs::symlink_metadata(path) {
        Ok(m) => Ok(Some(m)),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(Error::Unsupported {}),
    }
}
fn directory(host: &Host) -> Result<()> {
    if let Some(meta) = metadata(&host.wireguard)? {
        require(meta.is_dir() && meta.uid() == host.root_uid && meta.mode() & 0o022 == 0)?;
    }
    Ok(())
}
fn locked(host: &Host) -> Result<fs::File> {
    peer_dns::hold(&host.peer_lock, host.lock_wait, true)
        .map_err(|_| Error::Busy {})?
        .ok_or(Error::Busy {})
}
fn publish(host: &Host, name: &str, bytes: &[u8], mode: u32) -> Result<()> {
    Files {
        root: PathBuf::from("/"),
        uid: host.root_uid,
        gid: host.root_gid,
    }
    .publish(
        &host.path(name),
        bytes,
        mode,
        host.root_uid,
        host.root_gid,
        name == "orbit.dns-link",
    )
    .map_err(|_| Error::Failed {})
}
fn read_owned(host: &Host, name: &str) -> Result<Option<Vec<u8>>> {
    let path = host.path(name);
    let Some(meta) = metadata(&path)? else {
        return Ok(None);
    };
    require(meta.is_file() && meta.uid() == host.root_uid && meta.len() <= SNAPSHOT_LIMIT as u64)?;
    let mut bytes = Vec::new();
    fs::OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW)
        .open(path)
        .map_err(|_| Error::Unsupported {})?
        .take(SNAPSHOT_LIMIT as u64 + 1)
        .read_to_end(&mut bytes)
        .map_err(|_| Error::Unsupported {})?;
    require(bytes.len() <= SNAPSHOT_LIMIT)?;
    Ok(Some(bytes))
}
fn wg_public(host: &Host, private: &[u8]) -> Result<String> {
    let mut child = host
        .tool("wg")
        .arg("pubkey")
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .spawn()
        .map_err(|_| Error::KeyFailed {})?;
    let wrote = child
        .stdin
        .take()
        .is_some_and(|mut stdin| stdin.write_all(private).is_ok());
    let result = child.wait_with_output().map_err(|_| Error::KeyFailed {})?;
    let public = String::from_utf8(result.stdout).map_err(|_| Error::KeyFailed {})?;
    let public = public.trim();
    if !wrote || !result.status.success() || !key(public) {
        return Err(Error::KeyFailed {});
    }
    Ok(public.into())
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct KeyInput {
    existing: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct PublicKey {
    public_key: String,
}
fn prepare_key(input: KeyInput, host: &Host) -> Result<PublicKey> {
    let _lock = locked(host)?;
    directory(host)?;
    let old = read_owned(host, "orbit.key")?;
    if input.existing && old.as_ref().is_none_or(Vec::is_empty) {
        return Err(Error::KeyMissing {});
    }
    if !input.existing {
        read_owned(host, "orbit.public")?;
    }
    let private = match old {
        Some(bytes) if !bytes.is_empty() => bytes,
        _ => host
            .read("wg", &["genkey"])
            .filter(|s| key(s.trim()))
            .ok_or(Error::KeyFailed {})?
            .into_bytes(),
    };
    let public = wg_public(host, &private)?;
    if !input.existing {
        if !host.wireguard.exists() {
            fs::create_dir(&host.wireguard).map_err(|_| Error::KeyFailed {})?;
        }
        super::caddy::chown(&host.wireguard, host.root_uid, host.root_gid)
            .map_err(|_| Error::KeyFailed {})?;
        fs::set_permissions(&host.wireguard, fs::Permissions::from_mode(0o700))
            .map_err(|_| Error::KeyFailed {})?;
        if read_owned(host, "orbit.key")?
            .as_ref()
            .is_none_or(Vec::is_empty)
        {
            publish(host, "orbit.key", &private, 0o600).map_err(|_| Error::KeyFailed {})?;
        }
        let bytes = format!("{public}\n");
        if read_owned(host, "orbit.public")?.as_deref() != Some(bytes.as_bytes()) {
            publish(host, "orbit.public", bytes.as_bytes(), 0o600)
                .map_err(|_| Error::KeyFailed {})?;
        }
    }
    Ok(PublicKey { public_key: public })
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    server_public_key: String,
    peer_public_key: String,
    endpoint: String,
    address: String,
    subnet: String,
    dns_server: String,
    domains: Vec<String>,
    operator_dns: Option<String>,
    retain: bool,
}
fn cidr(value: &str) -> bool {
    value.split_once('/').is_some_and(|(ip, prefix)| {
        ip.parse::<Ipv4Addr>().is_ok() && prefix.parse::<u8>().is_ok_and(|p| p <= 32)
    })
}
fn validate(input: &Input) -> Result<()> {
    require(
        key(&input.server_public_key)
            && key(&input.peer_public_key)
            && cidr(&input.address)
            && cidr(&input.subnet),
    )?;
    require(
        input.dns_server.parse::<IpAddr>().is_ok()
            && (1..=512).contains(&input.domains.len())
            && input.domains.iter().all(|d| domain(d) && d != "."),
    )?;
    if let Some(dns) = &input.operator_dns {
        require(dns.parse::<IpAddr>().is_ok())?;
    }
    let (host, port) = input
        .endpoint
        .rsplit_once(':')
        .ok_or(Error::Unsupported {})?;
    require(
        port.parse::<u16>().is_ok_and(|p| p > 0)
            && !host.is_empty()
            && host.len() <= 255
            && host
                .bytes()
                .all(|b| b.is_ascii_alphanumeric() || b".-:[]".contains(&b)),
    )
}

#[derive(Debug, Deserialize, Serialize)]
#[serde(tag = "kind", deny_unknown_fields)]
enum SavedFile {
    Absent {},
    File {
        bytes: Vec<u8>,
        mode: u32,
        uid: u32,
        gid: u32,
    },
    Link {
        target: PathBuf,
        uid: u32,
        gid: u32,
    },
}
impl SavedFile {
    fn capture(host: &Host, name: &str, allow_link: bool) -> Result<Self> {
        let path = host.path(name);
        let Some(meta) = metadata(&path)? else {
            return Ok(Self::Absent {});
        };
        require(meta.uid() == host.root_uid)?;
        if meta.is_symlink() && allow_link {
            return Ok(Self::Link {
                target: fs::read_link(path).map_err(|_| Error::Unsupported {})?,
                uid: meta.uid(),
                gid: meta.gid(),
            });
        }
        require(meta.is_file() && meta.len() <= 256 * 1024)?;
        Ok(Self::File {
            bytes: read_owned(host, name)?.ok_or(Error::Unsupported {})?,
            mode: meta.mode() & 0o7777,
            uid: meta.uid(),
            gid: meta.gid(),
        })
    }
    fn restore(&self, host: &Host, name: &str) -> Result<()> {
        let path = host.path(name);
        let temp = host.path(&format!(".{name}.restore"));
        remove(&temp).map_err(|_| Error::Recovery {})?;
        match self {
            Self::Absent {} => remove(&path).map_err(|_| Error::Recovery {}),
            Self::File {
                bytes,
                mode,
                uid,
                gid,
            } => {
                host.write_private(&temp, bytes)
                    .map_err(|_| Error::Recovery {})?;
                super::caddy::chown(&temp, *uid, *gid).map_err(|_| Error::Recovery {})?;
                fs::set_permissions(&temp, fs::Permissions::from_mode(*mode))
                    .map_err(|_| Error::Recovery {})?;
                host.rename(&temp, &path).map_err(|_| Error::Recovery {})
            }
            Self::Link { target, uid, gid } => {
                std::os::unix::fs::symlink(target, &temp).map_err(|_| Error::Recovery {})?;
                rustix::fs::chownat(
                    rustix::fs::CWD,
                    &temp,
                    Some(rustix::fs::Uid::from_raw(*uid)),
                    Some(rustix::fs::Gid::from_raw(*gid)),
                    rustix::fs::AtFlags::SYMLINK_NOFOLLOW,
                )
                .map_err(|_| Error::Recovery {})?;
                host.rename(&temp, &path).map_err(|_| Error::Recovery {})
            }
        }
    }
}
#[derive(Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct Dns {
    link: String,
    server: String,
    domains: Vec<String>,
}
fn dns_state(host: &Host) -> Result<Option<Dns>> {
    let path = host.path("orbit.dns-link");
    if !present(&path) {
        return Ok(None);
    }
    let bytes = fs::read(&path).map_err(|_| Error::Unsupported {})?;
    require(bytes.len() <= 256 * 1024)?;
    let text = String::from_utf8(bytes).map_err(|_| Error::Unsupported {})?;
    if text.is_empty() {
        return Ok(None);
    }
    let lines = text.lines().collect::<Vec<_>>();
    require(
        lines.len() >= 3
            && link(lines[0])
            && lines[1].parse::<IpAddr>().is_ok()
            && lines[2..].iter().all(|d| domain(d)),
    )?;
    Ok(Some(Dns {
        link: lines[0].into(),
        server: lines[1].into(),
        domains: lines[2..].iter().map(|d| (*d).into()).collect(),
    }))
}
#[derive(Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct Snapshot {
    // This is the existing recovery record, with the prior bytes included so commit is one unlink.
    peer_transaction: bool,
    active: bool,
    enabled: String,
    config: SavedFile,
    dns_file: SavedFile,
    dns: Option<Dns>,
    resolver: Option<Vec<String>>,
    uplink: Option<String>,
}
fn setting(output: &str) -> String {
    output
        .split_once(':')
        .map_or(output, |(_, value)| value)
        .trim()
        .into()
}
fn resolver(host: &Host) -> Result<Vec<String>> {
    let mut values = vec![];
    for name in SETTINGS {
        values.push(setting(
            &host
                .read("resolvectl", &[name, "orbit"])
                .ok_or(Error::Unsupported {})?,
        ));
    }
    validate_resolver(&values)?;
    Ok(values)
}
fn validate_resolver(values: &[String]) -> Result<()> {
    require(values.len() == 8)?;
    require(values[0].split_whitespace().all(|value| {
        value.len() <= 512
            && value
                .bytes()
                .all(|b| b.is_ascii_alphanumeric() || b".:_%#-".contains(&b))
            && value
                .split(['%', '#'])
                .next()
                .is_some_and(|ip| ip.parse::<IpAddr>().is_ok())
    }))?;
    require(
        values[1]
            .split_whitespace()
            .all(|d| domain(d.strip_prefix('~').unwrap_or(d))),
    )?;
    for (value, choices) in values[2..].iter().zip([
        &["yes", "no"][..],
        &["yes", "no", "resolve"],
        &["yes", "no", "resolve"],
        &["yes", "no", "allow-downgrade"],
        &["yes", "no", "opportunistic"],
    ]) {
        require(choices.contains(&value.as_str()))?;
    }
    require(values[7].split_whitespace().count() <= 256 && values[7].split_whitespace().all(domain))
}
fn apply_resolver(host: &Host, values: &[String], restore_selection: bool) -> bool {
    SETTINGS
        .iter()
        .zip(values)
        .skip(if restore_selection { 0 } else { 2 })
        .all(|(name, value)| {
            let mut args = vec![*name, "orbit"];
            let fields = value.split_whitespace().collect::<Vec<_>>();
            if fields.is_empty() {
                args.push("");
            } else {
                args.extend(fields);
            }
            host.succeeds("resolvectl", &args)
        })
}
fn set_dns(host: &Host, state: &Dns) -> bool {
    let domains = state
        .domains
        .iter()
        .map(|d| format!("~{d}"))
        .collect::<Vec<_>>();
    let mut args = vec!["domain", &state.link];
    args.extend(domains.iter().map(String::as_str));
    host.succeeds("resolvectl", &["dns", &state.link, &state.server])
        && host.succeeds("resolvectl", &args)
}
fn enabled(host: &Host, args: &[&str]) -> bool {
    host.succeeds("systemctl", args)
}
fn state_output(host: &Host, name: &str) -> Result<String> {
    // systemctl returns nonzero for supported inactive/disabled/masked states.
    let out = host
        .tool("systemctl")
        .args([name, SERVICE])
        .stderr(Stdio::null())
        .output()
        .map_err(|_| Error::Unsupported {})?;
    String::from_utf8(out.stdout)
        .map(|s| s.trim().into())
        .map_err(|_| Error::Unsupported {})
}
fn snapshot(host: &Host, input: &Input) -> Result<Snapshot> {
    let active = match state_output(host, "is-active")?.as_str() {
        "active" => true,
        "inactive" => false,
        _ => return Err(Error::Unsupported {}),
    };
    let enabled = state_output(host, "is-enabled")?;
    require(
        [
            "enabled",
            "enabled-runtime",
            "disabled",
            "masked",
            "masked-runtime",
        ]
        .contains(&enabled.as_str()),
    )?;
    let config = SavedFile::capture(host, "orbit.conf", false)?;
    let dns_file = SavedFile::capture(host, "orbit.dns-link", true)?;
    let dns = dns_state(host)?;
    let resolver = if active { Some(resolver(host)?) } else { None };
    let uplink = if input.operator_dns.is_none() {
        dns.as_ref()
            .filter(|d| d.link != "orbit")
            .map(|d| peer_dns::capture_uplink(host, &d.link).ok_or(Error::Unsupported {}))
            .transpose()?
    } else {
        None
    };
    Ok(Snapshot {
        peer_transaction: true,
        active,
        enabled,
        config,
        dns_file,
        dns,
        resolver,
        uplink,
    })
}
fn restore(host: &Host, state: &Snapshot) -> Result<()> {
    // Stop with the current configuration before restoring a saved SaveConfig=yes file.
    if !enabled(host, &["stop", SERVICE]) {
        return Err(Error::Recovery {});
    }
    state.config.restore(host, "orbit.conf")?;
    state.dns_file.restore(host, "orbit.dns-link")?;
    for args in [
        vec!["unmask", SERVICE],
        vec!["unmask", "--runtime", SERVICE],
        vec!["disable", SERVICE],
        vec!["disable", "--runtime", SERVICE],
    ] {
        if !enabled(host, &args) {
            return Err(Error::Recovery {});
        }
    }
    let restored_enable = match state.enabled.as_str() {
        "enabled" => enabled(host, &["enable", SERVICE]),
        "enabled-runtime" => enabled(host, &["enable", "--runtime", SERVICE]),
        _ => true,
    };
    if !restored_enable {
        return Err(Error::Recovery {});
    }
    let restored_service = if state.active {
        enabled(host, &["start", SERVICE])
    } else {
        true
    };
    if !restored_service {
        return Err(Error::Recovery {});
    }
    if let Some(dns) = &state.dns {
        if dns.link != "orbit" && !set_dns(host, dns) {
            return Err(Error::Recovery {});
        }
    }
    if let Some(uplink) = &state.uplink {
        if !peer_dns::restore_uplink(host, uplink, false) {
            return Err(Error::Recovery {});
        }
    }
    if let Some(values) = &state.resolver {
        if !apply_resolver(host, values, true) {
            return Err(Error::Recovery {});
        }
    }
    let restored_mask = match state.enabled.as_str() {
        "masked" => enabled(host, &["mask", SERVICE]),
        "masked-runtime" => enabled(host, &["mask", "--runtime", SERVICE]),
        _ => true,
    };
    if !restored_mask {
        return Err(Error::Recovery {});
    }
    remove(&host.path(STATE)).map_err(|_| Error::Recovery {})
}
fn read_snapshot(host: &Host) -> Result<Option<Snapshot>> {
    let Some(bytes) = read_owned(host, STATE)? else {
        return Ok(None);
    };
    let state: Snapshot = serde_json::from_slice(&bytes).map_err(|_| Error::Unsupported {})?;
    require(
        state.peer_transaction
            && [
                "enabled",
                "enabled-runtime",
                "disabled",
                "masked",
                "masked-runtime",
            ]
            .contains(&state.enabled.as_str()),
    )?;
    if let Some(values) = &state.resolver {
        validate_resolver(values)?;
    }
    Ok(Some(state))
}
fn rollback(host: &Host) -> Result<()> {
    let _lock = locked(host)?;
    directory(host)?;
    if let Some(state) = read_snapshot(host)? {
        restore(host, &state)?;
    }
    Ok(())
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CommitInput {
    public_key: String,
}
fn commit(input: CommitInput, host: &Host) -> Result<()> {
    require(key(&input.public_key))?;
    let _lock = locked(host)?;
    directory(host)?;
    if read_snapshot(host)?.is_none() {
        return Ok(());
    }
    if host.read("wg", &["show", "orbit", "public-key"]).as_deref() != Some(&input.public_key) {
        return Err(Error::Commit {});
    }
    remove(&host.path(STATE)).map_err(|_| Error::Commit {})
}
fn render(input: &Input, private: &str) -> String {
    let dns = if let Some(server) = &input.operator_dns {
        format!("DNS = {server}")
    } else {
        peer_dns::post_up(&input.dns_server, &input.domains)
    };
    format!("[Interface]\nPrivateKey = {}\nAddress = {}\n\n{}\n\n[Peer]\nPublicKey = {}\nEndpoint = {}\nAllowedIPs = {}\nPersistentKeepalive = 25\n",private.trim(),input.address,dns,input.server_public_key,input.endpoint,input.subnet)
}
fn activate(host: &Host, input: &Input, state: &Snapshot) -> Result<()> {
    host.rename(&host.path(CANDIDATE), &host.path("orbit.conf"))
        .map_err(|_| Error::Failed {})?;
    if input.operator_dns.is_none() {
        if let Some(dns) = state.dns.as_ref().filter(|d| d.link != "orbit") {
            if !host.succeeds("resolvectl", &["revert", &dns.link])
                || !peer_dns::restore_uplink(
                    host,
                    state.uplink.as_deref().ok_or(Error::Unsupported {})?,
                    true,
                )
                || host
                    .read_bounded(15, 5, "networkctl", &["reconfigure", &dns.link])
                    .is_none()
            {
                return Err(Error::Unsupported {});
            }
            let deadline = Instant::now() + host.provider_wait;
            loop {
                if peer_dns::has_uplink(host, &input.dns_server, &dns.server)
                    && peer_dns::configured(host, 2, 2, &dns.link) == Some(true)
                {
                    break;
                }
                if Instant::now() >= deadline {
                    return Err(Error::Uplink {});
                }
                std::thread::sleep(Duration::from_millis(250));
            }
        }
    }
    let unmasked = match state.enabled.as_str() {
        "masked" => enabled(host, &["unmask", SERVICE]),
        "masked-runtime" => enabled(host, &["unmask", "--runtime", SERVICE]),
        _ => true,
    };
    if !unmasked || !enabled(host, &["enable", SERVICE]) || !enabled(host, &["restart", SERVICE]) {
        return Err(Error::Failed {});
    }
    if let Some(values) = &state.resolver {
        if !apply_resolver(host, values, false) {
            return Err(Error::Failed {});
        }
    }
    if input.operator_dns.is_none() {
        if !host.succeeds("resolvectl", &["default-route", "orbit", "no"]) {
            return Err(Error::Failed {});
        }
        publish(
            host,
            "orbit.dns-link",
            format!(
                "orbit\n{}\n{}\n",
                input.dns_server,
                input.domains.join("\n")
            )
            .as_bytes(),
            0o600,
        )?;
    } else {
        remove(&host.path("orbit.dns-link")).map_err(|_| Error::Failed {})?;
    }
    if host.read("wg", &["show", "orbit", "public-key"]).as_deref() != Some(&input.peer_public_key)
    {
        return Err(Error::Failed {});
    }
    Ok(())
}
fn publish_peer(input: Input, host: &Host) -> Result<PublicKey> {
    validate(&input)?;
    let _lock = locked(host)?;
    directory(host)?;
    if [
        STATE,
        CANDIDATE,
        ".orbit.conf.rollback",
        ".orbit.dns-link.rollback",
        ".orbit.dns-link.candidate",
        ".orbit.peer-transaction.candidate",
        ".orbit.conf.restore",
        ".orbit.dns-link.restore",
    ]
    .iter()
    .any(|name| present(&host.path(name)))
    {
        return Err(Error::Pending {});
    }
    let private = read_owned(host, "orbit.key")?.ok_or(Error::KeyMissing {})?;
    let private = String::from_utf8(private).map_err(|_| Error::KeyFailed {})?;
    if wg_public(host, private.as_bytes())? != input.peer_public_key {
        return Err(Error::KeyFailed {});
    }
    let state = snapshot(host, &input)?;
    let saved = serde_json::to_vec(&state).map_err(|_| Error::Failed {})?;
    require(saved.len() <= SNAPSHOT_LIMIT)?;
    if input.operator_dns.is_none() {
        match state.dns.as_ref().filter(|d| d.link != "orbit") {
            Some(dns) => require(peer_dns::configured(host, 10, 5, &dns.link) == Some(true))?,
            None => {
                if !peer_dns::has_uplink(
                    host,
                    &input.dns_server,
                    state.dns.as_ref().map_or("", |d| d.server.as_str()),
                ) {
                    return Err(Error::Uplink {});
                }
            }
        }
    }
    let candidate = render(&input, &private);
    publish(host, CANDIDATE, candidate.as_bytes(), 0o600)?;
    let result = (|| {
        if !host.succeeds(
            "wg-quick",
            &[
                "strip",
                host.path(CANDIDATE).to_str().ok_or(Error::Unsupported {})?,
            ],
        ) {
            return Err(Error::Failed {});
        }
        publish(host, STATE, &saved, 0o600)?;
        if let Err(error) = activate(host, &input, &state) {
            restore(host, &state)?;
            return Err(error);
        }
        if !input.retain && remove(&host.path(STATE)).is_err() {
            restore(host, &state)?;
            return Err(Error::Commit {});
        }
        Ok(PublicKey {
            public_key: input.peer_public_key,
        })
    })();
    let _ = remove(&host.path(CANDIDATE));
    result
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Empty {}
#[derive(schemars::JsonSchema, Serialize)]
pub struct Done {}
pub struct PrepareKey;
pub struct Publish;
pub struct Commit;
pub struct Rollback;
macro_rules! command {
    ($type:ty,$name:literal,$input:ty,$output:ty,$run:expr) => {
        impl Command for $type {
            const NAME: &'static str = $name;
            const SUMMARY: &'static str =
                concat!("Manage the native WireGuard peer transaction: ", $name, ".");
            const MUTATES: bool = true;
            const TIMEOUT: Duration = Duration::from_secs(120);
            type Input = $input;
            type Output = $output;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Root)
            }
            fn target(_: &Self::Input) -> Option<String> {
                Some("wireguard-peer".into())
            }
            fn run(input: Self::Input, _: &Context) -> Result<Self::Output> {
                ($run)(input, &Host::system())
            }
        }
    };
}
command!(
    PrepareKey,
    "wireguard.prepare-peer-key",
    KeyInput,
    PublicKey,
    prepare_key
);
command!(
    Publish,
    "wireguard.publish-peer",
    Input,
    PublicKey,
    publish_peer
);
command!(
    Commit,
    "wireguard.commit-peer",
    CommitInput,
    Done,
    |input, host| commit(input, host).map(|()| Done {})
);
command!(
    Rollback,
    "wireguard.rollback-peer",
    Empty,
    Done,
    |_, host| rollback(host).map(|()| Done {})
);
#[cfg(test)]
#[path = "wireguard_peer_tests.rs"]
mod tests;

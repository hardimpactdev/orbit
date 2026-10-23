//! A managed peer's split DNS (docs/reference/private-dns.md): `wireguard.inspect-peer-dns` reads the saved and live
//! resolver state under a shared peer lock, `wireguard.query-dns` sends uncached lookups, and
//! `wireguard.repair-peer-dns` moves the peer's DNS selection to the `orbit` link with the Gateway's routing domains.
//!
//! Repair runs as one transaction under the exclusive peer lock `/run/lock/orbit-wireguard-peer.lock`, which the
//! shell programs of peer convergence also take. It backs up the configuration, the DNS state, the `orbit` default
//! route flag, and the old uplink's resolver settings in `/etc/wireguard/.orbit.peer-transaction`, owned by
//! `dns-repair-v2`, before it publishes. A failure restores them. A failed restore leaves the transaction, and the
//! next repair completes it first. The file formats are those that peer convergence and earlier repairs write.

use super::wireguard::{copy_exact, link, present, regular, remove, Host};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use base64::{engine::general_purpose::STANDARD, Engine};
use regex::Regex;
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::OpenOptionsExt,
    path::Path,
    sync::LazyLock,
    time::{Duration, Instant},
};

static LINK: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[A-Za-z0-9_.:+-]+$").unwrap());
static SERVER: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[A-Fa-f0-9:.]+$").unwrap());
static DOMAIN: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[A-Za-z0-9.-]+$").unwrap());
static PUBLIC_KEY: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[A-Za-z0-9+/]{43}=$").unwrap());
static HOOK: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^PostUp = resolvectl dns %i [^;]+; resolvectl domain %i .+\r?$").unwrap()
});
static HEADER: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^\[Interface\]\r?$").unwrap());
static OLD_PRE_DOWN: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^PreDown = resolvectl revert %i\r?$|^PreDown = resolvectl dns %i ''; resolvectl domain %i ''\r?$")
        .unwrap()
});

/// The resolver settings of an uplink, in the order an uplink snapshot holds them.
const UPLINK_SETTINGS: [&str; 8] = [
    "dns",
    "domain",
    "default-route",
    "llmnr",
    "mdns",
    "dnssec",
    "dnsovertls",
    "nta",
];

/// The owner line of a repair transaction.
const OWNER: &str = "dns-repair-v2";

/// The lines of a file as `mapfile -t` reads them: a final newline ends the last line rather than starting one.
fn lines(text: &str) -> Vec<&str> {
    let mut lines: Vec<&str> = text.split('\n').collect();
    if text.is_empty() || text.ends_with('\n') {
        lines.pop();
    }
    lines
}

/// A `resolvectl <setting> <link>` value: the text after its first colon, without leading whitespace.
fn setting_value(output: &str) -> &str {
    output
        .split_once(':')
        .map_or(output, |(_, value)| value)
        .trim_start()
}

/// Holds a lock file for up to `wait`, exclusively or shared.
fn hold(path: &Path, wait: Duration, exclusive: bool) -> io::Result<Option<fs::File>> {
    let file = fs::OpenOptions::new()
        .create(true)
        .append(true)
        .mode(0o644)
        .custom_flags(libc::O_NOFOLLOW)
        .open(path)?;
    let operation = if exclusive {
        rustix::fs::FlockOperation::NonBlockingLockExclusive
    } else {
        rustix::fs::FlockOperation::NonBlockingLockShared
    };
    let deadline = Instant::now() + wait;
    loop {
        match rustix::fs::flock(&file, operation) {
            Ok(()) => return Ok(Some(file)),
            Err(rustix::io::Errno::WOULDBLOCK) if Instant::now() < deadline => {
                std::thread::sleep(Duration::from_millis(50));
            }
            Err(rustix::io::Errno::WOULDBLOCK) => return Ok(None),
            Err(error) => return Err(error.into()),
        }
    }
}

/// Whether one `resolvectl dns` server can answer public names: not the private server, the server it replaces, a
/// loopback, or an unspecified address.
fn usable(server: &str, private: &str, previous: &str) -> bool {
    let server = server.split(['%', '#']).next().unwrap_or("");
    if server == private
        || server == previous
        || server == "::1"
        || server == "::"
        || server == "0.0.0.0"
    {
        return false;
    }
    let octets: Vec<&str> = server.split('.').collect();
    if octets.len() == 4
        && octets
            .iter()
            .all(|octet| !octet.is_empty() && octet.bytes().all(|byte| byte.is_ascii_digit()))
    {
        let values: Vec<u64> = octets
            .iter()
            .map(|octet| octet.parse().unwrap_or(u64::MAX))
            .collect();
        return values.iter().all(|value| *value <= 255) && values[0] != 127;
    }
    server.contains(':') && SERVER.is_match(server)
}

/// Whether `resolvectl dns`, which also lists the global `resolved.conf` servers, names a usable server on a link
/// other than `orbit`.
pub fn usable_uplink(listing: &str, private: &str, previous: &str) -> bool {
    static SCOPE: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"^(Global|Link [0-9]+ \([^)]+\)): ?").unwrap());
    static ORBIT: LazyLock<Regex> =
        LazyLock::new(|| Regex::new(r"^Link [0-9]+ \(orbit\):").unwrap());
    listing.split('\n').any(|line| {
        if ORBIT.is_match(line) || !SCOPE.is_match(line) {
            return false;
        }
        let servers = line.split_once(':').map_or("", |(_, rest)| rest);
        servers
            .split_whitespace()
            .any(|server| usable(server, private, previous))
    })
}

fn has_uplink(host: &Host, private: &str, previous: &str) -> bool {
    host.read_bounded(5, 2, "resolvectl", &["dns"])
        .is_some_and(|listing| usable_uplink(&listing, private, previous))
}

/// The first line of `networkctl list <link>`: whether networkd names the link and has configured it.
fn configured(host: &Host, seconds: u32, kill: u32, link: &str) -> Option<bool> {
    let state = host.read_bounded(
        seconds,
        kill,
        "networkctl",
        &["--no-legend", "--no-pager", "--full", "list", link],
    )?;
    let first = state.split('\n').next().unwrap_or("");
    let fields: Vec<&str> = first.split_whitespace().collect();
    Some(fields.get(1) == Some(&link) && fields.len() == 5 && fields[4] == "configured")
}

/// The resolver settings of an uplink in the encoded form a transaction keeps: base64 of the link and one `v:` line
/// per setting, each base64 of its value.
fn capture_uplink(host: &Host, link: &str) -> Option<String> {
    if !LINK.is_match(link) || link == "orbit" {
        return None;
    }
    let mut snapshot = vec![link.to_string()];
    for setting in UPLINK_SETTINGS {
        let output = host.read("resolvectl", &[setting, link])?;
        snapshot.push(format!(
            "v:{}",
            STANDARD.encode(format!("{}\n", setting_value(&output)))
        ));
    }
    Some(STANDARD.encode(format!("{}\n", snapshot.join("\n"))))
}

/// Puts an uplink snapshot back: every setting, or with `protocols` only those other than its DNS selection.
fn restore_uplink(host: &Host, snapshot: &str, protocols: bool) -> bool {
    if snapshot.is_empty() {
        return true;
    }
    let Some(decoded) = STANDARD
        .decode(snapshot)
        .ok()
        .and_then(|bytes| String::from_utf8(bytes).ok())
    else {
        return false;
    };
    let fields = lines(decoded.trim_end_matches('\n'));
    if fields.len() != 9 || !LINK.is_match(fields[0]) || fields[0] == "orbit" {
        return false;
    }
    for (index, setting) in UPLINK_SETTINGS.iter().enumerate() {
        let encoded = fields[index + 1];
        let Some(value) = STANDARD
            .decode(encoded.strip_prefix("v:").unwrap_or(encoded))
            .ok()
            .and_then(|bytes| String::from_utf8(bytes).ok())
        else {
            return false;
        };
        if protocols && matches!(*setting, "dns" | "domain" | "default-route") {
            continue;
        }
        let mut values: Vec<&str> = value.split_whitespace().collect();
        if values.is_empty() {
            values.push("");
        }
        let mut arguments = vec![*setting, fields[0]];
        arguments.extend(values);
        if !host.succeeds("resolvectl", &arguments) {
            return false;
        }
    }
    true
}

// ---- inspect -----------------------------------------------------------------------------------------------------

command_errors! {
    pub enum InspectError {
        Busy {} => "wireguard.peer_dns_busy", "Another WireGuard peer operation holds the peer lock.";
        Unreadable {} => "wireguard.peer_dns_unreadable", "The peer's DNS state could not be read.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectInput {
    /// The VPN DNS server the peer should use. It never counts as an uplink.
    pub server: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct PeerDnsState {
    /// `/etc/wireguard/orbit.dns-link`: the link, the server, then one routing domain per line.
    pub state: String,
    /// The `PostUp = resolvectl` lines of `/etc/wireguard/orbit.conf`.
    pub hooks: String,
    /// `resolvectl dns orbit`, `resolvectl domain orbit`, and `resolvectl default-route orbit` as printed.
    pub dns: String,
    pub domains: String,
    pub default_route: String,
    /// Whether a usable non-Orbit DNS server is configured.
    pub uplink: bool,
}

fn text_of(path: &Path) -> Result<String, InspectError> {
    if !regular(path) {
        return Ok(String::new());
    }
    let mut text = fs::read_to_string(path).map_err(|_| InspectError::Unreadable {})?;
    while text.ends_with('\n') {
        text.pop();
    }
    Ok(text)
}

pub fn inspect(input: &InspectInput, host: &Host) -> Result<PeerDnsState, InspectError> {
    let _lock = hold(&host.peer_lock, host.inspect_lock_wait, false)
        .map_err(|_| InspectError::Unreadable {})?
        .ok_or(InspectError::Busy {})?;
    let state = text_of(&host.path("orbit.dns-link"))?;
    let hooks = text_of(&host.path("orbit.conf"))?
        .split('\n')
        .filter(|line| line.starts_with("PostUp = resolvectl"))
        .collect::<Vec<_>>()
        .join("\n");
    let read = |setting: &str| {
        host.read("resolvectl", &[setting, "orbit"])
            .ok_or(InspectError::Unreadable {})
    };
    let previous = lines(&state).get(1).copied().unwrap_or("").to_string();
    Ok(PeerDnsState {
        dns: read("dns")?,
        domains: read("domain")?,
        default_route: read("default-route")?,
        uplink: has_uplink(host, &input.server, &previous),
        state,
        hooks,
    })
}

pub struct InspectPeerDns;

impl Command for InspectPeerDns {
    const NAME: &'static str = "wireguard.inspect-peer-dns";
    const SUMMARY: &'static str =
        "Read a peer's saved DNS state, its PostUp hooks, and its live orbit resolver settings.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = InspectInput;
    type Output = PeerDnsState;
    type Error = InspectError;

    /// `/etc/wireguard` and the peer lock are root's.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: InspectInput, _: &Context) -> Result<PeerDnsState, InspectError> {
        inspect(&input, &Host::system())
    }
}

// ---- query -------------------------------------------------------------------------------------------------------

command_errors! {
    pub enum QueryError {
        NameInvalid {} => "wireguard.dns_name_invalid", "A name is not a DNS hostname.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct QueryInput {
    pub names: Vec<String>,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Answers {
    /// `resolvectl query --cache=no --type=A` of each name, or `None` when the lookup failed.
    pub answers: Vec<Option<String>>,
}

pub fn query(input: &QueryInput, host: &Host) -> Result<Answers, QueryError> {
    if input.names.is_empty()
        || input.names.len() > 8
        || input
            .names
            .iter()
            .any(|name| name.len() > 253 || !DOMAIN.is_match(name))
    {
        return Err(QueryError::NameInvalid {});
    }
    Ok(Answers {
        answers: input
            .names
            .iter()
            .map(|name| {
                host.read(
                    "resolvectl",
                    &["query", "--cache=no", "--type=A", name.as_str()],
                )
                .map(|answer| answer.trim_end_matches('\r').to_string())
            })
            .collect(),
    })
}

pub struct QueryDns;

impl Command for QueryDns {
    const NAME: &'static str = "wireguard.query-dns";
    const SUMMARY: &'static str = "Look names up through systemd-resolved without its cache.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = QueryInput;
    type Output = Answers;
    type Error = QueryError;

    /// Any account may ask systemd-resolved.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: QueryInput, _: &Context) -> Result<Answers, QueryError> {
        query(&input, &Host::system())
    }
}

// ---- repair ------------------------------------------------------------------------------------------------------

command_errors! {
    pub enum RepairError {
        Busy {} => "wireguard.peer_dns_busy", "Another WireGuard peer operation holds the peer lock.";
        RecoveryPending {} => "wireguard.peer_recovery_pending", "Recovery files of another peer operation are present.";
        StateUnsupported {} => "wireguard.peer_dns_state_unsupported", "The WireGuard DNS state is not one repair can change.";
        CandidateInvalid {} => "wireguard.peer_dns_candidate_invalid", "The repaired configuration did not validate; nothing changed.";
        ApplyFailed {} => "wireguard.peer_dns_apply_failed", "The repaired DNS settings did not apply; the previous ones are back.";
        RecoveryFailed {} => "wireguard.peer_dns_recovery_failed", "Restoring the previous DNS state failed; its recovery files stay.";
        UplinkMissing {} => "wireguard.peer_dns_uplink_missing", "No usable non-Orbit DNS server is configured.";
        Failed {} => "wireguard.peer_dns_repair_failed", "The DNS repair failed.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RepairInput {
    /// The VPN DNS server.
    pub dns_server: String,
    /// The public key the running `orbit` interface must have.
    pub public_key: String,
    /// The routing domains, without `~`.
    pub domains: Vec<String>,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Repaired {}

struct DnsState {
    link: String,
    server: String,
    domains: Vec<String>,
}

/// A saved DNS state file: a link, a server, and 1 to 1024 routing domains.
fn read_dns_state(path: &Path) -> Option<DnsState> {
    if !fs::metadata(path).is_ok_and(|metadata| metadata.len() > 0) {
        return None;
    }
    let text = fs::read_to_string(path).ok()?;
    let lines = lines(&text);
    let link = lines.first().copied().unwrap_or("");
    let server = lines.get(1).copied().unwrap_or("");
    let domains: Vec<String> = lines.iter().skip(2).map(ToString::to_string).collect();
    (LINK.is_match(link)
        && SERVER.is_match(server)
        && (1..=1024).contains(&domains.len())
        && domains.iter().all(|domain| DOMAIN.is_match(domain)))
    .then(|| DnsState {
        link: link.into(),
        server: server.into(),
        domains,
    })
}

fn first_lines(path: &Path) -> Vec<String> {
    fs::read_to_string(path)
        .map(|text| lines(&text).into_iter().map(ToString::to_string).collect())
        .unwrap_or_default()
}

struct Repair<'a> {
    host: &'a Host,
}

impl Repair<'_> {
    fn path(&self, name: &str) -> std::path::PathBuf {
        self.host.path(name)
    }

    fn resolvectl(&self, arguments: &[&str]) -> bool {
        self.host.succeeds("resolvectl", arguments)
    }

    fn domain(&self, link: &str, domains: &[String]) -> bool {
        let routed: Vec<String> = domains.iter().map(|domain| format!("~{domain}")).collect();
        let mut arguments = vec!["domain", link];
        arguments.extend(routed.iter().map(String::as_str));
        self.resolvectl(&arguments)
    }

    fn public_key(&self) -> Option<String> {
        self.host.read("wg", &["show", "orbit", "public-key"])
    }

    /// Removes every transaction file, and checks that the backups and the transaction are gone.
    fn cleanup(&self) -> bool {
        let names = [
            "orbit-candidate.conf",
            ".orbit.dns-link.candidate",
            ".orbit.peer-transaction.candidate",
            ".orbit.conf.restore",
            ".orbit.dns-link.restore",
            ".orbit.conf.rollback",
            ".orbit.dns-link.rollback",
            ".orbit.peer-transaction",
        ];
        names.iter().all(|name| remove(&self.path(name)).is_ok())
            && ![
                ".orbit.conf.rollback",
                ".orbit.dns-link.rollback",
                ".orbit.peer-transaction",
            ]
            .iter()
            .any(|name| present(&self.path(name)))
    }

    /// Restores the configuration, DNS state, resolver selection, uplink, and `orbit` default route a transaction
    /// saved, then removes it.
    fn recover(&self) -> bool {
        let backup = self.path(".orbit.conf.rollback");
        let dns_backup = self.path(".orbit.dns-link.rollback");
        if !regular(&backup) || link(&backup) || (!regular(&dns_backup) && !link(&dns_backup)) {
            return false;
        }
        let Some(old) = read_dns_state(&dns_backup) else {
            return false;
        };
        let restore = self.path(".orbit.conf.restore");
        let dns_restore = self.path(".orbit.dns-link.restore");
        let mut ok = true;
        if let Some(current) = read_dns_state(&self.path("orbit.dns-link")) {
            ok &= self.resolvectl(&["dns", &current.link, ""]);
            ok &= self.resolvectl(&["domain", &current.link, ""]);
        }
        ok &= remove(&restore).is_ok();
        ok &= remove(&dns_restore).is_ok();
        ok &= copy_exact(&backup, &restore).is_ok();
        if present(&restore) {
            ok &= self.host.rename(&restore, &self.path("orbit.conf")).is_ok();
        }
        ok &= copy_exact(&dns_backup, &dns_restore).is_ok();
        if present(&dns_restore) {
            ok &= self
                .host
                .rename(&dns_restore, &self.path("orbit.dns-link"))
                .is_ok();
        }
        ok &= self.resolvectl(&["dns", &old.link, &old.server]);
        ok &= self.domain(&old.link, &old.domains);
        if !ok {
            return false;
        }
        let transaction = first_lines(&self.path(".orbit.peer-transaction"));
        let uplink = transaction.get(2).map_or("", String::as_str);
        if !restore_uplink(self.host, uplink, false) {
            return false;
        }
        let default_route = transaction.get(1).map_or("", String::as_str);
        matches!(default_route, "yes" | "no")
            && self.resolvectl(&["default-route", "orbit", default_route])
            && self.cleanup()
    }

    /// The live configuration with its one DNS hook replaced, or a hook added after its only `[Interface]` header.
    /// An old `PreDown` hook after the replaced one goes.
    fn candidate(live: &str, hook: &str, old_link: &str) -> Option<String> {
        let lines = lines(live);
        let hooks = lines.iter().filter(|line| HOOK.is_match(line)).count();
        let headers = lines.iter().filter(|line| HEADER.is_match(line)).count();
        if hooks > 1 || (hooks == 0 && (old_link == "orbit" || headers != 1)) {
            return None;
        }
        let mut output = String::new();
        let mut index = 0;
        while index < lines.len() {
            let line = lines[index];
            if HOOK.is_match(line) {
                output.push_str(hook);
                if lines
                    .get(index + 1)
                    .is_some_and(|next| OLD_PRE_DOWN.is_match(next))
                {
                    index += 1;
                }
            } else {
                output.push_str(line);
                output.push('\n');
                if hooks == 0 && HEADER.is_match(line) {
                    output.push_str(hook);
                }
            }
            index += 1;
        }
        Some(output)
    }

    /// After a failed publication or apply: restored, or its recovery files stay.
    fn restored(&self) -> RepairError {
        if self.recover() {
            RepairError::ApplyFailed {}
        } else {
            RepairError::RecoveryFailed {}
        }
    }

    fn run(&self, input: &RepairInput) -> Result<(), RepairError> {
        let candidate = self.path("orbit-candidate.conf");
        let live = self.path("orbit.conf");
        let backup = self.path(".orbit.conf.rollback");
        let dns_state = self.path("orbit.dns-link");
        let dns_candidate = self.path(".orbit.dns-link.candidate");
        let dns_backup = self.path(".orbit.dns-link.rollback");
        let transaction = self.path(".orbit.peer-transaction");
        let transaction_candidate = self.path(".orbit.peer-transaction.candidate");

        if present(&transaction) {
            let owned = regular(&transaction)
                && !link(&transaction)
                && first_lines(&transaction).first().map(String::as_str) == Some(OWNER);
            if !owned {
                return Err(RepairError::RecoveryPending {});
            }
            if !self.recover() {
                return Err(RepairError::RecoveryFailed {});
            }
        } else if [
            "orbit-candidate.conf",
            ".orbit.conf.rollback",
            ".orbit.dns-link.candidate",
            ".orbit.dns-link.rollback",
            ".orbit.peer-transaction.candidate",
            ".orbit.conf.restore",
            ".orbit.dns-link.restore",
        ]
        .iter()
        .any(|name| present(&self.path(name)))
        {
            return Err(RepairError::RecoveryPending {});
        }

        let old = (regular(&live) && !link(&live) && (regular(&dns_state) || link(&dns_state)))
            .then(|| read_dns_state(&dns_state))
            .flatten()
            .filter(|_| {
                self.host
                    .succeeds("systemctl", &["is-active", "--quiet", "wg-quick@orbit"])
                    && self.public_key().as_deref() == Some(input.public_key.as_str())
            })
            .ok_or(RepairError::StateUnsupported {})?;

        let routed: Vec<String> = input
            .domains
            .iter()
            .map(|domain| format!("\\~{domain}"))
            .collect();
        let hook = format!(
            "PostUp = resolvectl dns %i {}; resolvectl domain %i {}\n",
            input.dns_server,
            routed.join(" ")
        );

        if old.link != "orbit" && configured(self.host, 10, 5, &old.link) != Some(true) {
            return Err(RepairError::StateUnsupported {});
        }
        if old.link == "orbit" && !has_uplink(self.host, &input.dns_server, &old.server) {
            return Err(RepairError::UplinkMissing {});
        }

        let discard = || {
            let _ = remove(&candidate);
            let _ = remove(&dns_candidate);
        };
        let Some(repaired) = fs::read_to_string(&live)
            .ok()
            .and_then(|live| Self::candidate(&live, &hook, &old.link))
        else {
            discard();
            return Err(RepairError::CandidateInvalid {});
        };
        if self
            .host
            .write_private(&candidate, repaired.as_bytes())
            .is_err()
        {
            discard();
            return Err(RepairError::Failed {});
        }
        if !self
            .host
            .succeeds("wg-quick", &["strip", &candidate.to_string_lossy()])
        {
            discard();
            return Err(RepairError::CandidateInvalid {});
        }
        let mut state = format!("orbit\n{}\n", input.dns_server);
        for domain in &input.domains {
            state.push_str(domain);
            state.push('\n');
        }
        if self
            .host
            .write_private(&dns_candidate, state.as_bytes())
            .is_err()
        {
            discard();
            return Err(RepairError::CandidateInvalid {});
        }

        // Nothing is published yet, so a refusal from here on leaves no candidate behind.
        let uplink = if old.link == "orbit" {
            String::new()
        } else if let Some(uplink) = capture_uplink(self.host, &old.link) {
            uplink
        } else {
            discard();
            return Err(RepairError::StateUnsupported {});
        };
        let Some(default_route) = self.host.read("resolvectl", &["default-route", "orbit"]) else {
            discard();
            return Err(RepairError::Failed {});
        };
        let default_route = default_route
            .rsplit_once(": ")
            .map_or(default_route.as_str(), |(_, value)| value)
            .to_string();
        if !matches!(default_route.as_str(), "yes" | "no") {
            discard();
            return Err(RepairError::StateUnsupported {});
        }
        let saved = copy_exact(&live, &backup)
            .and_then(|()| copy_exact(&dns_state, &dns_backup))
            .and_then(|()| {
                self.host.write_private(
                    &transaction_candidate,
                    format!("{OWNER}\n{default_route}\n{uplink}\n").as_bytes(),
                )
            })
            .and_then(|()| self.host.rename(&transaction_candidate, &transaction));
        if saved.is_err() {
            for path in [
                &candidate,
                &dns_candidate,
                &transaction_candidate,
                &backup,
                &dns_backup,
                &transaction,
            ] {
                let _ = remove(path);
            }
            return Err(RepairError::CandidateInvalid {});
        }

        if self.host.rename(&candidate, &live).is_err()
            || self.host.rename(&dns_candidate, &dns_state).is_err()
        {
            return Err(self.restored());
        }

        let mut apply_failed = false;
        if old.link != "orbit" {
            apply_failed |= !self.resolvectl(&["revert", &old.link]);
            apply_failed |= !restore_uplink(self.host, &uplink, true);
            apply_failed |= self
                .host
                .read_bounded(15, 5, "networkctl", &["reconfigure", &old.link])
                .is_none();
            let mut provider_usable = false;
            let deadline = Instant::now() + self.host.provider_wait;
            while !apply_failed && Instant::now() < deadline {
                if has_uplink(self.host, &input.dns_server, &old.server)
                    && configured(self.host, 2, 2, &old.link) == Some(true)
                {
                    provider_usable = true;
                    break;
                }
                std::thread::sleep(Duration::from_millis(250));
            }
            if !provider_usable || !has_uplink(self.host, &input.dns_server, &old.server) {
                if !self.recover() {
                    return Err(RepairError::RecoveryFailed {});
                }
                return Err(RepairError::UplinkMissing {});
            }
        }
        apply_failed |= !self.resolvectl(&["dns", "orbit", &input.dns_server]);
        apply_failed |= !self.domain("orbit", &input.domains);
        apply_failed |= !self.resolvectl(&["default-route", "orbit", "no"]);
        apply_failed |= self.public_key().as_deref() != Some(input.public_key.as_str());
        if apply_failed {
            return Err(self.restored());
        }
        if !self.cleanup() {
            return Err(RepairError::RecoveryFailed {});
        }
        Ok(())
    }
}

pub fn repair(input: &RepairInput, host: &Host) -> Result<Repaired, RepairError> {
    let valid = SERVER.is_match(&input.dns_server)
        && PUBLIC_KEY.is_match(&input.public_key)
        && (1..=512).contains(&input.domains.len())
        && input.domains.iter().all(|domain| DOMAIN.is_match(domain))
        && !(input.domains.len() != 1 && input.domains.iter().any(|domain| domain == "."));
    if !valid {
        return Err(RepairError::StateUnsupported {});
    }
    let _lock = hold(&host.peer_lock, host.lock_wait, true)
        .map_err(|_| RepairError::Busy {})?
        .ok_or(RepairError::Busy {})?;
    Repair { host }.run(input).map(|()| Repaired {})
}

pub struct RepairPeerDns;

impl Command for RepairPeerDns {
    const NAME: &'static str = "wireguard.repair-peer-dns";
    const SUMMARY: &'static str =
        "Move a peer's DNS selection to the orbit link with the given routing domains, restoring it on failure.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = RepairInput;
    type Output = Repaired;
    type Error = RepairError;

    /// `/etc/wireguard` and the resolver settings of every link are root's.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(_: &RepairInput) -> Option<String> {
        Some("wireguard-peer".into())
    }

    fn run(input: RepairInput, _: &Context) -> Result<Repaired, RepairError> {
        repair(&input, &Host::system())
    }
}

#[cfg(test)]
#[path = "peer_dns_tests.rs"]
mod tests;

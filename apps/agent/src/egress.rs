//! The `egress` fact: which outside hosts each task VM connects to. Log only, nothing is blocked.
//!
//! On a task VM host the watch list names the Incus bridge. Every [`crate::facts::EGRESS_INTERVAL`] the agent reads
//! three kinds of files (the bridge's dnsmasq query log, the kernel's connection log lines, and the bridge's host and
//! lease files), joins them per VM and reports one fact per VM. It starts no program, opens no port and writes nothing.
//! The fact holds destinations, ports and protocols only: never a URL path, packet content or a secret, and the agent
//! never writes row contents to its own log.
//!
//! The agent runs as root without capabilities, so it reads only files that it owns or that others may read. A file it
//! cannot open is "not available": the agent keeps its state, reports no fact and complains once in 10 minutes per path.
//!
//! Memory is bounded for the agent's `MemoryMax=128M`. All VMs share one byte [`BUDGET`] for rows, name and address
//! pairs and interned names. Every name is stored once and shared by the pairs and rows that use it. A row the budget or
//! the row cap refuses is counted once in `overflow`. The DNS question map adds at most 8192 entries of a few hundred
//! bytes, and at most [`MAX_SENDS`] facts are built per tick.

use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::collections::hash_map::DefaultHasher;
use std::collections::{BTreeMap, BTreeSet, HashMap, HashSet, VecDeque};
use std::fs::File;
use std::hash::{Hash, Hasher};
use std::io::{ErrorKind, Read, Seek, SeekFrom};
use std::net::Ipv4Addr;
use std::os::unix::fs::MetadataExt;
use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::time::{Duration, Instant};

/// The most a log is read per tick. A line longer than this is skipped.
const MAX_READ: u64 = 4 << 20;
/// DNS queries the agent remembers, so an answer finds its question.
const SERIAL_CAP: usize = 8192;
/// A query is forgotten this long after the agent read it.
const SERIAL_TTL: Duration = Duration::from_secs(120);
/// Rows kept per VM. At the longest destination and 8 addresses a row is about 600 bytes, so a full fact stays below
/// 1 MiB, which the Gateway refuses above.
const MAX_ROWS: usize = 1500;
/// Addresses listed on one row.
const MAX_ADDRESSES: usize = 8;
/// Name and address pairs remembered per VM; the oldest answered is dropped beyond this.
const MAX_RESOLVED: usize = 1024;
/// Names remembered per address; a new name replaces the oldest answered.
const MAX_NAMES_PER_ADDRESS: usize = 8;
/// A connection takes the name of an answer only for this long after the answer.
const CREDIT_TTL: Duration = Duration::from_secs(30 * 60);
/// Distinct refused rows counted per VM.
const MAX_REFUSED: usize = 1024;
/// VMs tracked.
const MAX_VMS: usize = 64;
/// Longest DNS name, in bytes.
const MAX_NAME: usize = 253;
/// Host files read per tick, and the bytes of the lease file.
const MAX_HOST_FILES: usize = 1024;
const MAX_IDENTITY_BYTES: u64 = 1 << 20;
/// The agent complains about one path at most this often.
const COMPLAIN_EVERY: Duration = Duration::from_secs(600);
const MAX_COMPLAINTS: usize = 64;
/// Bytes all VMs may account together. The costs below are generous estimates of what each entry holds.
const BUDGET: usize = 32 << 20;
const NAME_OVERHEAD: usize = 64;
const ROW_COST: usize = 512;
const PAIR_COST: usize = 96;
const VM_BASE: usize = 24 << 10;
/// Facts built and handed to the reporter per tick; the rest follow on later ticks.
pub const MAX_SENDS: usize = 4;
/// A changed fact waits at most this many ticks after the last one it sent (15 s at a 5 s tick).
pub const DEBOUNCE_TICKS: u32 =
    (crate::facts::EGRESS_DEBOUNCE.as_secs() / crate::facts::EGRESS_INTERVAL.as_secs()) as u32;
/// An unchanged fact is offered to the reporter after the reporter's resync interval (10 minutes), plus one tick so
/// that the reporter's own clock agrees.
pub const RESYNC_TICKS: u32 =
    (crate::facts::RESYNC_INTERVAL.as_secs() / crate::facts::EGRESS_INTERVAL.as_secs()) as u32 + 1;
/// Public resolvers that answer DNS over HTTPS on port 443.
const DOH_RESOLVERS: [Ipv4Addr; 10] = [
    Ipv4Addr::new(1, 1, 1, 1),
    Ipv4Addr::new(1, 0, 0, 1),
    Ipv4Addr::new(8, 8, 8, 8),
    Ipv4Addr::new(8, 8, 4, 4),
    Ipv4Addr::new(9, 9, 9, 9),
    Ipv4Addr::new(149, 112, 112, 112),
    Ipv4Addr::new(94, 140, 14, 14),
    Ipv4Addr::new(94, 140, 15, 15),
    Ipv4Addr::new(208, 67, 222, 222),
    Ipv4Addr::new(208, 67, 220, 220),
];

/// A bridge name from the watch list: `orbittask` and 1 to 6 lower-case letters or digits.
pub fn valid_network(name: &str) -> bool {
    name.strip_prefix("orbittask").is_some_and(|rest| {
        (1..=6).contains(&rest.len())
            && rest
                .bytes()
                .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit())
    })
}

/// The files the agent reads, for one bridge.
#[derive(Clone, Debug)]
pub struct Paths {
    pub dns_log: PathBuf,
    pub connection_log: PathBuf,
    pub hosts_dir: PathBuf,
    pub leases: PathBuf,
}

impl Paths {
    /// The host paths of `network`.
    pub fn for_network(network: &str) -> Self {
        Self::under(Path::new("/"), network)
    }

    /// The same layout below `root`, so a test can use a temporary directory.
    pub fn under(root: &Path, network: &str) -> Self {
        let networks = root.join("var/lib/incus/networks").join(network);
        Self {
            // Orbit's own file, which the host part creates. Incus keeps its dnsmasq output in another file
            // and truncates it at every dnsmasq start.
            dns_log: root.join(format!("var/log/orbit/dnsmasq-{network}.log")),
            connection_log: root.join("var/log/orbit/task-egress.log"),
            hosts_dir: networks.join("dnsmasq.hosts"),
            leases: networks.join("dnsmasq.leases"),
        }
    }
}

/// A file the agent could not read. It names the path and the kind of error, never any content.
#[derive(Debug, PartialEq, Eq)]
pub struct Unreadable {
    pub path: PathBuf,
    pub kind: ErrorKind,
}

impl Unreadable {
    fn new(path: &Path, kind: ErrorKind) -> Self {
        Self {
            path: path.to_owned(),
            kind,
        }
    }
}

/// Decides when an unreadable path is worth a line in the agent's log: once in [`COMPLAIN_EVERY`] per path.
#[derive(Default)]
struct Complaints {
    last: BTreeMap<PathBuf, Instant>,
}

impl Complaints {
    /// The line to log for `problem`, or None when this path was logged recently.
    fn note(&mut self, problem: &Unreadable, now: Instant) -> Option<String> {
        if self
            .last
            .get(&problem.path)
            .is_some_and(|at| now.saturating_duration_since(*at) < COMPLAIN_EVERY)
        {
            return None;
        }
        if self.last.len() >= MAX_COMPLAINTS {
            self.last.clear();
        }
        self.last.insert(problem.path.clone(), now);
        Some(format!(
            "orbit-agent: egress cannot read {}: {}",
            problem.path.display(),
            problem.kind
        ))
    }
}

/// A DNS name as the agent keeps it: lower case, 1 to 253 bytes of `[a-z0-9._-]`.
fn dns_name(raw: &str) -> Option<String> {
    let name = raw.to_ascii_lowercase();
    (!name.is_empty()
        && name.len() <= MAX_NAME
        && name.bytes().all(|byte| {
            byte.is_ascii_lowercase() || byte.is_ascii_digit() || b"._-".contains(&byte)
        }))
    .then_some(name)
}

/// One line of the dnsmasq log (`log-queries=extra`) that matters.
#[derive(Debug, PartialEq, Eq)]
pub enum DnsLine {
    /// An IPv4 `query[A]` of `name` by `client`.
    Query {
        serial: u64,
        client: Ipv4Addr,
        name: String,
    },
    /// An IPv4 address answered under the serial of an earlier query.
    Answer { serial: u64, address: Ipv4Addr },
}

/// Parses `Nov  7 19:00:01 dnsmasq[1234]: <serial> <ip>/<port> query[A] <name> from <ip>` and the `reply`, `cached`
/// and `config` answers. A CNAME, `NXDOMAIN`, `NODATA`, IPv6 or any other line is not a match.
pub fn parse_dns_line(line: &str) -> Option<DnsLine> {
    let (_, text) = line.split_once("]: ")?;
    let mut fields = text.split_whitespace();
    let serial: u64 = fields.next()?.parse().ok()?;
    let client = fields.next()?;
    match fields.next()? {
        "query[A]" => Some(DnsLine::Query {
            serial,
            name: dns_name(fields.next()?)?,
            client: client.split('/').next()?.parse().ok()?,
        }),
        "reply" | "cached" | "config" => {
            let _name = fields.next()?;
            (fields.next()? == "is").then_some(())?;
            Some(DnsLine::Answer {
                serial,
                address: fields.next()?.parse().ok()?,
            })
        }
        _ => None,
    }
}

/// The transport of a logged connection.
#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord, Hash)]
pub enum Proto {
    Tcp,
    Udp,
}

impl Proto {
    fn as_str(self) -> &'static str {
        match self {
            Self::Tcp => "tcp",
            Self::Udp => "udp",
        }
    }
}

/// What the log line's MAC fields say about the sender.
#[derive(Debug, PartialEq, Eq)]
pub enum Frame {
    /// No MAC field: the sender is found by its source address and the lease.
    Absent,
    /// The source MAC, from `MACSRC=` or from octets 7 to 12 of the older `MAC=` field.
    Source(String),
    /// A MAC field of another shape. The line names no VM.
    Unusable,
}

/// One new outbound connection from the kernel's log.
#[derive(Debug, PartialEq, Eq)]
pub struct Connection {
    /// The `IN=` interface: the bridge the packet came in on.
    pub interface: Option<String>,
    pub frame: Frame,
    pub source: Ipv4Addr,
    pub destination: Ipv4Addr,
    pub protocol: Proto,
    pub port: u16,
}

/// The source MAC of `MACSRC=<src MAC>`, which current kernels print with `log flags ether`.
fn macsrc(field: &str) -> Frame {
    mac(field).map_or(Frame::Unusable, Frame::Source)
}

/// The source MAC of the older `MAC=<dst6>:<src6>:<type2>` field, 14 octets of the Ethernet header.
fn source_mac(field: &str) -> Frame {
    let octets: Vec<&str> = field.split(':').collect();
    match octets.get(6..12).and_then(|source| mac(&source.join(":"))) {
        Some(mac) if octets.len() == 14 => Frame::Source(mac),
        _ => Frame::Unusable,
    }
}

/// Parses a line with `orbit-egress ` and the kernel fields `IN=`, `MACSRC=` (or the older `MAC=`), `SRC=`, `DST=`,
/// `PROTO=` and `DPT=`. Fields are found by name, so any timestamp, host or `kernel:` prefix is fine. `MACSRC=` wins
/// over `MAC=`. Only TCP and UDP count.
pub fn parse_connection_line(line: &str) -> Option<Connection> {
    let (_, fields) = line.split_once("orbit-egress ")?;
    let (mut interface, mut source_field, mut legacy_field) = (None, None, None);
    let (mut source, mut destination, mut protocol, mut port) = (None, None, None, None);
    for token in fields.split_whitespace() {
        match token.split_once('=') {
            Some(("IN", value)) if interface.is_none() => interface = Some(value.to_owned()),
            Some(("MACSRC", value)) if source_field.is_none() => source_field = Some(macsrc(value)),
            Some(("MAC", value)) if legacy_field.is_none() => {
                legacy_field = Some(source_mac(value))
            }
            Some(("SRC", value)) if source.is_none() => source = value.parse().ok(),
            Some(("DST", value)) if destination.is_none() => destination = value.parse().ok(),
            Some(("PROTO", value)) if protocol.is_none() => {
                protocol = match value {
                    "TCP" => Some(Proto::Tcp),
                    "UDP" => Some(Proto::Udp),
                    _ => None,
                }
            }
            Some(("DPT", value)) if port.is_none() => port = value.parse().ok(),
            _ => {}
        }
    }
    Some(Connection {
        interface,
        frame: source_field.or(legacy_field).unwrap_or(Frame::Absent),
        source: source?,
        destination: destination?,
        protocol: protocol?,
        port: port.filter(|port| *port != 0)?,
    })
}

/// Whether `address` is a public address: not private, loopback, link-local, shared (100.64/10), multicast, reserved or
/// unspecified.
pub fn is_outside(address: Ipv4Addr) -> bool {
    let [first, second, ..] = address.octets();
    !(address.is_private()
        || address.is_loopback()
        || address.is_link_local()
        || first == 0
        || first >= 224
        || (first == 100 && (64..128).contains(&second)))
}

/// A MAC in lower-case colon form, from any case.
fn mac(raw: &str) -> Option<String> {
    let parts: Vec<&str> = raw.split(':').collect();
    (parts.len() == 6
        && parts
            .iter()
            .all(|part| part.len() == 2 && part.bytes().all(|byte| byte.is_ascii_hexdigit())))
    .then(|| raw.to_ascii_lowercase())
}

/// A VM name the Gateway accepts as a subject: `[a-z0-9][a-z0-9._-]{0,62}`.
fn vm_name(raw: &str) -> Option<String> {
    (!raw.is_empty()
        && raw.len() <= 63
        && !b"._-".contains(&raw.as_bytes()[0])
        && raw.bytes().all(|byte| {
            byte.is_ascii_lowercase() || byte.is_ascii_digit() || b"._-".contains(&byte)
        }))
    .then(|| raw.to_owned())
}

/// A host file of Incus: `MAC,name` or `MAC,ipv4,name`. Returns the MAC and the name.
pub fn parse_host_file(text: &str) -> Option<(String, String)> {
    let line = text.lines().find(|line| !line.trim().is_empty())?;
    let mut fields = line.trim().split(',');
    let mac = mac(fields.next()?)?;
    Some((mac, vm_name(fields.next_back()?)?))
}

/// A `dnsmasq.leases` line: `expiry MAC IP name clientid`. Returns the MAC and the address.
pub fn parse_lease_line(line: &str) -> Option<(String, Ipv4Addr)> {
    let mut fields = line.split_whitespace();
    let _expiry = fields.next()?;
    let mac = mac(fields.next()?)?;
    Some((mac, fields.next()?.parse().ok()?))
}

/// Who is who on the bridge, from Incus' own files.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Identity {
    /// VM name by MAC, from the host files. The agent tracks exactly these MACs. The lease and DHCP host name are not
    /// trusted for the name.
    names: BTreeMap<String, String>,
    /// MAC by address, from the leases. Only used to find the MAC of an address.
    leases: BTreeMap<Ipv4Addr, String>,
}

impl Identity {
    /// Builds the identity from the text of each host file and of the lease file.
    pub fn parse<'a>(hosts: impl IntoIterator<Item = &'a str>, leases: &str) -> Self {
        Self {
            names: hosts.into_iter().filter_map(parse_host_file).collect(),
            leases: leases
                .lines()
                .filter_map(parse_lease_line)
                .map(|(mac, address)| (address, mac))
                .collect(),
        }
    }
}

fn read_small(path: &Path, limit: u64) -> Result<String, Unreadable> {
    let mut bytes = Vec::new();
    File::open(path)
        .and_then(|file| file.take(limit).read_to_end(&mut bytes))
        .map_err(|error| Unreadable::new(path, error.kind()))?;
    Ok(String::from_utf8_lossy(&bytes).into_owned())
}

/// Reads the host files and leases. An error when any cannot be read, so a failed read never drops a VM.
fn read_identity(paths: &Paths) -> Result<Identity, Unreadable> {
    let mut texts = Vec::new();
    let entries = std::fs::read_dir(&paths.hosts_dir)
        .map_err(|error| Unreadable::new(&paths.hosts_dir, error.kind()))?;
    for entry in entries.flatten().take(MAX_HOST_FILES) {
        if entry.file_type().is_ok_and(|kind| kind.is_file()) {
            match read_small(&entry.path(), 4096) {
                Ok(text) => texts.push(text),
                // A VM deleted between the listing and the read.
                Err(problem) if problem.kind == ErrorKind::NotFound => {}
                Err(problem) => return Err(problem),
            }
        }
    }
    let leases = read_small(&paths.leases, MAX_IDENTITY_BYTES)?;
    Ok(Identity::parse(texts.iter().map(String::as_str), &leases))
}

/// Follows a growing log from a remembered offset.
#[derive(Debug, Default)]
struct Tail {
    /// Whether the agent has looked for the file before.
    looked: bool,
    /// The inode and offset after the last complete line read. None until the agent has read the file.
    at: Option<(u64, u64)>,
}

impl Tail {
    /// The complete new lines of `path`. A file that is there when the agent first looks starts at its end. A file that
    /// appears later starts at its start. A file that does not exist reads as empty: rsyslog and dnsmasq create their
    /// logs on the first line. A file that shrank or changed inode is read from the start. At most `limit` bytes are
    /// read. An error when the file exists but cannot be opened or read.
    fn read(&mut self, path: &Path, limit: u64) -> Result<Vec<String>, Unreadable> {
        let problem = |error: std::io::Error| Unreadable::new(path, error.kind());
        let mut file = match File::open(path) {
            Ok(file) => file,
            Err(error) if error.kind() == ErrorKind::NotFound => {
                self.looked = true;
                return Ok(Vec::new());
            }
            Err(error) => return Err(problem(error)),
        };
        let metadata = file.metadata().map_err(problem)?;
        if !metadata.is_file() {
            return Err(Unreadable::new(path, ErrorKind::InvalidInput));
        }
        let (inode, length) = (metadata.ino(), metadata.len());
        let start = match self.at {
            None if !self.looked => {
                self.looked = true;
                self.at = Some((inode, length));
                return Ok(Vec::new());
            }
            None => 0,
            Some((known, offset)) if known == inode && offset <= length => offset,
            Some(_) => 0,
        };
        self.looked = true;
        if start == length {
            self.at = Some((inode, start));
            return Ok(Vec::new());
        }
        let want = (length - start).min(limit);
        let mut bytes = Vec::new();
        file.seek(SeekFrom::Start(start)).map_err(problem)?;
        file.take(want).read_to_end(&mut bytes).map_err(problem)?;
        let end = match bytes.iter().rposition(|byte| *byte == b'\n') {
            Some(at) => at + 1,
            // One line longer than the read limit would block the log for good; skip it.
            None if bytes.len() as u64 >= limit => bytes.len(),
            None => 0,
        };
        self.at = Some((inode, start + end as u64));
        Ok(bytes[..end]
            .split(|byte| *byte == b'\n')
            .filter(|line| !line.is_empty())
            .map(|line| String::from_utf8_lossy(line).into_owned())
            .collect())
    }
}

struct Asked {
    client: Ipv4Addr,
    name: String,
    at: Instant,
}

/// The DNS queries still waiting for answers, by dnsmasq serial. Bounded by [`SERIAL_CAP`] and [`SERIAL_TTL`].
#[derive(Default)]
struct Serials {
    asked: HashMap<u64, Asked>,
    order: VecDeque<(u64, Instant)>,
}

impl Serials {
    fn forget(&mut self, serial: u64, at: Instant) {
        if self.asked.get(&serial).is_some_and(|asked| asked.at == at) {
            self.asked.remove(&serial);
        }
    }

    fn expire(&mut self, now: Instant) {
        while let Some(&(serial, at)) = self.order.front() {
            if now.saturating_duration_since(at) < SERIAL_TTL {
                break;
            }
            self.order.pop_front();
            self.forget(serial, at);
        }
    }

    fn insert(&mut self, serial: u64, client: Ipv4Addr, name: String, now: Instant) {
        self.expire(now);
        while self.asked.len() >= SERIAL_CAP && !self.asked.contains_key(&serial) {
            let Some((oldest, at)) = self.order.pop_front() else {
                break;
            };
            self.forget(oldest, at);
        }
        self.asked.insert(
            serial,
            Asked {
                client,
                name,
                at: now,
            },
        );
        self.order.push_back((serial, now));
    }

    /// The question of `serial`, unless it is older than [`SERIAL_TTL`].
    fn get(&self, serial: u64, now: Instant) -> Option<&Asked> {
        self.asked
            .get(&serial)
            .filter(|asked| now.saturating_duration_since(asked.at) < SERIAL_TTL)
    }
}

/// The byte budget all VMs share, and the pool that stores each name once.
struct Shared {
    pool: HashSet<Arc<str>>,
    used: usize,
    limit: usize,
}

impl Shared {
    fn new(limit: usize) -> Self {
        Self {
            pool: HashSet::new(),
            used: 0,
            limit,
        }
    }

    /// Accounts `bytes`, or refuses when that would pass the budget.
    fn charge(&mut self, bytes: usize) -> bool {
        if self.used + bytes > self.limit {
            return false;
        }
        self.used += bytes;
        true
    }

    fn release(&mut self, bytes: usize) {
        self.used = self.used.saturating_sub(bytes);
    }

    /// The one stored copy of `name`, or None when the budget cannot hold a new one.
    fn intern(&mut self, name: &str) -> Option<Arc<str>> {
        if let Some(stored) = self.pool.get(name) {
            return Some(stored.clone());
        }
        if !self.charge(name.len() + NAME_OVERHEAD) {
            return None;
        }
        let stored: Arc<str> = Arc::from(name);
        self.pool.insert(stored.clone());
        Some(stored)
    }

    /// Drops the names that no row or pair uses any more.
    fn prune(&mut self) {
        let mut freed = 0;
        self.pool.retain(|name| {
            let used = Arc::strong_count(name) > 1;
            if !used {
                freed += name.len() + NAME_OVERHEAD;
            }
            used
        });
        self.release(freed);
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord)]
enum Kind {
    Address,
    Name,
}

/// Where a row came from. The order is the order of the names in the fact.
#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord)]
enum Source {
    Conn,
    Dns,
}

/// Flags in the order of their names.
#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord)]
enum Flag {
    Dns,
    Doh,
    Dot,
}

/// The identity of a row: destination, port (0 for none) and protocol.
type RowKey = (Arc<str>, u16, Option<Proto>);

struct Row {
    kind: Kind,
    addresses: BTreeSet<Ipv4Addr>,
    sources: BTreeSet<Source>,
    flags: BTreeSet<Flag>,
    first_seen: String,
}

impl Row {
    fn json(&self, key: &RowKey) -> Value {
        json!({
            "destination": &*key.0,
            "kind": if self.kind == Kind::Name { "name" } else { "address" },
            "port": (key.1 != 0).then_some(key.1),
            "protocol": key.2.map(Proto::as_str),
            "addresses": self.addresses.iter().map(Ipv4Addr::to_string).collect::<Vec<_>>(),
            "sources": self.sources.iter().map(|source| match source {
                Source::Conn => "conn",
                Source::Dns => "dns",
            }).collect::<Vec<_>>(),
            "flags": self.flags.iter().map(|flag| match flag {
                Flag::Dns => "dns",
                Flag::Doh => "doh",
                Flag::Dot => "dot",
            }).collect::<Vec<_>>(),
            "first_seen": self.first_seen,
        })
    }
}

/// A name a VM resolved to an address, and when it last got that answer.
struct Pair {
    name: Arc<str>,
    at: Instant,
}

fn key_hash(destination: &str, port: u16, protocol: Option<Proto>) -> u64 {
    let mut hasher = DefaultHasher::new();
    destination.hash(&mut hasher);
    port.hash(&mut hasher);
    protocol.hash(&mut hasher);
    hasher.finish()
}

fn fact_digest(data: &Value) -> String {
    format!("{:x}", Sha256::digest(data.to_string().as_bytes()))
}

/// What the agent knows about one VM.
struct Vm {
    /// Names this VM resolved to an address, with the time of the last answer.
    resolved: BTreeMap<Ipv4Addr, Vec<Pair>>,
    pairs: usize,
    rows: BTreeMap<RowKey, Row>,
    /// Rows refused, counted once each. `refused` holds the hashes of the distinct refused rows.
    overflow: u64,
    refused: HashSet<u64>,
    /// Bytes this VM accounts in the [`Shared`] budget.
    bytes: usize,
    /// Whether the fact may differ from the one last handed to the reporter.
    dirty: bool,
    /// The digest of the fact last handed to the reporter, and the name it went out under.
    digest: Option<String>,
    reported_as: Option<String>,
    /// Ticks since then.
    ticks: u32,
}

impl Vm {
    /// A new VM, or None when the budget cannot hold even its base.
    fn new(shared: &mut Shared) -> Option<Self> {
        shared.charge(VM_BASE).then(|| Self {
            resolved: BTreeMap::new(),
            pairs: 0,
            rows: BTreeMap::new(),
            overflow: 0,
            refused: HashSet::new(),
            bytes: VM_BASE,
            dirty: true,
            digest: None,
            reported_as: None,
            ticks: 0,
        })
    }

    /// Counts a refused row once, however often it comes again.
    fn refuse(&mut self, destination: &str, port: u16, protocol: Option<Proto>) {
        if self.refused.len() < MAX_REFUSED
            && self.refused.insert(key_hash(destination, port, protocol))
        {
            self.overflow += 1;
            self.dirty = true;
        }
    }

    #[allow(clippy::too_many_arguments)]
    fn upsert(
        &mut self,
        shared: &mut Shared,
        key: RowKey,
        kind: Kind,
        address: Ipv4Addr,
        sources: &[Source],
        flags: &[Flag],
        stamp: &str,
    ) {
        if let Some(row) = self.rows.get_mut(&key) {
            let before = (row.addresses.len(), row.sources.len(), row.flags.len());
            if row.addresses.len() < MAX_ADDRESSES {
                row.addresses.insert(address);
            }
            row.sources.extend(sources);
            row.flags.extend(flags);
            if before != (row.addresses.len(), row.sources.len(), row.flags.len()) {
                self.dirty = true;
            }
        } else if self.rows.len() < MAX_ROWS && shared.charge(ROW_COST) {
            self.bytes += ROW_COST;
            self.dirty = true;
            self.rows.insert(
                key,
                Row {
                    kind,
                    addresses: BTreeSet::from([address]),
                    sources: sources.iter().copied().collect(),
                    flags: flags.iter().copied().collect(),
                    first_seen: stamp.to_owned(),
                },
            );
        } else {
            self.refuse(&key.0, key.1, key.2);
        }
    }

    /// Drops the oldest answered pair of this VM.
    fn evict_oldest(&mut self, shared: &mut Shared) {
        let oldest = self
            .resolved
            .iter()
            .flat_map(|(address, pairs)| {
                pairs
                    .iter()
                    .enumerate()
                    .map(move |(index, pair)| (pair.at, *address, index))
            })
            .min_by_key(|(at, _, _)| *at);
        if let Some((_, address, index)) = oldest {
            self.remove_pair(shared, address, index);
        }
    }

    fn remove_pair(&mut self, shared: &mut Shared, address: Ipv4Addr, index: usize) {
        if let Some(pairs) = self.resolved.get_mut(&address) {
            pairs.remove(index);
            if pairs.is_empty() {
                self.resolved.remove(&address);
            }
            self.pairs -= 1;
            self.bytes -= PAIR_COST;
            shared.release(PAIR_COST);
        }
    }

    /// Remembers that the VM got `name` as an answer for `address` now. A name already known only gets a new time.
    /// A ninth name of an address replaces the oldest answered one.
    fn learn(&mut self, shared: &mut Shared, name: &Arc<str>, address: Ipv4Addr, now: Instant) {
        if let Some(pairs) = self.resolved.get_mut(&address) {
            if let Some(pair) = pairs.iter_mut().find(|pair| pair.name == *name) {
                pair.at = now;
                return;
            }
            if pairs.len() >= MAX_NAMES_PER_ADDRESS {
                if let Some(oldest) = pairs.iter_mut().min_by_key(|pair| pair.at) {
                    *oldest = Pair {
                        name: name.clone(),
                        at: now,
                    };
                }
                return;
            }
        }
        if !shared.charge(PAIR_COST) {
            return;
        }
        self.bytes += PAIR_COST;
        self.pairs += 1;
        self.resolved.entry(address).or_default().push(Pair {
            name: name.clone(),
            at: now,
        });
        if self.pairs > MAX_RESOLVED {
            self.evict_oldest(shared);
        }
    }

    /// Forgets the pairs whose answer is older than [`CREDIT_TTL`].
    fn expire(&mut self, shared: &mut Shared, now: Instant) {
        let stale: Vec<(Ipv4Addr, usize)> = self
            .resolved
            .iter()
            .flat_map(|(address, pairs)| {
                pairs
                    .iter()
                    .enumerate()
                    .filter(|(_, pair)| now.saturating_duration_since(pair.at) >= CREDIT_TTL)
                    .map(move |(index, _)| (*address, index))
            })
            .collect();
        // From the back, so earlier indexes stay valid.
        for (address, index) in stale.into_iter().rev() {
            self.remove_pair(shared, address, index);
        }
    }

    /// The names the VM got for `address` in the last [`CREDIT_TTL`].
    fn fresh_names(&self, address: Ipv4Addr, now: Instant) -> Vec<Arc<str>> {
        self.resolved
            .get(&address)
            .map(|pairs| {
                pairs
                    .iter()
                    .filter(|pair| now.saturating_duration_since(pair.at) < CREDIT_TTL)
                    .map(|pair| pair.name.clone())
                    .collect()
            })
            .unwrap_or_default()
    }

    /// The VM got `name` as an answer for `address`.
    fn answer(
        &mut self,
        shared: &mut Shared,
        name: &str,
        address: Ipv4Addr,
        now: Instant,
        stamp: &str,
    ) {
        if !is_outside(address) {
            return;
        }
        let Some(name) = shared.intern(name) else {
            return self.refuse(name, 0, None);
        };
        self.learn(shared, &name, address, now);
        self.upsert(
            shared,
            (name, 0, None),
            Kind::Name,
            address,
            &[Source::Dns],
            &[],
            stamp,
        );
    }

    /// The VM opened a connection to `address`.
    fn connect(
        &mut self,
        shared: &mut Shared,
        address: Ipv4Addr,
        protocol: Proto,
        port: u16,
        now: Instant,
        stamp: &str,
    ) {
        let mut flags = Vec::new();
        match port {
            53 => flags.push(Flag::Dns),
            853 => flags.push(Flag::Dot),
            443 if DOH_RESOLVERS.contains(&address) => flags.push(Flag::Doh),
            _ => {}
        }
        let names = self.fresh_names(address, now);
        if names.is_empty() {
            let text = address.to_string();
            let Some(destination) = shared.intern(&text) else {
                return self.refuse(&text, port, Some(protocol));
            };
            self.upsert(
                shared,
                (destination, port, Some(protocol)),
                Kind::Address,
                address,
                &[Source::Conn],
                &flags,
                stamp,
            );
        }
        for name in names {
            self.upsert(
                shared,
                (name, port, Some(protocol)),
                Kind::Name,
                address,
                &[Source::Conn, Source::Dns],
                &flags,
                stamp,
            );
        }
    }

    /// The `egress` data of this VM.
    fn data(&self, network: &str, mac: &str) -> Value {
        let connected: BTreeSet<&str> = self
            .rows
            .keys()
            .filter(|key| key.1 != 0)
            .map(|key| &*key.0)
            .collect();
        // A name the VM connected to is told by its connection rows, so its DNS-only row is left out.
        let rows: Vec<Value> = self
            .rows
            .iter()
            .filter(|(key, _)| key.1 != 0 || !connected.contains(&*key.0))
            .map(|(key, row)| row.json(key))
            .collect();
        json!({"network": network, "mac": mac, "rows": rows, "overflow": self.overflow})
    }
}

/// Reads the sources for one bridge and keeps the per-VM state.
pub struct Observer {
    network: String,
    paths: Paths,
    dns: Tail,
    connections: Tail,
    serials: Serials,
    identity: Identity,
    shared: Shared,
    vms: BTreeMap<String, Vm>,
    /// When the agent first saw each MAC the host files name; with more than [`MAX_VMS`] it tracks the newest.
    first_seen: BTreeMap<String, u64>,
    next_seen: u64,
    /// Whether the last read could open all the sources. Without it the observer reports no fact.
    available: bool,
}

impl Observer {
    pub fn new(network: &str) -> Self {
        Self::with_paths(network, Paths::for_network(network))
    }

    pub fn with_paths(network: &str, paths: Paths) -> Self {
        Self::with_budget(network, paths, BUDGET)
    }

    fn with_budget(network: &str, paths: Paths, budget: usize) -> Self {
        Self {
            network: network.to_owned(),
            paths,
            dns: Tail::default(),
            connections: Tail::default(),
            serials: Serials::default(),
            identity: Identity::default(),
            shared: Shared::new(budget),
            vms: BTreeMap::new(),
            first_seen: BTreeMap::new(),
            next_seen: 0,
            available: false,
        }
    }

    pub fn network(&self) -> &str {
        &self.network
    }

    /// One read: the identity, then the DNS log, then the connection log. It stops at the first source it cannot
    /// read and returns it. Nothing is lost by that: the logs are read on from their offsets next time, and a VM is
    /// never dropped for an identity that could not be read.
    pub fn observe(&mut self, now: Instant, stamp: &str) -> Vec<Unreadable> {
        self.available = false;
        let identity = match read_identity(&self.paths) {
            Ok(identity) => identity,
            Err(problem) => return vec![problem],
        };
        self.apply_identity(identity);
        match self.dns.read(&self.paths.dns_log, MAX_READ) {
            Ok(lines) => lines
                .iter()
                .for_each(|line| self.apply_dns(line, now, stamp)),
            Err(problem) => return vec![problem],
        }
        match self.connections.read(&self.paths.connection_log, MAX_READ) {
            Ok(lines) => lines
                .iter()
                .for_each(|line| self.apply_connection(line, now, stamp)),
            Err(problem) => return vec![problem],
        }
        for vm in self.vms.values_mut() {
            vm.expire(&mut self.shared, now);
        }
        self.shared.prune();
        self.available = true;
        Vec::new()
    }

    fn apply_identity(&mut self, identity: Identity) {
        let known: BTreeSet<&str> = identity.names.keys().map(String::as_str).collect();
        self.first_seen
            .retain(|mac, _| known.contains(mac.as_str()));
        for mac in &known {
            if !self.first_seen.contains_key(*mac) {
                self.first_seen.insert((*mac).to_owned(), self.next_seen);
                self.next_seen += 1;
            }
        }
        let mut newest: Vec<(&String, &u64)> = self.first_seen.iter().collect();
        newest.sort_by_key(|(_, seen)| std::cmp::Reverse(**seen));
        let tracked: BTreeSet<String> = newest
            .into_iter()
            .take(MAX_VMS)
            .map(|(mac, _)| mac.clone())
            .collect();
        let gone: Vec<String> = self
            .vms
            .keys()
            .filter(|mac| !tracked.contains(*mac))
            .cloned()
            .collect();
        for mac in gone {
            if let Some(vm) = self.vms.remove(&mac) {
                self.shared.release(vm.bytes);
            }
        }
        for mac in tracked {
            if !self.vms.contains_key(&mac) {
                if let Some(vm) = Vm::new(&mut self.shared) {
                    self.vms.insert(mac, vm);
                }
            }
        }
        self.identity = identity;
    }

    fn apply_dns(&mut self, line: &str, now: Instant, stamp: &str) {
        match parse_dns_line(line) {
            Some(DnsLine::Query {
                serial,
                client,
                name,
            }) => self.serials.insert(serial, client, name, now),
            Some(DnsLine::Answer { serial, address }) => {
                let Some(asked) = self.serials.get(serial, now) else {
                    return;
                };
                let (client, name) = (asked.client, asked.name.clone());
                if let Some(vm) = self
                    .identity
                    .leases
                    .get(&client)
                    .and_then(|mac| self.vms.get_mut(mac))
                {
                    vm.answer(&mut self.shared, &name, address, now, stamp);
                }
            }
            None => {}
        }
    }

    fn apply_connection(&mut self, line: &str, now: Instant, stamp: &str) {
        let Some(connection) = parse_connection_line(line) else {
            return;
        };
        // Only this bridge's lines count; the host may log other bridges into the same file.
        if connection.interface.as_deref() != Some(self.network.as_str())
            || !is_outside(connection.destination)
        {
            return;
        }
        // The Ethernet source in the log line is the VM; the address and lease are the fallback without it.
        let mac = match &connection.frame {
            Frame::Source(mac) => Some(mac),
            Frame::Absent => self.identity.leases.get(&connection.source),
            Frame::Unusable => None,
        };
        if let Some(vm) = mac.and_then(|mac| self.vms.get_mut(mac)) {
            vm.connect(
                &mut self.shared,
                connection.destination,
                connection.protocol,
                connection.port,
                now,
                stamp,
            );
        }
    }

    /// The names of the tracked VMs.
    pub fn known(&self) -> BTreeSet<String> {
        self.vms
            .keys()
            .filter_map(|mac| self.identity.names.get(mac).cloned())
            .collect()
    }

    /// The fact data of each tracked VM, by VM name. Empty while a source is unavailable.
    pub fn facts(&self) -> impl Iterator<Item = (String, Value)> + '_ {
        self.vms
            .iter()
            .filter(|_| self.available)
            .filter_map(|(mac, vm)| {
                let name = self.identity.names.get(mac)?;
                Some((name.clone(), vm.data(&self.network, mac)))
            })
    }

    /// Counts a tick and builds the facts that are due, at most `limit` of them: a VM not reported yet, one whose fact
    /// changed at least [`DEBOUNCE_TICKS`] ago, and one not reported for [`RESYNC_TICKS`]. A VM that did not change
    /// is not built at all. A VM beyond `limit` stays due for the next tick.
    pub fn due(&mut self, limit: usize) -> Vec<(String, Value)> {
        let mut due = Vec::new();
        for (mac, vm) in self.vms.iter_mut() {
            vm.ticks = vm.ticks.saturating_add(1);
            if !self.available || due.len() >= limit {
                continue;
            }
            let Some(name) = self.identity.names.get(mac) else {
                continue;
            };
            let new = vm.digest.is_none() || vm.reported_as.as_deref() != Some(name.as_str());
            let resync = vm.ticks >= RESYNC_TICKS;
            let changed = vm.dirty && vm.ticks >= DEBOUNCE_TICKS;
            if !(new || resync || changed) {
                continue;
            }
            let data = vm.data(&self.network, mac);
            let digest = fact_digest(&data);
            vm.dirty = false;
            if new || resync || vm.digest.as_deref() != Some(digest.as_str()) {
                vm.digest = Some(digest);
                vm.reported_as = Some(name.clone());
                vm.ticks = 0;
                due.push((name.clone(), data));
            }
        }
        due
    }
}

/// What the reporter does after one step: send `facts`, and forget every sent fact whose subject is not in `known`.
#[derive(Debug, Default)]
pub struct Step {
    pub facts: Vec<(String, Value)>,
    pub known: BTreeSet<String>,
}

/// The lifecycle of the observers: one per bridge that the watch list names, each with its own files, offsets and state.
#[derive(Default)]
pub struct Runner {
    observers: BTreeMap<String, Observer>,
    complaints: Complaints,
}

impl Runner {
    /// Whether the runner holds state.
    pub fn active(&self) -> bool {
        !self.observers.is_empty()
    }

    /// One tick for the bridges the watch list names. A bridge it no longer names drops its state; with none, all state goes.
    pub fn step(&mut self, networks: &[String], now: Instant, stamp: &str) -> Step {
        self.step_with(networks, now, stamp, Observer::new)
    }

    pub fn step_with(
        &mut self,
        networks: &[String],
        now: Instant,
        stamp: &str,
        make: impl Fn(&str) -> Observer,
    ) -> Step {
        self.observers
            .retain(|network, _| networks.contains(network));
        if self.observers.is_empty() && networks.is_empty() {
            *self = Self::default();
            return Step::default();
        }
        let mut step = Step::default();
        for network in networks {
            let observer = self
                .observers
                .entry(network.clone())
                .or_insert_with(|| make(network));
            for problem in observer.observe(now, stamp) {
                if let Some(line) = self.complaints.note(&problem, now) {
                    eprintln!("{line}");
                }
            }
            // The facts of one tick share the limit; a bridge that gets none this tick has its due facts built on the next.
            step.facts
                .extend(observer.due(MAX_SENDS - step.facts.len()));
            step.known.extend(observer.known());
        }
        step
    }
}

#[cfg(test)]
#[path = "egress_tests.rs"]
mod tests;

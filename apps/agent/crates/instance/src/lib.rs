//! The instance family of the executor (ADR 0271): an Instance container made from its default's seed.
//!
//! - [`clone`] (`instance.clone`): the copy's network ACL, then a copy of the seed snapshot with the copy's config, root
//!   disk and guarded NIC, then the T3 proxy device. A repeat finds the copy and only completes what is missing.
//! - [`prepare`] (`instance.prepare`): a stopped copy's files before its first start: the seed's Orbit units that are not
//!   the copy's go, its own machine id, then the boot, trust and T3 files the Gateway rendered.
//! - [`fetch_branch`] (`instance.fetch_branch`): the Instance's branch in the Node's mirror, when the origin has it.
//! - [`checkout`] (`instance.checkout`): the copy's workspace on the Instance's branch, once per Instance, and its `.env`
//!   merged from the default's and the Gateway's render.
//! - [`start`] (`instance.start`): the copy's first start (its files shifted back first on a nested Node), then its
//!   hostname and `/etc/hosts`.
//! - [`t3`] (`instance.t3`): the copy's T3 running, its two sessions issued, checked and sealed to the Gateway, and its
//!   workspace a T3 project.
//!
//! The Gateway renders every value; the Node checks each one's shape and builds every command itself. Incus runs only as
//! the fixed `incus` binary with fixed arguments.

mod checkout;
mod env;
mod fetch;
mod prepare;
mod shift;
mod start;
mod t3;
#[cfg(test)]
mod tests;

pub use checkout::checkout;
pub use fetch::fetch_branch;
pub use prepare::prepare;
pub use start::start;
pub use t3::t3;

use std::collections::BTreeMap;
use std::io::Write as _;
use std::process::{Command, Stdio};

use serde::Deserialize;
use serde_json::Value;

const TIMEOUT: &str = "/usr/bin/timeout";
pub(crate) const INCUS: &str = "/usr/bin/incus";
const CURL: &str = "/usr/bin/curl";
const SOCKET: &str = "/var/lib/incus/unix.socket";

/// Why the primitive failed: a stable code, and a detail for the executor's log only.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Failure {
    /// The code the receipt carries.
    pub code: &'static str,
    /// What the executor logs. Never sent to the Gateway.
    pub detail: String,
}

impl Failure {
    fn new(code: &'static str, detail: impl Into<String>) -> Self {
        Self { code, detail: detail.into() }
    }
}

/// What the primitive needs from the Node. The Node itself in production; a double in the tests.
pub trait System {
    /// Runs a command with a time limit, with `stdin` as its standard input, and returns its standard output.
    ///
    /// # Errors
    ///
    /// The command's standard error when it fails or cannot start.
    fn run(&self, seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String>;

    /// The content of a blob the executor read for this order, by its SHA-256.
    ///
    /// # Errors
    ///
    /// A message when the blob is not in the local cache.
    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String>;

    /// A file of the Node.
    ///
    /// # Errors
    ///
    /// A message when it cannot be read.
    fn read(&self, path: &str) -> Result<Vec<u8>, String>;

    /// Where a Node path is: itself on a Node, inside a scratch folder in the tests.
    fn path(&self, value: &str) -> std::path::PathBuf {
        std::path::PathBuf::from(value)
    }
}

/// A blob reference in an order: `{"blob": {"sha256": "…"}}`.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct BlobRef {
    pub(crate) blob: BlobDigest,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct BlobDigest {
    pub(crate) sha256: String,
}

/// The Node's own system: `timeout`-bounded commands, the executor's blob cache and the Node's files.
#[derive(Debug, Clone)]
pub struct NodeSystem {
    blobs: std::path::PathBuf,
}

impl NodeSystem {
    /// A system that reads blobs from `blobs`, the executor's content cache.
    pub fn new(blobs: impl Into<std::path::PathBuf>) -> Self {
        Self { blobs: blobs.into() }
    }
}

impl System for NodeSystem {
    fn run(&self, seconds: u32, argv: &[String], stdin: Option<&[u8]>) -> Result<String, String> {
        let mut child = Command::new(TIMEOUT)
            .arg(seconds.to_string())
            .args(argv)
            .stdin(if stdin.is_some() { Stdio::piped() } else { Stdio::null() })
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .map_err(|error| error.to_string())?;
        // A failed write still waits for the child, so no process is left behind; its exit then reports the failure.
        let written = match (stdin, child.stdin.take()) {
            (Some(bytes), Some(mut pipe)) => pipe.write_all(bytes).map_err(|error| error.to_string()),
            _ => Ok(()),
        };
        let output = child.wait_with_output().map_err(|error| error.to_string())?;
        written?;
        if output.status.success() {
            Ok(String::from_utf8_lossy(&output.stdout).into_owned())
        } else {
            Err(String::from_utf8_lossy(&output.stderr).trim().to_owned())
        }
    }

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        use sha2::Digest as _;
        if sha256.len() != 64 || !sha256.bytes().all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte)) {
            return Err("the blob reference is not a SHA-256".to_owned());
        }
        let bytes = std::fs::read(self.blobs.join(sha256)).map_err(|error| error.to_string())?;
        let held = sha2::Sha256::digest(&bytes).iter().fold(String::new(), |mut out, byte| {
            let _ = std::fmt::Write::write_fmt(&mut out, format_args!("{byte:02x}"));
            out
        });
        if held != sha256 {
            return Err("the blob does not match its SHA-256".to_owned());
        }
        Ok(bytes)
    }

    fn read(&self, path: &str) -> Result<Vec<u8>, String> {
        std::fs::read(path).map_err(|error| error.to_string())
    }
}

/// What `instance.clone` makes.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct CloneInput {
    project: String,
    name: String,
    /// The default whose seed snapshot the copy starts from.
    source: String,
    /// The admitted seed, `seed-<commit>`.
    snapshot: String,
    acl: Acl,
    /// The bridge's shared ACL, listed before the copy's own on the NIC.
    nic_acl: String,
    address: String,
    config: BTreeMap<String, String>,
    root: Root,
    /// The NIC's address filters, `security.ipv4_filtering` and `security.mac_filtering`.
    filters: BTreeMap<String, String>,
    proxy: Proxy,
    /// The `ufw` route rules that let the Node's `WireGuard` side reach the copy, such as T3's port.
    #[serde(default)]
    routes: Vec<RouteRule>,
}

/// One `ufw route allow in on <in> out on <out> from <from> to <to> port <port> proto tcp comment <comment>`.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct RouteRule {
    interface_in: String,
    interface_out: String,
    from: String,
    to: String,
    port: u16,
    comment: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Acl {
    name: String,
    /// `{description, egress, ingress}`, as Incus answers them.
    rules: Value,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Root {
    pool: String,
    size: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Proxy {
    device: String,
    /// `tcp:<address>:<port>` on the Node.
    listen: String,
    /// `tcp:<address>:<port>` in the copy.
    connect: String,
}

/// Makes the copy, or completes a copy an earlier run made: its own ACL with the admitted rules, then, when the copy does
/// not exist, a copy of the seed snapshot, then the T3 proxy device. The copy is never started here.
///
/// The admitted seed is copied when it exists. A deployment that replaced it with a newer seed leaves that newer seed, which
/// is copied instead; a retake that has not renamed its `next-` snapshot yet refuses, so the create can run again.
///
/// Answers `{"snapshot": <the seed copied, or null for an existing copy>}`.
///
/// # Errors
///
/// `instance.clone_input_invalid`, `instance.default_seed_missing`, `instance.default_seed_moved`,
/// `instance.pool_driver_unsupported` or `instance.clone_failed`.
pub fn clone(system: &impl System, input: &Value) -> Result<String, Failure> {
    let input: CloneInput = serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.clone_input_invalid", error.to_string()))?;
    check(&input).map_err(|detail| Failure::new("instance.clone_input_invalid", detail))?;
    let incus = Incus { system, code: "instance.clone_failed" };
    let instance = incus.query(&format!("/1.0/instances/{}?project={}", input.name, input.project))?;
    ensure_acl(&incus, &input.acl)?;
    let (devices, snapshot) = if let Some(found) = instance {
        (found.get("devices").cloned().unwrap_or(Value::Null), None)
    } else {
        let snapshot = seed(&incus, &input)?;
        copy(&incus, &input, &snapshot)?;
        (Value::Null, Some(snapshot))
    };
    if devices.get(&input.proxy.device).is_none() {
        incus.change(&[
            "--project",
            &input.project,
            "config",
            "device",
            "add",
            &input.name,
            &input.proxy.device,
            "proxy",
            &format!("listen={}", input.proxy.listen),
            &format!("connect={}", input.proxy.connect),
            "nat=true",
        ])?;
    }
    for rule in &input.routes {
        let port = rule.port.to_string();
        let argv: Vec<String> = [
            "/usr/sbin/ufw", "route", "allow", "in", "on", &rule.interface_in, "out", "on", &rule.interface_out, "from", &rule.from, "to", &rule.to,
            "port", &port, "proto", "tcp", "comment", &rule.comment,
        ]
        .iter()
        .map(|part| (*part).to_owned())
        .collect();
        system.run(60, &argv, None).map_err(|detail| Failure::new("instance.clone_failed", format!("ufw route: {detail}")))?;
    }
    Ok(serde_json::json!({"snapshot": snapshot}).to_string())
}

/// A name Incus allows that cannot read as an option.
pub(crate) fn name(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 63
        && value.bytes().all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'-')
        && !value.starts_with('-')
}

/// A path as a URL query value.
pub(crate) fn encode(value: &str) -> String {
    value.bytes().fold(String::new(), |mut out, byte| {
        if byte.is_ascii_alphanumeric() || b"/-._~".contains(&byte) {
            out.push(char::from(byte));
        } else {
            let _ = std::fmt::Write::write_fmt(&mut out, format_args!("%{byte:02X}"));
        }
        out
    })
}

/// The shapes the Node accepts: names Incus allows, and values that cannot carry a second device key or option.
fn check(input: &CloneInput) -> Result<(), String> {
    for (field, value) in [
        ("project", &input.project),
        ("name", &input.name),
        ("source", &input.source),
        ("acl", &input.acl.name),
        ("nic_acl", &input.nic_acl),
        ("root.pool", &input.root.pool),
        ("proxy.device", &input.proxy.device),
    ] {
        if !name(value) {
            return Err(format!("{field} is not a name"));
        }
    }
    let seed = input.snapshot.strip_prefix("seed-").unwrap_or_default();
    if seed.is_empty() || !seed.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return Err("snapshot is not seed-<commit>".to_owned());
    }
    let octets: Vec<&str> = input.address.split('.').collect();
    if octets.len() != 4 || octets.iter().any(|octet| octet.parse::<u8>().is_err()) {
        return Err("address is not IPv4".to_owned());
    }
    let size = input.root.size.trim_end_matches(|c: char| c.is_ascii_alphabetic());
    if size.is_empty() || !size.bytes().all(|byte| byte.is_ascii_digit()) || !matches!(&input.root.size[size.len()..], "GiB" | "MiB" | "GB" | "MB") {
        return Err("root.size is not a size".to_owned());
    }
    let key = |value: &str| !value.is_empty() && value.bytes().all(|byte| byte.is_ascii_alphanumeric() || b"._-".contains(&byte));
    // A `--config` value may hold commas, such as a NO_PROXY list; only a `--device` value splits on them.
    let value = |value: &str| !value.is_empty() && !value.bytes().any(|byte| byte.is_ascii_control());
    let device = |value: &str| value.bytes().all(|byte| byte != b',');
    for (k, v) in input.config.iter().chain(&input.filters) {
        if !key(k) || !value(v) || (input.filters.contains_key(k) && !device(v)) {
            return Err(format!("{k} is not a config key and value"));
        }
    }
    if input.filters.keys().any(|k| k != "security.ipv4_filtering" && k != "security.mac_filtering") {
        return Err("a filter is not an address filter".to_owned());
    }
    let endpoint = |value: &str| {
        let Some(rest) = value.strip_prefix("tcp:") else { return false };
        let Some((host, port)) = rest.rsplit_once(':') else { return false };
        host.split('.').count() == 4 && host.split('.').all(|octet| octet.parse::<u8>().is_ok()) && port.parse::<u16>().is_ok_and(|port| port > 0)
    };
    if !endpoint(&input.proxy.listen) || !endpoint(&input.proxy.connect) {
        return Err("proxy endpoint is not tcp:<IPv4>:<port>".to_owned());
    }
    let interface = |value: &str| !value.is_empty() && value.len() <= 15 && !value.starts_with('-') && value.bytes().all(|b| b.is_ascii_alphanumeric() || b"._-".contains(&b));
    let address = |value: &str| value == "any" || (value.split('.').count() == 4 && value.split('.').all(|octet| octet.parse::<u8>().is_ok()));
    for rule in &input.routes {
        let comment = rule.comment.strip_prefix("orbit:").is_some_and(|rest| !rest.is_empty() && rest.bytes().all(|b| b.is_ascii_alphanumeric() || b"._-".contains(&b)));
        if !interface(&rule.interface_in) || !interface(&rule.interface_out) || !address(&rule.from) || rule.to == "any" || !address(&rule.to) || rule.port == 0 || !comment {
            return Err("a route rule is not an Instance route rule".to_owned());
        }
    }
    if !input.acl.rules.is_object() {
        return Err("acl.rules is not an object".to_owned());
    }
    Ok(())
}

/// The copy's own ACL: made when missing, its rules written when they differ.
fn ensure_acl(incus: &Incus<'_, impl System>, acl: &Acl) -> Result<(), Failure> {
    let found = incus.query(&format!("/1.0/network-acls/{}?project=default", acl.name))?;
    if found.is_none() {
        incus.change(&["network", "acl", "create", &acl.name, "--project", "default"])?;
    }
    let held = |key: &str| found.as_ref().and_then(|found| found.get(key)).cloned().unwrap_or(Value::Null);
    let wanted = |key: &str| acl.rules.get(key).cloned().unwrap_or(Value::Null);
    if found.is_none() || ["description", "egress", "ingress"].iter().any(|key| held(key) != wanted(key)) {
        let rules = acl.rules.to_string();
        incus.write(&["network", "acl", "edit", &acl.name, "--project", "default"], rules.as_bytes())?;
    }
    Ok(())
}

/// The seed to copy: the admitted one, or the newest seed when a deployment replaced it.
fn seed(incus: &Incus<'_, impl System>, input: &CloneInput) -> Result<String, Failure> {
    let listed = incus
        .query(&format!("/1.0/instances/{}/snapshots?project={}&recursion=1", input.source, input.project))?
        .ok_or_else(|| Failure::new("instance.default_seed_missing", format!("{} not found", input.source)))?;
    let snapshots: Vec<(String, String)> = listed
        .as_array()
        .map(|rows| {
            rows.iter()
                .filter_map(|row| {
                    let name = row.get("name")?.as_str()?.rsplit('/').next()?.to_owned();
                    Some((name, row.get("created_at").and_then(Value::as_str).unwrap_or_default().to_owned()))
                })
                .collect()
        })
        .unwrap_or_default();
    if snapshots.iter().any(|(name, _)| *name == input.snapshot) {
        return Ok(input.snapshot.clone());
    }
    if !snapshots.iter().any(|(name, _)| name.starts_with("seed-") || name.starts_with("next-")) {
        return Err(Failure::new("instance.default_seed_missing", "no seed"));
    }
    let retake = format!("next-{}", &input.snapshot["seed-".len()..]);
    let newest = snapshots.iter().filter(|(name, _)| name.starts_with("seed-")).max_by(|a, b| a.1.cmp(&b.1));
    match newest {
        Some((name, _)) if !snapshots.iter().any(|(held, _)| *held == retake) => Ok(name.clone()),
        _ => Err(Failure::new("instance.default_seed_moved", "the admitted seed is being retaken")),
    }
}

/// The copy of `snapshot`, with the copy's config, its root disk and a NIC that admits only the copy's own address.
fn copy(incus: &Incus<'_, impl System>, input: &CloneInput, snapshot: &str) -> Result<(), Failure> {
    let pool = incus
        .query(&format!("/1.0/storage-pools/{}", input.root.pool))?
        .ok_or_else(|| Failure::new("instance.clone_failed", format!("pool {} not found", input.root.pool)))?;
    let driver = pool.get("driver").and_then(Value::as_str).unwrap_or_default();
    // Incus sizes a btrfs root with qgroups, which a nested Node refuses; the copy's own volume bounds that pool.
    let sized = match driver {
        "zfs" => true,
        "btrfs" => false,
        _ => return Err(Failure::new("instance.pool_driver_unsupported", driver.to_owned())),
    };
    let mut argv: Vec<String> = ["--project", &input.project, "copy", &format!("{}/{snapshot}", input.source), &input.name]
        .iter()
        .map(|part| (*part).to_owned())
        .collect();
    for (key, value) in &input.config {
        argv.extend(["--config".to_owned(), format!("{key}={value}")]);
    }
    let mut devices = vec!["root,type=disk".to_owned(), "root,path=/".to_owned(), format!("root,pool={}", input.root.pool)];
    if sized {
        devices.push(format!("root,size={}", input.root.size));
    }
    devices.extend([
        format!("eth0,ipv4.address={}", input.address),
        format!("eth0,security.acls={},{}", input.nic_acl, input.acl.name),
        "eth0,security.acls.default.egress.action=reject".to_owned(),
        "eth0,security.acls.default.ingress.action=reject".to_owned(),
    ]);
    devices.extend(input.filters.iter().map(|(key, value)| format!("eth0,{key}={value}")));
    for device in devices {
        argv.extend(["--device".to_owned(), device]);
    }
    incus.run(&argv, None).map(drop)
}

/// The `incus` binary, and its API socket through `curl` for what the CLI does not show.
pub(crate) struct Incus<'a, S: System> {
    pub(crate) system: &'a S,
    /// The code of a failed command.
    pub(crate) code: &'static str,
}

impl<S: System> Incus<'_, S> {
    /// `incus query`: the answer, or None when Incus answers that the object does not exist.
    pub(crate) fn query(&self, url: &str) -> Result<Option<Value>, Failure> {
        match self.system.run(60, &[INCUS.to_owned(), "query".to_owned(), url.to_owned()], None) {
            Ok(text) => serde_json::from_str(&text).map(Some).map_err(|error| Failure::new(self.code, format!("{url}: {error}"))),
            Err(detail) if detail.to_ascii_lowercase().contains("not found") => Ok(None),
            Err(detail) => Err(Failure::new(self.code, format!("query {url}: {detail}"))),
        }
    }

    pub(crate) fn change(&self, argv: &[&str]) -> Result<(), Failure> {
        self.run(&argv.iter().map(|part| (*part).to_owned()).collect::<Vec<_>>(), None).map(drop)
    }

    pub(crate) fn write(&self, argv: &[&str], stdin: &[u8]) -> Result<(), Failure> {
        self.run(&argv.iter().map(|part| (*part).to_owned()).collect::<Vec<_>>(), Some(stdin)).map(drop)
    }

    pub(crate) fn run(&self, argv: &[String], stdin: Option<&[u8]>) -> Result<String, Failure> {
        let mut full = vec![INCUS.to_owned()];
        full.extend_from_slice(argv);
        // A copy reads the whole seed: the bound covers a large one on a busy Node.
        self.system
            .run(300, &full, stdin)
            .map_err(|detail| Failure::new(self.code, format!("{}: {detail}", argv.join(" "))))
    }

    /// A request to the Incus API socket; answers what `--write-out` prints.
    pub(crate) fn curl(&self, argv: &[&str]) -> Result<String, String> {
        let mut full = vec![CURL.to_owned(), "--silent".to_owned(), "--unix-socket".to_owned(), SOCKET.to_owned()];
        full.extend(argv.iter().map(|part| (*part).to_owned()));
        self.system.run(30, &full, None)
    }
}

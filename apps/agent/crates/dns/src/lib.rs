//! The dns family of the executor (ADR 0271): the private DNS of a VPN Node.
//!
//! - [`publish_records`] (`dns.publish_records`): places dnsmasq's Orbit records and the private DNS listener's catalog,
//!   checks dnsmasq's configuration, then restarts dnsmasq and the listener so both load them.
//!
//! The Gateway renders both files; the Node only places them. They arrive as blobs the executor has already read and
//! checked by their SHA-256. Every command is fixed here.

#[cfg(test)]
mod tests;

use std::fs::{self, File};
use std::io::Write;
use std::os::unix::fs::{fchown, OpenOptionsExt, PermissionsExt};
use std::path::{Path, PathBuf};
use std::process::Command;

/// dnsmasq's Orbit records: a host record per Route and the TLD addresses.
pub const RECORDS: &str = "/etc/dnsmasq.d/orbit-records.conf";
/// The private DNS listener's catalog.
pub const CATALOG: &str = "/var/lib/orbit/private-dns/catalog.json";

const TIMEOUT: &str = "/usr/bin/timeout";

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
    /// Where `/` is: the real root on a Node, a scratch folder in the tests.
    fn root(&self) -> &Path;

    /// Runs a command with a time limit.
    ///
    /// # Errors
    ///
    /// The command's standard error when it fails or cannot start.
    fn run(&self, seconds: u32, argv: &[&str]) -> Result<(), String>;

    /// The content of a blob the executor read for this order, by its SHA-256.
    ///
    /// # Errors
    ///
    /// A message when the blob is not in the local cache.
    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String>;

    /// The user and group ids that own the placed files: root on a Node.
    fn owner(&self) -> (u32, u32) {
        (0, 0)
    }

    /// The path of `value` under [`System::root`].
    fn path(&self, value: &str) -> PathBuf {
        self.root().join(value.trim_start_matches('/'))
    }
}

/// The Node's own system: real paths, `timeout`-bounded commands and the executor's blob cache.
#[derive(Debug, Clone)]
pub struct NodeSystem {
    blobs: PathBuf,
}

impl NodeSystem {
    /// A system that reads blobs from `blobs`, the executor's content cache.
    pub fn new(blobs: impl Into<PathBuf>) -> Self {
        Self { blobs: blobs.into() }
    }
}

impl System for NodeSystem {
    fn root(&self) -> &Path {
        Path::new("/")
    }

    fn run(&self, seconds: u32, argv: &[&str]) -> Result<(), String> {
        let output = Command::new(TIMEOUT).arg(seconds.to_string()).args(argv).output().map_err(|error| error.to_string())?;
        if output.status.success() {
            Ok(())
        } else {
            Err(String::from_utf8_lossy(&output.stderr).trim().to_owned())
        }
    }

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        fs::read(self.blobs.join(sha256)).map_err(|error| error.to_string())
    }
}

#[derive(Debug, serde::Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    records: BlobRef,
    catalog: BlobRef,
}

/// A file's desired bytes as a blob. `from_sha256` (feature `dns.guarded_records`) names the live bytes the Gateway
/// rendered from: the file is replaced only when it still holds them, or kept when it already holds the desired bytes.
#[derive(Debug, serde::Deserialize)]
#[serde(deny_unknown_fields)]
struct BlobRef {
    blob: BlobDigest,
    #[serde(default)]
    from_sha256: Option<String>,
}

#[derive(Debug, serde::Deserialize)]
#[serde(deny_unknown_fields)]
struct BlobDigest {
    sha256: String,
}

/// Places the records and the catalog, root-owned and mode 0644, each only when it differs. Then dnsmasq checks its
/// configuration, and dnsmasq and the listener restart. They restart on every publication, as the Swamp phase did: a
/// repeat after a crash between a write and the restart must still load the files. A failed check puts the previous
/// files back and restarts nothing, so the next start of dnsmasq still finds a configuration that passes. Answers how
/// many files changed.
///
/// # Errors
///
/// `dns.input_invalid`, `dns.content_missing`, `owned_revision_conflict` (a guarded file holds neither the rendered-from nor
/// the desired bytes; nothing is written), `dns.write_failed`, `dns.check_failed` or `dns.restart_failed`.
pub fn publish_records(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = serde_json::from_value(input.clone()).map_err(|error| Failure::new("dns.input_invalid", error.to_string()))?;
    let mut contents = Vec::new();
    for (path, blob) in [(RECORDS, &input.records), (CATALOG, &input.catalog)] {
        contents.push((path, system.blob(&blob.blob.sha256).map_err(|detail| Failure::new("dns.content_missing", detail))?));
    }
    // Under the Node's DNS publication lock, as the Gateway's and Swamp's DNS writers: nothing changes the files between the guard
    // and the write.
    let _lock = lock(system)?;
    // Every guard is checked before any write, so a refusal leaves both files as they were.
    for ((path, content), blob) in contents.iter().zip([&input.records, &input.catalog]) {
        if let Some(from) = &blob.from_sha256 {
            let live = fs::symlink_metadata(system.path(path)).ok().filter(fs::Metadata::is_file).and_then(|_| fs::read(system.path(path)).ok()).unwrap_or_default();
            if live != *content && sha256_hex(&live) != *from {
                return Err(Failure::new("owned_revision_conflict", format!("{path} changed outside this Operation")));
            }
        }
    }
    // Each replaced file with what it held before: a plain file's bytes, or None when there was none.
    let mut replaced: Vec<(&str, Option<Vec<u8>>)> = Vec::new();
    for (path, content) in &contents {
        let dest = system.path(path);
        let previous = fs::symlink_metadata(&dest).ok().filter(fs::Metadata::is_file).and_then(|_| fs::read(&dest).ok());
        if previous.as_deref() == Some(content.as_slice()) && placed(&dest, system.owner()) {
            continue;
        }
        if let Err(error) = replace(&dest, content, system.owner()) {
            restore(system, &replaced);
            return Err(Failure::new("dns.write_failed", format!("{path}: {error}")));
        }
        replaced.push((path, previous));
    }
    if let Err(detail) = system.run(30, &["/usr/sbin/dnsmasq", "--test"]) {
        restore(system, &replaced);
        return Err(Failure::new("dns.check_failed", detail));
    }
    system
        .run(60, &["/usr/bin/systemctl", "restart", "dnsmasq.service", "orbit-private-dns.service"])
        .map_err(|detail| Failure::new("dns.restart_failed", detail))?;
    Ok(u32::try_from(replaced.len()).unwrap_or(u32::MAX))
}

/// The lock file every private DNS writer of this Node takes (`DnsmasqPrivateDnsManager`, `orbit_route_withdraw`).
const LOCK: &str = "/run/lock/orbit-dnsmasq.lock";

/// Takes the exclusive DNS publication lock, waiting up to 60 s for another writer.
fn lock(system: &impl System) -> Result<File, Failure> {
    let path = system.path(LOCK);
    if let Some(parent) = path.parent() {
        let _ = fs::create_dir_all(parent);
    }
    let file = fs::OpenOptions::new()
        .read(true)
        .write(true)
        .create(true)
        .truncate(false)
        .mode(0o600)
        .custom_flags(0o400_000)
        .open(&path)
        .map_err(|error| Failure::new("dns.publish_lock_invalid", error.to_string()))?;
    let deadline = std::time::Instant::now() + std::time::Duration::from_secs(60);
    loop {
        match file.try_lock() {
            Ok(()) => return Ok(file),
            Err(fs::TryLockError::WouldBlock) if std::time::Instant::now() < deadline => std::thread::sleep(std::time::Duration::from_millis(200)),
            Err(fs::TryLockError::WouldBlock) => return Err(Failure::new("dns.publish_busy", "the DNS publication lock stayed held")),
            Err(fs::TryLockError::Error(error)) => return Err(Failure::new("dns.publish_lock_failed", error.to_string())),
        }
    }
}

fn sha256_hex(bytes: &[u8]) -> String {
    use sha2::Digest as _;
    sha2::Sha256::digest(bytes).iter().fold(String::with_capacity(64), |mut hex, byte| {
        use std::fmt::Write as _;
        let _ = write!(hex, "{byte:02x}");
        hex
    })
}

/// Puts back what each replaced file held before; a file that did not exist is removed. Best effort: the failure the
/// caller reports is the one that matters.
fn restore(system: &impl System, replaced: &[(&str, Option<Vec<u8>>)]) {
    for (path, previous) in replaced {
        let dest = system.path(path);
        let _ = match previous {
            Some(bytes) => replace(&dest, bytes, system.owner()),
            None => fs::remove_file(&dest),
        };
    }
}

/// Whether the file is a plain file with the owner and mode the primitive gives it.
fn placed(path: &Path, (uid, gid): (u32, u32)) -> bool {
    use std::os::unix::fs::MetadataExt;
    fs::symlink_metadata(path).is_ok_and(|meta| meta.is_file() && meta.uid() == uid && meta.gid() == gid && meta.mode() & 0o7777 == 0o644)
}

/// Writes `content` root-owned with mode 0644 to a new file beside `dest`, then moves it in place. A link in the
/// file's place is replaced, never followed.
fn replace(dest: &Path, content: &[u8], (uid, gid): (u32, u32)) -> std::io::Result<()> {
    let parent = dest.parent().unwrap_or_else(|| Path::new("/"));
    let name = dest.file_name().map_or_else(String::new, |name| name.to_string_lossy().into_owned());
    let temporary = parent.join(format!(".orbit-dns-{}-{name}", std::process::id()));
    // A temporary file a stopped run left behind under the same name goes first.
    let _ = fs::remove_file(&temporary);
    let result = (|| {
        let mut stream = File::options().write(true).create_new(true).mode(0o600).open(&temporary)?;
        fchown(&stream, Some(uid), Some(gid))?;
        stream.set_permissions(fs::Permissions::from_mode(0o644))?;
        stream.write_all(content)?;
        stream.sync_all()?;
        fs::rename(&temporary, dest)
    })();
    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result
}

//! Host-resource primitives of the executor (ADR 0271 N3; docs/reference/executor.md#primitives). Each acts on one resource
//! that any Linux host can have, and the Gateway sends every path, name, owner, mode, lock, unit and digest.
//!
//! - [`file_ensure`] (`file.ensure`): one file, folder or link, or its absence.
//! - [`file_read`] (`file.read`): the digest, type, owner and mode of one path, read only.
//! - [`account_read`] (`account.read`): the uid, primary group and home of one account, read only through the host's NSS.
//! - [`dotenv_ensure`] (`dotenv.ensure`): keys of one dotenv file.
//! - [`acl_ensure`] (`posix.acl.ensure`): one entry of the access list of one path.
//! - [`trust_ensure`] (`ca.trust.ensure`): one certificate in the system trust store.
//! - [`unit_ensure`] (`systemd.unit.ensure`): one unit, enabled and running or stopped.
//! - [`config_apply`] (`config.apply`): one set of configuration files that share a lock, validated by a fixed validator,
//!   loaded by a reload or restart, and put back on failure.
//! - [`probe`] (`http.probe`): one HTTP answer, read with a bounded wait and a typed expectation.
//!
//! Every command is fixed here; none comes from the order. Content arrives as a blob the executor has already read and
//! checked by its SHA-256.

mod account;
mod acl;
mod config;
mod dotenv;
mod file;
mod fs;
mod lock;
mod probe;
mod read;
mod trust;
mod unit;

#[cfg(test)]
mod tests;

use std::path::{Path, PathBuf};
use std::process::Command;
use std::sync::LazyLock;
use std::time::{Duration, Instant};

use regex::Regex;
use serde::Deserialize;

pub use account::{account_read, Account};
pub use acl::acl_ensure;
pub use config::config_apply;
pub use dotenv::dotenv_ensure;
pub use file::file_ensure;
pub use probe::probe;
pub use read::{file_read, Read};
pub use trust::trust_ensure;
pub use unit::unit_ensure;

const TIMEOUT: &str = "/usr/bin/timeout";

/// A user or group name.
pub(crate) static NAME: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[a-z_][a-z0-9_-]{0,31}$").expect("a valid pattern"));
/// A SHA-256 in lower-case hex.
pub(crate) static DIGEST: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[0-9a-f]{64}$").expect("a valid pattern"));

/// Why a primitive failed: a stable code, and a detail for the executor's log only.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Failure {
    /// The code the receipt carries.
    pub code: &'static str,
    /// What the executor logs. Never sent to the Gateway: it can name paths, never content.
    pub detail: String,
}

impl Failure {
    pub(crate) fn new(code: &'static str, detail: impl Into<String>) -> Self {
        Self { code, detail: detail.into() }
    }
}

/// What the primitives need from the host. The Node itself in production; a double in the tests.
pub trait System {
    /// Where `/` is: the real root on a Node, a scratch folder in the tests.
    fn root(&self) -> &Path;

    /// Runs a command with a time limit and returns its standard output.
    ///
    /// # Errors
    ///
    /// The command's standard error when it fails or cannot start.
    fn run(&self, seconds: u32, argv: &[String]) -> Result<String, String>;

    /// Runs a command with a time limit and returns its exit code, `None` when a signal ended it, and its standard output,
    /// whatever the code.
    ///
    /// # Errors
    ///
    /// A message when the command cannot start.
    fn status(&self, seconds: u32, argv: &[String]) -> Result<(Option<i32>, String), String>;

    /// A user's id by name.
    ///
    /// # Errors
    ///
    /// A message when the user does not exist.
    fn user(&self, name: &str) -> Result<u32, String> {
        let line = self.run(10, &strings(&["/usr/bin/getent", "passwd", name]))?;
        line.trim().split(':').nth(2).and_then(|uid| uid.parse().ok()).ok_or_else(|| format!("no user {name}"))
    }

    /// A group's id by name.
    ///
    /// # Errors
    ///
    /// A message when the group does not exist.
    fn group(&self, name: &str) -> Result<u32, String> {
        let line = self.run(10, &strings(&["/usr/bin/getent", "group", name]))?;
        line.trim().split(':').nth(2).and_then(|gid| gid.parse().ok()).ok_or_else(|| format!("no group {name}"))
    }

    /// Changes the owner of a path without following a final link.
    ///
    /// # Errors
    ///
    /// The system error.
    fn lchown(&self, path: &Path, uid: u32, gid: u32) -> Result<(), String> {
        std::os::unix::fs::lchown(path, Some(uid), Some(gid)).map_err(|error| error.to_string())
    }

    /// The content of a blob the executor read for this order, by its SHA-256.
    ///
    /// # Errors
    ///
    /// A message when the blob is not in the local cache.
    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String>;

    /// The monotonic time a probe's budget is measured on.
    fn now(&self) -> Instant {
        Instant::now()
    }

    /// Waits before a probe or a lock is tried again.
    fn sleep(&self, duration: Duration) {
        std::thread::sleep(duration);
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

    fn run(&self, seconds: u32, argv: &[String]) -> Result<String, String> {
        let output = Command::new(TIMEOUT).arg(seconds.to_string()).args(argv).output().map_err(|error| error.to_string())?;
        if output.status.success() {
            Ok(String::from_utf8_lossy(&output.stdout).into_owned())
        } else {
            Err(String::from_utf8_lossy(&output.stderr).trim().to_owned())
        }
    }

    fn status(&self, seconds: u32, argv: &[String]) -> Result<(Option<i32>, String), String> {
        let output = Command::new(TIMEOUT).arg(seconds.to_string()).args(argv).output().map_err(|error| error.to_string())?;
        Ok((output.status.code(), String::from_utf8_lossy(&output.stdout).into_owned()))
    }

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        std::fs::read(self.blobs.join(sha256)).map_err(|error| error.to_string())
    }
}

/// A blob reference in an order: `{"blob": {"sha256": "…"}}`.
#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct BlobRef {
    pub(crate) blob: BlobDigest,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct BlobDigest {
    pub(crate) sha256: String,
}

impl BlobRef {
    /// The blob's bytes, only when the reference is a SHA-256 and the bytes read hash to it.
    pub(crate) fn bytes(&self, system: &impl System) -> Result<Vec<u8>, Failure> {
        if !DIGEST.is_match(&self.blob.sha256) {
            return Err(invalid("blob reference"));
        }
        let bytes = system.blob(&self.blob.sha256).map_err(|detail| Failure::new("host.content_missing", detail))?;
        if sha256(&bytes) != self.blob.sha256 {
            return Err(Failure::new("host.content_missing", "the blob does not hash to its reference"));
        }
        Ok(bytes)
    }
}

/// The owner and mode the Gateway names for a placed file or folder.
#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct Owner {
    pub(crate) user: String,
    pub(crate) group: String,
    /// Octal, such as `0644`.
    pub(crate) mode: String,
}

/// An owner resolved to ids, with its mode.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) struct Ids {
    pub(crate) uid: u32,
    pub(crate) gid: u32,
    pub(crate) mode: u32,
}

impl Owner {
    pub(crate) fn valid(&self) -> bool {
        NAME.is_match(&self.user) && NAME.is_match(&self.group) && mode(&self.mode).is_some()
    }

    pub(crate) fn ids(&self, system: &impl System, code: &'static str) -> Result<Ids, Failure> {
        Ok(Ids {
            uid: system.user(&self.user).map_err(|detail| Failure::new(code, detail))?,
            gid: system.group(&self.group).map_err(|detail| Failure::new(code, detail))?,
            mode: mode(&self.mode).ok_or_else(|| Failure::new("host.input_invalid", "mode"))?,
        })
    }
}

/// `0644` or `644` as permission bits, at most `07777`.
pub(crate) fn mode(value: &str) -> Option<u32> {
    (value.len() <= 4 && !value.is_empty()).then(|| u32::from_str_radix(value, 8).ok()).flatten().filter(|bits| *bits <= 0o7777)
}

/// An absolute path in its one spelling: below `/`, with no empty, `.` or `..` part, no trailing `/`, no line break and no
/// NUL. Two such paths name the same place only when they are equal, apart from links, so the root and its aliases fail.
pub(crate) fn absolute(value: &str) -> bool {
    value.strip_prefix('/').is_some_and(|rest| !rest.is_empty() && rest.split('/').all(|part| !part.is_empty() && part != "." && part != ".."))
        && !value.contains(['\n', '\0'])
}

/// The lower-case hex SHA-256 of `bytes`.
pub(crate) fn sha256(bytes: &[u8]) -> String {
    use sha2::{Digest as _, Sha256};
    use std::fmt::Write as _;
    Sha256::digest(bytes).iter().fold(String::with_capacity(64), |mut text, byte| {
        let _ = write!(text, "{byte:02x}");
        text
    })
}

/// The order's input in its typed shape, or `host.input_invalid`.
pub(crate) fn parse<T: serde::de::DeserializeOwned>(input: &serde_json::Value) -> Result<T, Failure> {
    serde_json::from_value(input.clone()).map_err(|error| Failure::new("host.input_invalid", error.to_string()))
}

pub(crate) fn invalid(detail: &str) -> Failure {
    Failure::new("host.input_invalid", detail.to_owned())
}

pub(crate) fn strings(values: &[&str]) -> Vec<String> {
    values.iter().map(|value| (*value).to_owned()).collect()
}

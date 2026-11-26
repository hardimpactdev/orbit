//! The route family of the executor (ADR 0271): a Route's work on one Node.
//!
//! - [`ensure_csr`] (`tls.ensure_csr`): the leaf key in its version folder, made once, and a certificate request for it.
//! - [`publish_site`] (`route.publish_site`): Caddy access to the served roots, `APP_URL`, environment links, PHP-FPM pools,
//!   the signed leaf and its account copy, the Orbit root's trust, and the Caddyfile, validated before it goes live.
//! - [`withdraw_site`] (`route.withdraw_site`): one Node's withdrawal of a Route: pools and Caddyfile without it, its leaf
//!   folders removed, Caddy reloaded and read back through its admin API.
//!
//! The Gateway renders every file; the Node only places it. Each command is built here from typed fields, never taken
//! from the order. Large or secret content arrives as a blob the executor has already read and checked by its SHA-256.

mod csr;
mod site;
mod withdraw;

#[cfg(test)]
mod tests;

use std::path::{Path, PathBuf};
use std::process::Command;

pub use csr::ensure_csr;
pub use site::publish_site;
pub use withdraw::withdraw_site;

const TIMEOUT: &str = "/usr/bin/timeout";

/// Why a route primitive failed: a stable code, and a detail for the executor's log only.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Failure {
    /// The code the receipt carries.
    pub code: &'static str,
    /// What the executor logs. Never sent to the Gateway: it can name paths, never key bytes.
    pub detail: String,
}

impl Failure {
    pub(crate) fn new(code: &'static str, detail: impl Into<String>) -> Self {
        Self { code, detail: detail.into() }
    }
}

/// An account as `getent passwd` gives it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Account {
    /// The user id.
    pub uid: u32,
    /// The primary group id.
    pub gid: u32,
    /// The home folder.
    pub home: String,
}

/// What the primitives need from the Node. The Node itself in production; a double in the tests.
pub trait System {
    /// Where `/` is: the real root on a Node, a scratch folder in the tests.
    fn root(&self) -> &Path;

    /// Runs a command with a time limit and returns its standard output.
    ///
    /// # Errors
    ///
    /// The command's standard error when it fails or cannot start.
    fn run(&self, seconds: u32, argv: &[String]) -> Result<String, String>;

    /// Looks up an account by name.
    ///
    /// # Errors
    ///
    /// A message when the account does not exist.
    fn account(&self, name: &str) -> Result<Account, String>;

    /// Looks up a group id by name.
    ///
    /// # Errors
    ///
    /// A message when the group does not exist.
    fn group(&self, name: &str) -> Result<u32, String>;

    /// Changes the owner of a path without following a final link.
    ///
    /// # Errors
    ///
    /// The system error.
    fn lchown(&self, path: &Path, uid: u32, gid: Option<u32>) -> Result<(), String>;

    /// The content of a blob the executor read for this order, by its SHA-256.
    ///
    /// # Errors
    ///
    /// A message when the blob is not in the local cache.
    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String>;

    /// The id of the user that owns the files the primitives place: root on a Node.
    fn superuser(&self) -> u32 {
        0
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

    fn account(&self, name: &str) -> Result<Account, String> {
        let line = self.run(10, &["/usr/bin/getent".to_owned(), "passwd".to_owned(), name.to_owned()])?;
        let fields: Vec<&str> = line.trim().split(':').collect();
        match fields.as_slice() {
            [_, _, uid, gid, _, home, ..] => Ok(Account {
                uid: uid.parse().map_err(|_| "uid".to_owned())?,
                gid: gid.parse().map_err(|_| "gid".to_owned())?,
                home: (*home).to_owned(),
            }),
            _ => Err(format!("no account {name}")),
        }
    }

    fn group(&self, name: &str) -> Result<u32, String> {
        let line = self.run(10, &["/usr/bin/getent".to_owned(), "group".to_owned(), name.to_owned()])?;
        line.trim().split(':').nth(2).and_then(|gid| gid.parse().ok()).ok_or_else(|| format!("no group {name}"))
    }

    fn lchown(&self, path: &Path, uid: u32, gid: Option<u32>) -> Result<(), String> {
        std::os::unix::fs::lchown(path, Some(uid), gid).map_err(|error| error.to_string())
    }

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        std::fs::read(self.blobs.join(sha256)).map_err(|error| error.to_string())
    }
}

/// A blob reference in an order: `{"blob": {"sha256": "…"}}`.
#[derive(Debug, Clone, serde::Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct BlobRef {
    pub(crate) blob: BlobDigest,
}

#[derive(Debug, Clone, serde::Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct BlobDigest {
    pub(crate) sha256: String,
}

impl BlobRef {
    pub(crate) fn text(&self, system: &impl System) -> Result<String, Failure> {
        let bytes = system.blob(&self.blob.sha256).map_err(|detail| Failure::new("route.content_missing", detail))?;
        String::from_utf8(bytes).map_err(|_| Failure::new("route.input_invalid", "blob is not UTF-8"))
    }
}

pub(crate) fn strings(values: &[&str]) -> Vec<String> {
    values.iter().map(|value| (*value).to_owned()).collect()
}

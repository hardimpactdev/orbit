//! The incus family of the executor (ADR 0271 N3): desired-state primitives on one Incus resource each, through the Incus
//! REST API on its unix socket. No Incus command runs.
//!
//! - [`acl_ensure`] (`incus.acl.ensure`): one network ACL in one project, or its absence.
//! - [`device_ensure`] (`incus.device.ensure`): one device key of one instance, or its absence.
//! - [`volume_ensure`] (`incus.volume.ensure`): one custom storage volume copied from a source volume or snapshot, or its absence.
//!
//! Every project, pool, name, device and rule comes from the Gateway. Each primitive reads the resource, changes only what
//! differs, reads it back and answers the number of changes; a repeat answers 0. Every request and every wait for a
//! background operation shares one deadline, `timeout_ms` from the input.

mod acl;
mod client;
mod device;
mod volume;

#[cfg(test)]
mod tests;

use std::path::PathBuf;
use std::sync::LazyLock;
use std::time::Duration;

use regex::Regex;
use serde::de::DeserializeOwned;
use serde_json::Value;

pub use acl::acl_ensure;
pub use device::device_ensure;
pub use volume::volume_ensure;

use client::Client;

/// The Incus daemon's own socket when the input names none.
pub const DEFAULT_SOCKET: &str = "/var/lib/incus/unix.socket";

/// A project, pool, instance, volume, snapshot, ACL or device name: one URL path part, never `.`, `..` or a separator.
pub(crate) static NAME: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$").expect("a valid pattern"));

/// Why a primitive failed: a stable code, and a detail for the executor's log only.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Failure {
    /// The code the receipt carries.
    pub code: &'static str,
    /// What the executor logs, such as Incus's own error. Never sent to the Gateway.
    pub detail: String,
}

impl Failure {
    pub(crate) fn new(code: &'static str, detail: impl Into<String>) -> Self {
        Self {
            code,
            detail: detail.into(),
        }
    }
}

/// Whether the resource must exist or be gone.
#[derive(Debug, Clone, Copy, PartialEq, Eq, serde::Deserialize)]
#[serde(rename_all = "lowercase")]
pub(crate) enum State {
    Present,
    Absent,
}

/// Parses an input into its typed shape; an unknown field or a wrong type refuses before any request.
pub(crate) fn input<T: DeserializeOwned>(input: &Value) -> Result<T, Failure> {
    serde_json::from_value(input.clone())
        .map_err(|error| Failure::new("incus.input_invalid", error.to_string()))
}

/// Checks each name, the socket and the time limit, and opens the client.
pub(crate) fn connect(
    socket: Option<&str>,
    timeout_ms: u64,
    names: &[&str],
) -> Result<Client, Failure> {
    if let Some(name) = names.iter().find(|name| !NAME.is_match(name)) {
        return Err(Failure::new(
            "incus.input_invalid",
            format!("{name:?} is not an Incus name"),
        ));
    }
    if !(1_000..=600_000).contains(&timeout_ms) {
        return Err(Failure::new(
            "incus.input_invalid",
            "timeout_ms is outside 1000..=600000",
        ));
    }
    let socket = socket.unwrap_or(DEFAULT_SOCKET);
    if !socket.starts_with('/')
        || socket
            .split('/')
            .skip(1)
            .any(|part| part.is_empty() || part == "." || part == "..")
    {
        return Err(Failure::new(
            "incus.input_invalid",
            "socket is not a plain absolute path",
        ));
    }
    Ok(Client::new(
        PathBuf::from(socket),
        Duration::from_millis(timeout_ms),
    ))
}

/// The read-back after a change found the resource other than asked.
pub(crate) fn not_converged(what: &str) -> Failure {
    Failure::new(
        "incus.not_converged",
        format!("{what} reads back other than asked"),
    )
}

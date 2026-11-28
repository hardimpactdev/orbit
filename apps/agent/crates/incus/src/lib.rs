//! The incus family of the executor (ADR 0271 N3; docs/reference/executor.md#primitives): primitives on one Incus resource,
//! through the Incus REST API over its unix socket. No primitive here runs the `incus` command.
//!
//! - [`instance_ensure`] (`incus.instance.ensure`): one Incus instance, copied from a snapshot, running, stopped or absent.
//!
//! The Gateway sends every project, instance, snapshot and profile name. The socket is the Incus default unless the input
//! names another one.

mod api;
mod instance;

#[cfg(test)]
mod tests;

pub use api::NodeSystem;
pub use instance::instance_ensure;

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

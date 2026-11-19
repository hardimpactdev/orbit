//! The firewall family of the executor (ADR 0271): `ufw.ensure_rule`, a desired-state primitive on one ufw rule.
//!
//! The Gateway sends one typed rule. The primitive reads the Node's own table, places the rule by Orbit's order
//! ([`plan`]), applies each change as an argument list it builds itself, reads the table again and checks it. It
//! answers how many changes it made.

pub mod plan;

#[cfg(test)]
mod tests;

use std::process::Command;

use plan::{check, inventory, validated, Input, Refusal};

const UFW: &str = "/usr/sbin/ufw";
const TIMEOUT: &str = "/usr/bin/timeout";

/// Why `ufw.ensure_rule` failed: a stable code, and a detail for the executor's log only.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Failure {
    /// The code the receipt carries.
    pub code: &'static str,
    /// What the executor logs, such as ufw's own error. Never sent to the Gateway.
    pub detail: String,
}

impl From<Refusal> for Failure {
    fn from(code: Refusal) -> Self {
        Self { code, detail: String::new() }
    }
}

/// How the primitive reaches ufw. The Node's ufw in production; a double in the tests.
pub trait Ufw {
    /// The output of `ufw status numbered`.
    ///
    /// # Errors
    ///
    /// `firewall.read_failed` when ufw cannot be read.
    fn status(&self) -> Result<String, Failure>;

    /// Runs `ufw` with these arguments.
    ///
    /// # Errors
    ///
    /// `firewall.apply_failed` when ufw refuses the change.
    fn apply(&self, args: &[String]) -> Result<(), Failure>;
}

/// The Node's own ufw, each call bounded by `timeout`.
#[derive(Debug, Default, Clone, Copy)]
pub struct NodeUfw;

impl Ufw for NodeUfw {
    fn status(&self) -> Result<String, Failure> {
        match Command::new(TIMEOUT).args(["30", UFW, "status", "numbered"]).output() {
            Ok(output) if output.status.success() => Ok(String::from_utf8_lossy(&output.stdout).into_owned()),
            Ok(output) => Err(Failure {
                code: "firewall.read_failed",
                detail: String::from_utf8_lossy(&output.stderr).trim().to_owned(),
            }),
            Err(error) => Err(Failure { code: "firewall.read_failed", detail: error.to_string() }),
        }
    }

    fn apply(&self, args: &[String]) -> Result<(), Failure> {
        match Command::new(TIMEOUT).arg("60").arg(UFW).args(args).output() {
            Ok(output) if output.status.success() => Ok(()),
            Ok(output) => Err(Failure {
                code: "firewall.apply_failed",
                detail: String::from_utf8_lossy(&output.stderr).trim().to_owned(),
            }),
            Err(error) => Err(Failure { code: "firewall.apply_failed", detail: error.to_string() }),
        }
    }
}

/// Converges one rule and returns how many ufw changes it made; 0 means the rule was already right.
///
/// A partial apply is not rolled back: a repeat of the same order reads the table again and repairs only the missing
/// lines.
///
/// # Errors
///
/// A [`Failure`] with `firewall.input_invalid`, `firewall.backend_inactive`, `firewall.read_failed`,
/// `firewall.apply_failed`, `firewall.verify_failed` or `firewall.remove_verify_failed`.
pub fn ensure_rule(ufw: &impl Ufw, input: &serde_json::Value) -> Result<u32, Failure> {
    let rule = serde_json::from_value::<Input>(input.clone())
        .map_err(|_| Failure::from("firewall.input_invalid"))
        .and_then(|input| validated(input).map_err(Failure::from))?;
    let before = ufw.status()?;
    let commands = plan::plan(&rule, inventory(&before)?);
    for args in &commands {
        ufw.apply(args)?;
    }
    let after = if commands.is_empty() { before } else { ufw.status()? };
    check(&rule, &inventory(&after)?)?;
    Ok(u32::try_from(commands.len()).unwrap_or(u32::MAX))
}

//! Command mode: `orbit-agent command <name>` runs one named Node action (ADR 0225).
//!
//! Standard input carries one newline-terminated JSON envelope and an optional bounded binary body; standard output carries declared binary chunks and one JSON result. Redacted logs go to
//! standard error. From the first released command-mode agent, newline framing and the hash check and reply never change, so it answers a
//! newer Gateway with a mismatch it can act on: the envelope's `agent_sha256` field, the check of the
//! executing binary against it, and the shape of the `agent.version_mismatch` result. The check runs
//! first, before the command name is looked up or the input is parsed.

pub mod binary_input;
pub mod binary_output;
pub mod effects;
pub mod identity;
pub mod lock;
pub mod registry;
pub mod run;
pub mod supervise;

use serde_json::{json, Value};
use std::io::{BufRead, BufReader, Read, Write};

pub use orbit_agent_contract::{Command, CommandError, Context, Platform, Privilege};

/// The envelope field that carries the expected SHA-256 of the agent binary. Frozen.
pub const SHA_FIELD: &str = "agent_sha256";
/// The error code of a binary that is not the expected one. Frozen.
pub const VERSION_MISMATCH: &str = "agent.version_mismatch";
/// The largest envelope the agent reads from standard input.
pub const MAX_ENVELOPE_BYTES: u64 = 4 * 1024 * 1024;

/// Errors that every command can return, before or around its own work.
pub const COMMON_ERRORS: &[&str] = &[
    VERSION_MISMATCH,
    "agent.envelope_invalid",
    "agent.binary_input_invalid",
    "agent.unknown_command",
    "agent.invalid_input",
    "agent.identity_mismatch",
    "agent.platform_unsupported",
    "agent.deadline_exceeded",
    "agent.supervisor_failed",
    "agent.supervisor_not_ready",
    "agent.target_busy",
    "agent.lock_unavailable",
    "agent.effect_key_invalid",
    "agent.effect_conflict",
    "agent.effect_unknown",
    "agent.effect_receipt_unavailable",
    "agent.effect_receipt_invalid",
    "agent.effect_sanitization_required",
    "agent.effect_unsanitized",
    "agent.effect_expired",
];

/// These refusals are emitted only before command locks/receipt intent or supervisor spawn.
pub const NOT_STARTED_ERRORS: &[&str] = &[
    "agent.target_busy",
    "agent.lock_unavailable",
    "agent.supervisor_not_ready",
];
/// Terminal preflight refusals; no command effect or target receipt has started.
pub const PREFLIGHT_ERRORS: &[&str] = &[
    "agent.invalid_input",
    "agent.envelope_invalid",
    VERSION_MISMATCH,
    "agent.unknown_command",
];

/// A complete result line.
#[derive(Debug, Clone, PartialEq, serde::Serialize, serde::Deserialize)]
pub enum Outcome {
    Ok(Value),
    Refused {
        code: String,
        message: String,
        details: Value,
    },
}
impl Outcome {
    pub fn refused(code: &str, message: impl Into<String>, details: Value) -> Self {
        Self::Refused {
            code: code.to_string(),
            message: message.into(),
            details,
        }
    }
    pub fn to_json(&self) -> Value {
        match self {
            Self::Ok(data) => json!({"ok": true, "data": data}),
            Self::Refused {
                code,
                message,
                details,
            } => {
                json!({"ok": false, "error": {"code": code, "message": message, "details": details}})
            }
        }
    }
}

/// The entry point of `orbit-agent command <name>`. Returns the process exit code: 0 for every valid
/// result, including a refusal.
pub fn main(arguments: &[String]) -> i32 {
    let [name] = arguments else {
        eprintln!("orbit-agent: usage: orbit-agent command <name>");
        return 64;
    };
    let mut envelope = Vec::new();
    let mut stdin = BufReader::new(std::io::stdin());
    if let Err(error) = stdin
        .by_ref()
        .take(MAX_ENVELOPE_BYTES + 1)
        .read_until(b'\n', &mut envelope)
    {
        eprintln!("orbit-agent: cannot read the envelope: {error}");
        return 74;
    }
    let outcome = if envelope.len() as u64 > MAX_ENVELOPE_BYTES {
        Outcome::refused(
            "agent.envelope_invalid",
            "The envelope exceeds its limit.",
            json!({}),
        )
    } else {
        run::execute_stream(name, &envelope, &run::Host::current(), Box::new(stdin))
    };
    let line = serde_json::to_string(&outcome.to_json()).expect("a result serializes");
    if registry::find(name).is_some_and(|entry| entry.output_max_bytes > 0) {
        return binary_output::finish(&line);
    }
    let mut stdout = std::io::stdout().lock();
    if writeln!(stdout, "{line}")
        .and_then(|()| stdout.flush())
        .is_err()
    {
        return 74;
    }
    0
}

//! Command mode: `orbit-agent command <name>` runs one named Node action (ADR 0225).
//!
//! Standard input carries one JSON envelope, standard output one JSON result, and redacted logs go to
//! standard error. Three things never change across agent versions, so every command-mode agent answers a
//! newer Gateway with a mismatch it can act on: the envelope's `agent_sha256` field, the check of the
//! executing binary against it, and the shape of the `agent.version_mismatch` result. The check runs
//! first, before the command name is looked up or the input is parsed.

pub mod identity;
pub mod lock;
pub mod registry;
pub mod run;
pub mod supervise;

use serde::{de::DeserializeOwned, Serialize};
use serde_json::{json, Map, Value};
use std::{
    io::{Read, Write},
    time::{Duration, Instant},
};

pub use identity::{Platform, Privilege};

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
    "agent.unknown_command",
    "agent.invalid_input",
    "agent.identity_mismatch",
    "agent.platform_unsupported",
    "agent.deadline_exceeded",
    "agent.supervisor_failed",
    "agent.target_busy",
    "agent.lock_unavailable",
];

/// One named Node action with a typed contract.
pub trait Command {
    const NAME: &'static str;
    const SUMMARY: &'static str;
    /// A mutation runs under its own supervisor and target lock; a read takes no lock.
    const MUTATES: bool;
    /// The longest the command may run. The envelope's remaining deadline can shorten it.
    const TIMEOUT: Duration;
    type Input: DeserializeOwned;
    type Output: Serialize;
    type Error: CommandError;

    /// The identity the command runs as on each platform, or `None` where it is unsupported.
    fn privilege(platform: Platform) -> Option<Privilege>;

    /// The canonical target a mutation locks exclusively, such as a checkout path. Reads take no lock.
    fn target(_input: &Self::Input) -> Option<String> {
        None
    }

    /// The shared resources a mutation changes, locked after its target in sorted order.
    const RESOURCES: &'static [&'static str] = &[];
    fn run(input: Self::Input, context: &Context) -> Result<Self::Output, Self::Error>;
}

/// A declared, typed refusal or failure of one command.
pub trait CommandError {
    /// Every code this error type can return.
    const CODES: &'static [&'static str];
    fn code(&self) -> &'static str;
    fn message(&self) -> String;
    fn details(&self) -> Value {
        Value::Object(Map::new())
    }
}

/// What a running command knows about its call.
pub struct Context {
    pub platform: Platform,
    deadline: Instant,
}
impl Context {
    pub fn new(platform: Platform, deadline: Instant) -> Self {
        Self { platform, deadline }
    }
    pub fn deadline(&self) -> Instant {
        self.deadline
    }
    /// The time left, never less than zero.
    pub fn remaining(&self) -> Duration {
        self.deadline.saturating_duration_since(Instant::now())
    }
}

/// A complete result line.
#[derive(Debug, Clone, PartialEq)]
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
    if let Err(error) = std::io::stdin()
        .take(MAX_ENVELOPE_BYTES + 1)
        .read_to_end(&mut envelope)
    {
        eprintln!("orbit-agent: cannot read the envelope: {error}");
        return 74;
    }
    let outcome = run::execute(name, &envelope, &run::Host::current());
    let mut stdout = std::io::stdout().lock();
    let line = serde_json::to_string(&outcome.to_json()).expect("a result serializes");
    if writeln!(stdout, "{line}")
        .and_then(|()| stdout.flush())
        .is_err()
    {
        return 74;
    }
    0
}

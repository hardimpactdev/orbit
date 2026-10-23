//! The compiled set of commands and the contract the Gateway reads from it.

use super::{
    identity::{Platform, Privilege},
    Command, CommandError, Context, Outcome, COMMON_ERRORS,
};
use serde_json::{json, Value};
use std::{sync::OnceLock, time::Duration};

/// One command with its types erased, so the registry can hold them together.
pub struct Entry {
    pub name: &'static str,
    pub summary: &'static str,
    pub mutates: bool,
    pub timeout: Duration,
    pub privilege: fn(Platform) -> Option<Privilege>,
    pub errors: &'static [&'static str],
    pub run: fn(Value, &Context) -> Outcome,
}

pub fn entry<C: Command>() -> Entry {
    Entry {
        name: C::NAME,
        summary: C::SUMMARY,
        mutates: C::MUTATES,
        timeout: C::TIMEOUT,
        privilege: C::privilege,
        errors: <C::Error as CommandError>::CODES,
        run: run::<C>,
    }
}

fn run<C: Command>(input: Value, context: &Context) -> Outcome {
    let input: C::Input = match serde_json::from_value(input) {
        Ok(input) => input,
        Err(error) => {
            return Outcome::refused(
                "agent.invalid_input",
                "The input does not match the command.",
                json!({"reason": error.to_string()}),
            )
        }
    };
    let _locks = if C::MUTATES {
        match C::target(&input) {
            Some(target) => match super::lock::acquire_all(
                &super::lock::lock_directory(),
                &target,
                C::RESOURCES,
                context.deadline(),
            ) {
                Ok(held) => held,
                Err(super::lock::LockError::Busy(path)) => {
                    return Outcome::refused(
                        "agent.target_busy",
                        "Another command holds the lock of this target.",
                        json!({"lock": path}),
                    )
                }
                Err(super::lock::LockError::Io(path, reason)) => {
                    return Outcome::refused(
                        "agent.lock_unavailable",
                        "The lock of this target cannot be opened.",
                        json!({"lock": path, "reason": reason}),
                    )
                }
            },
            None => Vec::new(),
        }
    } else {
        Vec::new()
    };
    match C::run(input, context) {
        Ok(output) => Outcome::Ok(serde_json::to_value(output).expect("an output serializes")),
        Err(error) => Outcome::refused(error.code(), error.message(), error.details()),
    }
}

/// Every command this binary implements. Each extension adds one line here (ADR 0210, ADR 0225).
fn build() -> Vec<Entry> {
    crate::commands::all()
}

pub fn all() -> &'static [Entry] {
    static ENTRIES: OnceLock<Vec<Entry>> = OnceLock::new();
    ENTRIES.get_or_init(build)
}

pub fn find(name: &str) -> Option<&'static Entry> {
    all().iter().find(|entry| entry.name == name)
}

/// The contract the Gateway and its tests read: each command's privilege, bound, and error codes.
pub fn contract() -> Value {
    let commands: Vec<Value> = all()
        .iter()
        .map(|entry| {
            json!({
                "name": entry.name,
                "summary": entry.summary,
                "mutates": entry.mutates,
                "timeout_seconds": entry.timeout.as_secs(),
                "privilege": {
                    "linux": (entry.privilege)(Platform::Linux),
                    "macos": (entry.privilege)(Platform::Macos),
                },
                "errors": entry.errors,
            })
        })
        .collect();
    json!({"common_errors": COMMON_ERRORS, "commands": commands})
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::BTreeSet;

    #[test]
    fn names_are_unique_and_namespaced() {
        let mut seen = BTreeSet::new();
        for entry in all() {
            assert!(seen.insert(entry.name), "duplicate command {}", entry.name);
            let (area, action) = entry.name.split_once('.').expect("area.action");
            assert!(!area.is_empty() && !action.is_empty(), "{}", entry.name);
            assert!(
                entry
                    .name
                    .bytes()
                    .all(|byte| byte.is_ascii_lowercase() || b".-".contains(&byte)),
                "{}",
                entry.name
            );
        }
    }

    #[test]
    fn command_errors_are_namespaced_and_never_repeat_a_common_error() {
        for entry in all() {
            for code in entry.errors {
                assert!(code.contains('.'), "{}: {code}", entry.name);
                assert!(!COMMON_ERRORS.contains(code), "{}: {code}", entry.name);
            }
        }
    }

    #[test]
    fn every_command_is_bounded_and_runs_on_linux() {
        for entry in all() {
            assert!(entry.timeout > Duration::ZERO, "{}", entry.name);
            assert!(
                (entry.privilege)(Platform::Linux).is_some(),
                "{}",
                entry.name
            );
        }
    }

    /// The Gateway and its tests read `contract/commands.json`. Rewrite it with
    /// `ORBIT_AGENT_WRITE_CONTRACT=1 cargo test contract_file`.
    #[test]
    fn contract_file_matches_the_compiled_commands() {
        let path = concat!(env!("CARGO_MANIFEST_DIR"), "/contract/commands.json");
        let expected = serde_json::to_string_pretty(&contract()).unwrap() + "\n";
        if std::env::var_os("ORBIT_AGENT_WRITE_CONTRACT").is_some() {
            std::fs::write(path, &expected).unwrap();
        }
        let actual = std::fs::read_to_string(path).unwrap_or_default();
        assert_eq!(
            actual, expected,
            "contract/commands.json is stale; run ORBIT_AGENT_WRITE_CONTRACT=1 cargo test contract_file"
        );
    }
}

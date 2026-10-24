//! The compiled set of commands and the contract the Gateway reads from it.

use super::{
    identity::{Platform, Privilege},
    Command, CommandError, Context, Outcome, COMMON_ERRORS,
};
use orbit_agent_contract::{Registrar, StartHook};
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
            Some(target) => match super::lock::lock_directory()
                .map_err(|reason| {
                    super::lock::LockError::Io(super::lock::LOCK_ROOT_LINUX.into(), reason)
                })
                .and_then(|directory| {
                    super::lock::acquire_all(&directory, &target, C::RESOURCES, context.deadline())
                }) {
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
    invoke::<C>(input, context)
}

fn invoke<C: Command>(input: C::Input, context: &Context) -> Outcome {
    match C::run(input, context) {
        Ok(output) => Outcome::Ok(serde_json::to_value(output).expect("an output serializes")),
        Err(error) => Outcome::refused(error.code(), error.message(), error.details()),
    }
}

/// Startup behavior and its filenames belong to the compiled contributor.
pub struct HookEntry {
    pub command: &'static str,
    pub name: &'static str,
    pub drop_in: &'static str,
    pub description: &'static str,
    pub validate: fn(Value) -> Result<(), Outcome>,
    pub run: fn(Value, &Context) -> Outcome,
}
fn validate_hook<H: StartHook>(input: Value) -> Result<(), Outcome> {
    let input = serde_json::from_value(input).map_err(|_| {
        Outcome::refused(
            "agent.invalid_input",
            "The startup input does not match the command.",
            json!({}),
        )
    })?;
    if H::target(&input).as_deref() != Some(orbit_agent_contract::START_HOOK_TARGET) {
        return Err(Outcome::refused(
            "agent.invalid_input",
            "Startup hooks must use the shared startup target.",
            json!({}),
        ));
    }
    H::validate(&input).map_err(|e| Outcome::refused(e.code(), e.message(), e.details()))
}
fn run_hook<H: StartHook>(input: Value, context: &Context) -> Outcome {
    let context = Context::new(
        context.platform,
        context
            .deadline()
            .min(std::time::Instant::now() + H::TIMEOUT),
    );
    match serde_json::from_value(input) {
        Ok(input) => invoke::<H>(input, &context),
        Err(_) => Outcome::refused(
            "agent.invalid_input",
            "The startup input does not match the command.",
            json!({}),
        ),
    }
}
struct Registry {
    commands: Vec<Entry>,
    hooks: Vec<HookEntry>,
}
impl Registrar for Registry {
    fn command<C: Command>(&mut self) {
        self.commands.push(entry::<C>());
    }
    fn start_hook<H: StartHook>(&mut self) {
        assert!(
            H::MUTATES
                && H::RESOURCES.is_empty()
                && H::privilege(Platform::Linux) == Some(Privilege::Root)
                && H::privilege(Platform::Macos).is_none(),
            "startup hooks must be Linux root mutations"
        );
        self.command::<H>();
        self.hooks.push(HookEntry {
            command: H::NAME,
            name: H::FILE_NAME,
            drop_in: H::DROP_IN,
            description: H::DESCRIPTION,
            validate: validate_hook::<H>,
            run: run_hook::<H>,
        });
    }
}
/// One composition call per extension. Core knows only the shared contracts.
fn registry() -> &'static Registry {
    static REGISTRY: OnceLock<Registry> = OnceLock::new();
    REGISTRY.get_or_init(|| {
        let mut registry = Registry {
            commands: crate::commands::all(),
            hooks: Vec::new(),
        };
        orbit_documents_agent::register(&mut registry);
        registry
    })
}
pub fn all() -> &'static [Entry] {
    &registry().commands
}
pub fn hooks() -> &'static [HookEntry] {
    &registry().hooks
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
    fn every_command_is_bounded_and_runs_on_at_least_one_platform() {
        for entry in all() {
            assert!(entry.timeout > Duration::ZERO, "{}", entry.name);
            assert!(
                [Platform::Linux, Platform::Macos]
                    .into_iter()
                    .any(|platform| (entry.privilege)(platform).is_some()),
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

//! One call from envelope to result.

use super::{
    identity::{effective_uid, Platform, Privilege},
    registry::{self, Entry},
    supervise, Context, Outcome, SHA_FIELD, VERSION_MISMATCH,
};
use serde_json::{json, Value};
use std::{
    io::Read,
    path::PathBuf,
    sync::mpsc,
    time::{Duration, Instant},
};

/// Set in the environment of a mutation that already runs under its supervisor.
pub const SUPERVISED_ENV: &str = "ORBIT_AGENT_SUPERVISED";

/// What this process is: the facts every call checks before it acts.
pub struct Host {
    pub platform: Platform,
    pub euid: u32,
    /// SHA-256 of the executing binary, or why it could not be read.
    pub executable_sha256: Result<String, String>,
    pub executable: PathBuf,
    /// Whether this process is the supervised half of a mutation.
    pub supervised: bool,
    /// The account that started this process through `sudo`, from `SUDO_USER`.
    pub sudo_user: Option<String>,
}
impl Host {
    pub fn current() -> Self {
        let executable = std::env::current_exe().unwrap_or_else(|_| PathBuf::from("orbit-agent"));
        Self {
            platform: Platform::current(),
            euid: effective_uid(),
            executable_sha256: own_digest(),
            executable,
            supervised: std::env::var_os(SUPERVISED_ENV).is_some(),
            sudo_user: std::env::var("SUDO_USER")
                .ok()
                .filter(|user| !user.is_empty() && user != "root"),
        }
    }
}

/// SHA-256 of the running executable. On Linux `/proc/self/exe` names the open binary even after an
/// atomic replacement, so a running command checks the code it actually runs.
fn own_digest() -> Result<String, String> {
    let path = if cfg!(target_os = "linux") {
        PathBuf::from("/proc/self/exe")
    } else {
        std::env::current_exe().map_err(|error| error.to_string())?
    };
    file_sha256(&path).map_err(|error| format!("{}: {error}", path.display()))
}

pub fn file_sha256(path: &std::path::Path) -> std::io::Result<String> {
    use sha2::{Digest, Sha256};
    let mut file = std::fs::File::open(path)?;
    let mut hasher = Sha256::new();
    let mut buffer = vec![0; 1 << 16];
    loop {
        let read = file.read(&mut buffer)?;
        if read == 0 {
            break;
        }
        hasher.update(&buffer[..read]);
    }
    Ok(hex(&hasher.finalize()))
}

pub fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|byte| format!("{byte:02x}")).collect()
}

fn is_sha256(value: &str) -> bool {
    value.len() == 64
        && value
            .bytes()
            .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
}

pub fn execute(name: &str, envelope: &[u8], host: &Host) -> Outcome {
    // 1. The frozen part: the expected digest, checked before anything else is read.
    let Ok(Value::Object(mut fields)) = serde_json::from_slice::<Value>(envelope) else {
        return Outcome::refused(
            "agent.envelope_invalid",
            "The envelope is not one JSON object.",
            json!({}),
        );
    };
    let expected = match fields.remove(SHA_FIELD) {
        Some(Value::String(expected)) if is_sha256(&expected) => expected,
        _ => {
            return Outcome::refused(
                "agent.envelope_invalid",
                "The envelope has no lowercase hexadecimal agent_sha256.",
                json!({"field": SHA_FIELD}),
            )
        }
    };
    let observed = match &host.executable_sha256 {
        Ok(observed) => observed.clone(),
        Err(reason) => {
            return Outcome::refused(
                VERSION_MISMATCH,
                "The agent cannot read its own executable.",
                json!({"expected": expected, "observed": null, "reason": reason}),
            )
        }
    };
    if observed != expected {
        return Outcome::refused(
            VERSION_MISMATCH,
            "This agent binary is not the one the Gateway pins.",
            json!({"expected": expected, "observed": observed}),
        );
    }

    // 2. The versioned part: same binary, same contract.
    let Some(entry) = registry::find(name) else {
        return Outcome::refused(
            "agent.unknown_command",
            "This agent has no command with that name.",
            json!({"command": name}),
        );
    };
    let deadline_ms = match fields.remove("deadline_ms") {
        Some(Value::Number(number)) if number.as_u64().is_some() => number.as_u64().unwrap(),
        _ => {
            return Outcome::refused(
                "agent.envelope_invalid",
                "The envelope has no deadline_ms.",
                json!({"field": "deadline_ms"}),
            )
        }
    };
    let Some(input) = fields.remove("input") else {
        return Outcome::refused(
            "agent.envelope_invalid",
            "The envelope has no input.",
            json!({"field": "input"}),
        );
    };
    if let Some(field) = fields.keys().next() {
        return Outcome::refused(
            "agent.envelope_invalid",
            "The envelope has an unknown field.",
            json!({"field": field}),
        );
    }

    let Some(privilege) = (entry.privilege)(host.platform) else {
        return Outcome::refused(
            "agent.platform_unsupported",
            "This command does not run on this platform.",
            json!({"command": name, "platform": host.platform}),
        );
    };
    let budget = entry.timeout.min(Duration::from_millis(deadline_ms));
    if budget.is_zero() {
        return Outcome::refused(
            "agent.deadline_exceeded",
            "No time is left for the command.",
            json!({}),
        );
    }
    let deadline = Instant::now() + budget;

    // On Linux a mutation starts through `sudo -n`, because only root starts its service. The service then runs as
    // the identity the privilege declares, and the supervised process checks that identity below.
    if entry.mutates && host.platform == Platform::Linux && !host.supervised {
        if host.euid != 0 {
            return Outcome::refused(
                "agent.identity_mismatch",
                "A mutation starts through sudo, so it can run under its own service.",
                json!({"privilege": privilege, "euid": host.euid}),
            );
        }
        let user = match privilege {
            Privilege::Account => match &host.sudo_user {
                Some(user) => Some(user.as_str()),
                None => {
                    return Outcome::refused(
                        "agent.identity_mismatch",
                        "An account mutation needs the account that started it through sudo.",
                        json!({"privilege": privilege}),
                    )
                }
            },
            Privilege::Owner | Privilege::Root => None,
        };
        let owner = match user {
            Some(user) => super::identity::account(user).map(|(uid, gid, _)| (uid, gid)),
            None => Ok((0, 0)),
        };
        let locks = owner.and_then(|(uid, gid)| {
            super::lock::prepare(std::path::Path::new(super::lock::LOCK_ROOT_LINUX), uid, gid)
                .map_err(|error| error.to_string())
        });
        let locks = match locks {
            Ok(locks) => locks,
            Err(reason) => {
                return Outcome::refused(
                    "agent.lock_unavailable",
                    "The lock directory of this command cannot be prepared.",
                    json!({"lock": super::lock::LOCK_ROOT_LINUX, "reason": reason}),
                )
            }
        };
        return supervise::launch(&host.executable, name, envelope, budget, user, &locks);
    }

    if !privilege.accepts(host.platform, host.euid) {
        return Outcome::refused(
            "agent.identity_mismatch",
            "The command does not run as the identity its privilege declares.",
            json!({"privilege": privilege, "euid": host.euid}),
        );
    }

    run_in_process(entry, input, Context::new(host.platform, deadline))
}

fn run_in_process(entry: &'static Entry, input: Value, context: Context) -> Outcome {
    let deadline = context.deadline();
    let (sender, receiver) = mpsc::channel();
    std::thread::spawn(move || {
        let _ = sender.send((entry.run)(input, &context));
    });
    match receiver.recv_timeout(deadline.saturating_duration_since(Instant::now())) {
        Ok(outcome) => outcome,
        Err(mpsc::RecvTimeoutError::Timeout) => Outcome::refused(
            "agent.deadline_exceeded",
            "The command did not finish before its deadline.",
            json!({}),
        ),
        Err(mpsc::RecvTimeoutError::Disconnected) => Outcome::refused(
            "agent.supervisor_failed",
            "The command ended without a result.",
            json!({}),
        ),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const PIN: &str = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    fn host(euid: u32) -> Host {
        Host {
            platform: Platform::Linux,
            euid,
            executable_sha256: Ok(PIN.to_string()),
            executable: PathBuf::from("/usr/local/bin/orbit-agent"),
            supervised: false,
            sudo_user: None,
        }
    }

    fn code(outcome: &Outcome) -> &str {
        match outcome {
            Outcome::Refused { code, .. } => code,
            Outcome::Ok(_) => "ok",
        }
    }

    #[test]
    fn answers_another_binary_with_a_mismatch_before_it_reads_the_name_or_the_input() {
        let other = "b".repeat(64);
        // An unknown command, an unknown envelope field, and invalid input still get the frozen mismatch.
        let envelope =
            json!({"agent_sha256": other, "deadline_ms": "soon", "future_field": 1, "input": 7});
        let outcome = execute(
            "a.command.this.agent.never.heard.of",
            envelope.to_string().as_bytes(),
            &host(1000),
        );
        assert_eq!(
            outcome.to_json(),
            json!({"ok": false, "error": {"code": "agent.version_mismatch",
                "message": "This agent binary is not the one the Gateway pins.",
                "details": {"expected": other, "observed": PIN}}})
        );
    }

    #[test]
    fn refuses_an_envelope_without_a_valid_digest() {
        for envelope in [
            "not json".to_string(),
            json!([]).to_string(),
            json!({"deadline_ms": 1000, "input": {}}).to_string(),
            json!({"agent_sha256": "ABC", "deadline_ms": 1000, "input": {}}).to_string(),
        ] {
            assert_eq!(
                code(&execute("agent.status", envelope.as_bytes(), &host(1000))),
                "agent.envelope_invalid"
            );
        }
    }

    #[test]
    fn refuses_an_unknown_command_and_unknown_envelope_fields_after_the_digest_matches() {
        let unknown = json!({"agent_sha256": PIN, "deadline_ms": 1000, "input": {}}).to_string();
        assert_eq!(
            code(&execute("no.such", unknown.as_bytes(), &host(1000))),
            "agent.unknown_command"
        );
        let extra =
            json!({"agent_sha256": PIN, "deadline_ms": 1000, "input": {}, "extra": 1}).to_string();
        assert_eq!(
            code(&execute("agent.status", extra.as_bytes(), &host(1000))),
            "agent.envelope_invalid"
        );
    }

    #[test]
    fn refuses_a_wrong_identity_and_an_exhausted_deadline_before_running() {
        let envelope = json!({"agent_sha256": PIN, "deadline_ms": 1000, "input": {}}).to_string();
        assert_eq!(
            code(&execute("agent.status", envelope.as_bytes(), &host(0))),
            "agent.identity_mismatch"
        );
        let spent = json!({"agent_sha256": PIN, "deadline_ms": 0, "input": {}}).to_string();
        assert_eq!(
            code(&execute("agent.status", spent.as_bytes(), &host(1000))),
            "agent.deadline_exceeded"
        );
    }

    #[test]
    fn a_mutation_starts_only_through_sudo_and_an_account_mutation_needs_its_account() {
        let envelope = json!({"agent_sha256": PIN, "deadline_ms": 1000, "input": {"candidate": "/x", "sha256": PIN}})
            .to_string();
        assert_eq!(
            code(&execute("agent.install", envelope.as_bytes(), &host(1000))),
            "agent.identity_mismatch"
        );
    }

    #[test]
    fn refuses_invalid_input_with_the_parse_error() {
        let envelope =
            json!({"agent_sha256": PIN, "deadline_ms": 1000, "input": {"unexpected": true}})
                .to_string();
        assert_eq!(
            code(&execute("agent.status", envelope.as_bytes(), &host(1000))),
            "agent.invalid_input"
        );
    }
}

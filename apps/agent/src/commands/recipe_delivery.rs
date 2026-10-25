//! Final recipe verification: observe the installed paths only while holding the update lock.
use crate::command::run::file_sha256;
use crate::command::{lock, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    path::Path,
    time::{Duration, Instant},
};

command_errors! {
    pub enum VerificationError {
        Invalid {} => "recipe.delivery_invalid", "The requested recipe delivery is invalid.";
        Busy {} => "recipe.update_busy", "The update lock remained busy until the verification deadline.";
        LockFailed {} => "recipe.update_lock_failed", "The update lock could not be opened.";
        Mismatch { component: String, expected: String, observed: Option<String> } => "recipe.delivery_mismatch", "The installed delivery differs from the requested delivery.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub user: String,
    pub identity: String,
    pub agent_sha256: String,
    pub cli_version: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Verified {
    pub identity: String,
    pub agent_sha256: String,
    pub cli_version: String,
}

fn digest(value: &str) -> bool {
    value.len() == 64
        && value
            .bytes()
            .all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f'))
}

fn verify(
    input: &Input,
    update_lock: &Path,
    binary: &Path,
    deadline: Instant,
    cli_version: impl FnOnce() -> Option<String>,
) -> Result<Verified, VerificationError> {
    if !input.identity.strip_prefix("sha256:").is_some_and(digest)
        || !digest(&input.agent_sha256)
        || input.user.is_empty()
        || !super::cli::release_name(&format!("orbit-{}", input.cli_version))
    {
        return Err(VerificationError::Invalid {});
    }
    let _held =
        lock::acquire(update_lock, lock::answer_by(deadline)).map_err(|error| match error {
            lock::LockError::Busy(_) => VerificationError::Busy {},
            lock::LockError::Io(_, _) => VerificationError::LockFailed {},
        })?;
    for (component, expected, observed) in [
        ("agent", &input.agent_sha256, file_sha256(binary).ok()),
        ("cli", &input.cli_version, cli_version()),
    ] {
        if observed.as_ref() != Some(expected) {
            return Err(VerificationError::Mismatch {
                component: component.into(),
                expected: expected.clone(),
                observed,
            });
        }
    }
    Ok(Verified {
        identity: input.identity.clone(),
        agent_sha256: input.agent_sha256.clone(),
        cli_version: input.cli_version.clone(),
    })
}

pub struct Verify;
impl Command for Verify {
    const NAME: &'static str = "recipe.verify-delivery";
    const SUMMARY: &'static str =
        "Verify the installed agent and CLI under the update lock before recording a recipe.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(365);
    type Input = Input;
    type Output = Verified;
    type Error = VerificationError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: Input, context: &Context) -> Result<Verified, VerificationError> {
        verify(
            &input,
            Path::new(lock::UPDATE_LOCK_LINUX),
            Path::new(super::agent::LINUX_BINARY),
            context
                .deadline()
                .min(Instant::now() + Duration::from_secs(300)),
            || super::cli::installed_version(Path::new("/usr/local/bin/orbit"), &input.user),
        )
    }
}

#[cfg(test)]
mod tests;

//! Cleanup-only local action. It never exposes SQLite copy, prepare, install, or a caller-selected account.

use super::{sqlite_copy, sqlite_seed};
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{fs, io, os::unix::fs::MetadataExt, path::PathBuf, time::Duration};

command_errors! {
    pub enum CleanupError {
        Refused {} => "database.sqlite_cleanup_refused", "The snapshot path, owner, or cleanup identity is invalid.";
        Failed {} => "database.sqlite_cleanup_failed", "The SQLite snapshot cleanup failed.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "variant", rename_all = "snake_case", deny_unknown_fields)]
pub enum CleanupInput {
    Clone {
        directory: PathBuf,
    },
    Seed {
        target_instance_id: u64,
        base: PathBuf,
        source_path: PathBuf,
        source_user: String,
        transport_user: String,
        operation: String,
        state_dir: PathBuf,
        snapshot_path: PathBuf,
    },
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct CleanupOutput {
    pub backend: &'static str,
}

fn hex(text: &str, length: usize) -> bool {
    text.len() == length
        && text
            .bytes()
            .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
}

fn clone_path(path: &std::path::Path) -> bool {
    path.to_str()
        .and_then(|text| text.strip_prefix("/tmp/orbit-sqlite-clone-"))
        .is_some_and(|suffix| hex(suffix, 32))
}

fn clone_cleanup(directory: PathBuf) -> Result<(), CleanupError> {
    if !clone_path(&directory) {
        return Err(CleanupError::Refused {});
    }
    let metadata = match fs::symlink_metadata(&directory) {
        Ok(metadata) => metadata,
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(()),
        Err(_) => return Err(CleanupError::Failed {}),
    };
    if !metadata.is_dir() || metadata.uid() == 0 || metadata.mode() & 0o7777 != 0o700 {
        return Err(CleanupError::Refused {});
    }
    let owner = metadata.uid();
    let clean = move || {
        sqlite_copy::copy(&sqlite_copy::CopyInput::Cleanup { directory })
            .map(|_| ())
            .map_err(|error| match error {
                sqlite_copy::CopyError::Refused {} => CleanupError::Refused {},
                sqlite_copy::CopyError::Failed {} => CleanupError::Failed {},
            })
    };
    if identity::effective_uid() == owner {
        return clean();
    }
    // Only the owner thread touches the snapshot. A rename race cannot turn this into a root unlink.
    let user = identity::user_name(owner).ok_or(CleanupError::Refused {})?;
    identity::on_account_thread(&user, clean).map_err(|_| CleanupError::Refused {})?
}

fn seed_input(input: CleanupInput) -> Result<sqlite_seed::SourceInput, CleanupError> {
    let CleanupInput::Seed {
        target_instance_id,
        base,
        source_path,
        source_user,
        transport_user,
        operation,
        state_dir,
        snapshot_path,
    } = input
    else {
        return Err(CleanupError::Refused {});
    };
    let prefix = "/var/lib/orbit/app-instance-sqlite-seeds/source-";
    let state = state_dir
        .to_str()
        .and_then(|path| path.strip_prefix(prefix))
        .ok_or(CleanupError::Refused {})?;
    let (source, target) = state
        .split_once("-target-")
        .ok_or(CleanupError::Refused {})?;
    let target_name = target_instance_id.to_string();
    if target_instance_id == 0
        || source.parse::<u64>().ok().filter(|id| *id > 0).is_none()
        || !(target == target_name || target == format!("{target_name}-{operation}"))
        || !hex(&operation, 64)
        || snapshot_path != format!("/tmp/orbit-sqlite-{operation}.sqlite")
        || !source_path.starts_with(&base)
        || source_path == base
    {
        return Err(CleanupError::Refused {});
    }
    Ok(sqlite_seed::SourceInput {
        mode: "cleanup".into(),
        base,
        source_path,
        source_user,
        transport_user,
        operation,
        state_dir,
        snapshot_path,
    })
}

pub fn cleanup(input: CleanupInput) -> Result<CleanupOutput, CleanupError> {
    match input {
        CleanupInput::Clone { directory } => clone_cleanup(directory)?,
        seed => {
            let source = seed_input(seed)?;
            let (source_uid, _, _) =
                identity::account(&source.source_user).map_err(|_| CleanupError::Refused {})?;
            let (transport_uid, _, _) =
                identity::account(&source.transport_user).map_err(|_| CleanupError::Refused {})?;
            if source_uid == 0 || transport_uid == 0 {
                return Err(CleanupError::Refused {});
            }
            match fs::symlink_metadata(&source.base) {
                Ok(metadata) if !metadata.is_dir() || metadata.uid() != source_uid => {
                    return Err(CleanupError::Refused {})
                }
                Ok(_) => {}
                Err(error) if error.kind() == io::ErrorKind::NotFound => {}
                Err(_) => return Err(CleanupError::Failed {}),
            }
            sqlite_seed::prepare(&source).map_err(|error| match error {
                sqlite_seed::SeedError::Refused {} => CleanupError::Refused {},
                _ => CleanupError::Failed {},
            })?;
        }
    }
    Ok(CleanupOutput { backend: "absent" })
}

pub struct CleanupSqlite;

impl Command for CleanupSqlite {
    const NAME: &'static str = "database.cleanup-sqlite";
    const SUMMARY: &'static str =
        "Clean up one admitted SQLite snapshot without copy or seed installation.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = CleanupInput;
    type Output = CleanupOutput;
    type Error = CleanupError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: CleanupInput, _: &Context) -> Result<CleanupOutput, CleanupError> {
        cleanup(input)
    }
}

#[cfg(test)]
#[path = "sqlite_cleanup_tests.rs"]
mod tests;

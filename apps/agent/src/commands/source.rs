//! Checks and steps that the instance commands share. A failed check ends the command with the error that
//! its caller names, through [`require`]. Paths are compared as the shell compared them: `stat`, `test`, and
//! `realpath -e` look at the path itself, and command output loses its trailing newlines.

pub use super::git_read::GitRead;

use std::{
    ffi::OsStr,
    fs,
    os::unix::{ffi::OsStrExt, fs::MetadataExt},
    path::{Path, PathBuf},
    process::{Command, Output, Stdio},
};

/// Ends a command when `condition` is false, and logs the check that failed.
pub fn require<E>(condition: bool, error: impl FnOnce() -> E, check: &str) -> Result<(), E> {
    if condition {
        Ok(())
    } else {
        eprintln!("refused: {check}");
        Err(error())
    }
}

/// Runs Git without hooks, fsmonitor, or optional locks, with standard input closed.
pub fn git(arguments: &[&OsStr], environment: &GitRead, quiet: bool) -> Output {
    let mut command = Command::new("git");
    environment.configure(&mut command);
    command
        .args(arguments)
        .stdin(Stdio::null())
        .stderr(if quiet {
            Stdio::null()
        } else {
            Stdio::inherit()
        });
    command.output().unwrap_or_else(|error| {
        eprintln!("git: {error}");
        Output {
            status: failed_status(),
            stdout: Vec::new(),
            stderr: Vec::new(),
        }
    })
}

fn failed_status() -> std::process::ExitStatus {
    use std::os::unix::process::ExitStatusExt;
    std::process::ExitStatus::from_raw(1 << 8)
}

pub fn bytes(path: &Path) -> &[u8] {
    path.as_os_str().as_bytes()
}

/// `stat -c '%U:%G'` of the path itself, or `""` when it cannot be read.
pub fn owner(path: &Path) -> String {
    let Ok(metadata) = fs::symlink_metadata(path) else {
        return String::new();
    };
    format!(
        "{}:{}",
        user_name(metadata.uid()),
        group_name(metadata.gid())
    )
}

pub fn user_name(uid: u32) -> String {
    crate::command::identity::user_name(uid).unwrap_or_else(|| "UNKNOWN".into())
}

pub fn group_name(gid: u32) -> String {
    crate::command::identity::group_name(gid).unwrap_or_else(|| "UNKNOWN".into())
}

pub fn exists(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok()
}

pub fn is_symlink(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| metadata.file_type().is_symlink())
}

/// The path with every link resolved, as `realpath -e` prints it, or `None` when it does not exist.
pub fn realpath(path: &Path) -> Option<PathBuf> {
    fs::canonicalize(path).ok()
}

/// Whether `realpath -e` of the path is the path itself.
pub fn canonical(path: &Path) -> bool {
    fs::canonicalize(path).is_ok_and(|resolved| resolved == path)
}

/// `test -f path && test ! -L path`.
pub fn is_regular_file(path: &Path) -> bool {
    path.is_file() && !is_symlink(path)
}

/// `test -d path && test ! -L path`.
pub fn is_real_directory(path: &Path) -> bool {
    path.is_dir() && !is_symlink(path)
}

pub fn starts_inside(path: &Path, root: &Path) -> bool {
    let mut prefix = bytes(root).to_vec();
    prefix.push(b'/');
    bytes(path).starts_with(&prefix)
}

/// Creates a directory that only its owner can read, such as one that holds a private repository's fetched objects.
pub fn private_directory(path: &Path) -> std::io::Result<()> {
    std::os::unix::fs::DirBuilderExt::mode(&mut fs::DirBuilder::new(), 0o700).create(path)
}

/// An absolute path without a parent-directory component.
pub fn absolute(path: &Path) -> bool {
    path.is_absolute()
        && !path
            .components()
            .any(|part| matches!(part, std::path::Component::ParentDir))
}

/// A DNS name of at most 253 characters whose labels are alphanumeric with inner hyphens.
pub fn valid_domain(domain: &str) -> bool {
    !domain.is_empty()
        && domain.len() <= 253
        && domain.split('.').all(|label| {
            !label.is_empty()
                && label.len() <= 63
                && label.as_bytes()[0].is_ascii_alphanumeric()
                && label.as_bytes()[label.len() - 1].is_ascii_alphanumeric()
                && label
                    .bytes()
                    .all(|b| b.is_ascii_alphanumeric() || b == b'-')
        })
}

#[cfg(test)]
mod tests {
    #[test]
    fn a_private_directory_is_readable_only_by_its_owner() {
        use std::os::unix::fs::PermissionsExt;
        let path =
            std::env::temp_dir().join(format!("orbit-private-{:016x}", rand::random::<u64>()));
        private_directory(&path).unwrap();
        let mode = fs::metadata(&path).unwrap().permissions().mode() & 0o777;
        fs::remove_dir(&path).unwrap();
        assert_eq!(mode, 0o700);
    }

    use super::*;
}

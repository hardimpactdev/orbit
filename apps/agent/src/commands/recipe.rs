//! Shared recipe operations for Node convergence and image construction.
pub mod apt;
pub mod artifacts;
pub mod assessment;
pub mod browser;
pub(crate) mod interruption;
pub mod javascript;
mod native;
pub mod operations;
pub mod ownership;
pub mod services;
pub mod sources;
pub mod store;
pub mod swamp;
#[cfg(test)]
mod test_process;
pub mod types;

use super::caddy::chown;
use crate::command::Context;
use serde::Serialize;
use std::{
    fs,
    io::{self, Write},
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    time::{Duration, Instant},
};

/// Internal errors retain the recipe stage and bounded diagnostics at the command boundary.
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct Failure {
    pub stage: String,
    pub reason: String,
    pub diagnostic: Option<Box<CommandFailure>>,
    pub packages: Vec<String>,
}
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct CommandFailure {
    pub command: Vec<String>,
    pub exit_code: i32,
    pub stdout: String,
    pub stderr: String,
}
impl Failure {
    pub fn at(mut self, stage: &str) -> Self {
        if self.stage.is_empty() {
            self.stage = stage.into();
        }
        self
    }
    pub fn command(invocation: &Invocation, output: &Output) -> Self {
        Self {
            stage: String::new(),
            reason: if output.truncated {
                "Command output exceeded its bound".into()
            } else {
                String::new()
            },
            diagnostic: Some(Box::new(CommandFailure {
                command: invocation.argv.iter().map(|arg| diagnostic(arg)).collect(),
                exit_code: output.code,
                stdout: diagnostic(&output.stdout),
                stderr: diagnostic(&output.stderr),
            })),
            packages: vec![],
        }
    }
    pub fn new(stage: &str, reason: impl ToString) -> Self {
        Self {
            stage: stage.into(),
            reason: diagnostic(&reason.to_string()),
            diagnostic: None,
            packages: vec![],
        }
    }
    pub fn io(stage: &str, path: &Path, error: impl ToString) -> Self {
        Self::new(stage, format!("{}: {}", path.display(), error.to_string()))
    }
}
pub fn diagnostic(text: &str) -> String {
    let clean: String = crate::logs::redact::redact_text(text)
        .chars()
        .filter(|c| !c.is_control() || matches!(c, '\n' | '\t' | '\r'))
        .collect();
    let mut start = clean.len().saturating_sub(8000);
    while !clean.is_char_boundary(start) {
        start += 1;
    }
    clean[start..].into()
}

#[derive(Debug, Clone, Default)]
pub struct Output {
    pub code: i32,
    pub signal: Option<i32>,
    pub stdout: String,
    pub stderr: String,
    pub truncated: bool,
}
#[derive(Debug, Clone)]
pub struct Invocation {
    pub argv: Vec<String>,
    pub environment: Vec<(String, String)>,
    pub timeout: Duration,
}
impl Invocation {
    pub fn new(argv: &[&str]) -> Self {
        Self {
            argv: argv.iter().map(|s| (*s).into()).collect(),
            environment: vec![],
            timeout: Duration::from_secs(600),
        }
    }
    pub fn env(mut self, key: &str, value: &str) -> Self {
        self.environment.push((key.into(), value.into()));
        self
    }
}
pub trait Host {
    fn execute(&mut self, invocation: &Invocation) -> Result<Output, Failure>;
    fn wait(&mut self, delay: Duration) -> Result<(), Failure>;
}
pub struct Native<'a>(pub &'a Context);
impl Host for Native<'_> {
    fn execute(&mut self, invocation: &Invocation) -> Result<Output, Failure> {
        native::execute(self.0, invocation)
    }
    fn wait(&mut self, delay: Duration) -> Result<(), Failure> {
        if self.0.remaining() <= delay {
            return Err(Failure::new(
                "deadline",
                "Recipe deadline expired before the next fetch",
            ));
        }
        let until = Instant::now() + delay;
        while Instant::now() < until {
            interruption::check()?;
            std::thread::sleep(
                until
                    .saturating_duration_since(Instant::now())
                    .min(Duration::from_millis(100)),
            );
        }
        interruption::check()?;
        Ok(())
    }
}
pub fn run(
    host: &mut impl Host,
    invocation: &Invocation,
    required: bool,
) -> Result<Output, Failure> {
    interruption::check()?;
    let output = host.execute(invocation)?;
    interruption::command_exited(output.signal)?;
    if output.truncated || (required && output.code != 0) {
        return Err(Failure::command(invocation, &output));
    }
    Ok(output)
}

/// Fixed host paths are rooted only by Rust callers; command input never supplies this root.
pub struct Files {
    pub root: PathBuf,
    pub uid: u32,
    pub gid: u32,
}
impl Files {
    pub fn system() -> Self {
        Self {
            root: PathBuf::from("/"),
            uid: 0,
            gid: 0,
        }
    }
    pub fn path(&self, absolute: &str) -> PathBuf {
        self.root.join(absolute.trim_start_matches('/'))
    }
    /// Where a host path, such as an account's home, lives under the root.
    pub fn at(&self, path: &Path) -> PathBuf {
        self.root.join(path.strip_prefix("/").unwrap_or(path))
    }
    pub fn apt(&self) -> PathBuf {
        self.path("/etc/apt")
    }
    pub fn directory(&self, path: &Path, uid: u32, gid: u32) -> Result<(), Failure> {
        match fs::symlink_metadata(path) {
            Ok(meta) if meta.is_dir() && meta.uid() == uid => Ok(()),
            Ok(_) => Err(Failure::io("", path, "Not an owned directory")),
            Err(e) if e.kind() == io::ErrorKind::NotFound => fs::DirBuilder::new()
                .mode(0o755)
                .create(path)
                .and_then(|()| chown(path, uid, gid))
                .map_err(|e| Failure::io("", path, e)),
            Err(e) => Err(Failure::io("", path, e)),
        }
    }
    pub fn publish(
        &self,
        path: &Path,
        content: &[u8],
        mode: u32,
        uid: u32,
        gid: u32,
        replace_link: bool,
    ) -> Result<(), Failure> {
        self.publish_with(path, mode, uid, gid, replace_link, |file| {
            file.write_all(content)
        })
    }
    pub fn publish_from(
        &self,
        path: &Path,
        source: &Path,
        mode: u32,
        uid: u32,
        gid: u32,
        replace_link: bool,
    ) -> Result<(), Failure> {
        self.publish_with(path, mode, uid, gid, replace_link, |file| {
            io::copy(&mut fs::File::open(source)?, file).map(|_| ())
        })
    }
    fn publish_with(
        &self,
        path: &Path,
        mode: u32,
        uid: u32,
        gid: u32,
        replace_link: bool,
        write: impl FnOnce(&mut fs::File) -> io::Result<()>,
    ) -> Result<(), Failure> {
        match fs::symlink_metadata(path) {
            Ok(meta) => {
                if meta.is_symlink() && !replace_link {
                    return Err(Failure::io("", path, "symlink replacement is not allowed"));
                }
                if !meta.is_file() && !meta.is_symlink() {
                    return Err(Failure::io("", path, "target is not a regular file"));
                }
                if meta.uid() != uid {
                    return Err(Failure::io(
                        "",
                        path,
                        format!("target is not owned by UID [{uid}]"),
                    ));
                }
            }
            Err(e) if e.kind() == io::ErrorKind::NotFound => {}
            Err(e) => return Err(Failure::io("", path, e)),
        }
        let temporary =
            path.with_file_name(format!(".orbit-recipe-{:016x}", rand::random::<u64>()));
        let result = (|| -> io::Result<()> {
            let mut file = fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .open(&temporary)?;
            write(&mut file)?;
            file.set_permissions(fs::Permissions::from_mode(mode))?;
            chown(&temporary, uid, gid)?;
            file.sync_all()?;
            fs::rename(&temporary, path)
        })();
        let _ = fs::remove_file(&temporary);
        result.map_err(|e| Failure::io("", path, e))
    }
    pub fn publish_root(&self, path: &Path, content: &[u8]) -> Result<(), Failure> {
        self.publish(path, content, 0o644, self.uid, self.gid, false)
    }
}

/// The entry at `path` without following a final link, or none when nothing is there.
pub fn metadata(path: &Path) -> io::Result<Option<fs::Metadata>> {
    match fs::symlink_metadata(path) {
        Ok(meta) => Ok(Some(meta)),
        Err(error) if error.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(error) => Err(error),
    }
}

/// Swaps two existing paths in one rename, so a reader sees either the old or the new entry at each.
pub fn exchange(first: &Path, second: &Path) -> io::Result<()> {
    rustix::fs::renameat_with(
        rustix::fs::CWD,
        first,
        rustix::fs::CWD,
        second,
        rustix::fs::RenameFlags::EXCHANGE,
    )
    .map_err(Into::into)
}

pub struct WorkDir(pub PathBuf);
impl WorkDir {
    pub fn new() -> Result<Self, Failure> {
        Self::within(&std::env::temp_dir())
    }
    pub fn within(parent: &Path) -> Result<Self, Failure> {
        let path = parent.join(format!("orbit-recipe-{:016x}", rand::random::<u64>()));
        fs::DirBuilder::new()
            .mode(0o700)
            .create(&path)
            .map_err(|e| Failure::io("", &path, e))?;
        Ok(Self(path))
    }
}
impl Drop for WorkDir {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

#[cfg(test)]
mod file_tests {
    use super::*;

    #[test]
    fn streamed_publication_preserves_custody_and_keeps_old_bytes_on_copy_failure() {
        let work = WorkDir::new().unwrap();
        let files = Files {
            root: work.0.clone(),
            uid: rustix::process::geteuid().as_raw(),
            gid: rustix::process::getegid().as_raw(),
        };
        let source = work.0.join("source");
        let target = work.0.join("target");
        let link = work.0.join("link");
        fs::write(&source, "verified").unwrap();
        fs::write(&target, "old").unwrap();
        std::os::unix::fs::symlink(&target, &link).unwrap();
        assert!(files
            .publish_from(&link, &source, 0o755, files.uid, files.gid, false)
            .is_err());
        assert!(files
            .publish_from(&target, &source, 0o755, files.uid + 1, files.gid, false)
            .is_err());
        assert!(files
            .publish_from(&target, &work.0, 0o755, files.uid, files.gid, false)
            .is_err());
        assert_eq!(fs::read_to_string(&target).unwrap(), "old");
        files
            .publish_from(&target, &source, 0o755, files.uid, files.gid, false)
            .unwrap();
        let meta = fs::symlink_metadata(&target).unwrap();
        assert!(meta.is_file());
        assert_eq!(
            (meta.uid(), meta.gid(), meta.mode() & 0o777),
            (files.uid, files.gid, 0o755)
        );
        assert_eq!(fs::read_to_string(&target).unwrap(), "verified");
        assert_eq!(fs::read_to_string(&source).unwrap(), "verified");
        assert!(link.is_symlink());
        assert!(!fs::read_dir(&work.0).unwrap().any(|entry| entry
            .unwrap()
            .file_name()
            .to_string_lossy()
            .starts_with(".orbit-recipe-")));
    }

    #[test]
    fn metadata_reports_a_missing_path_as_none_and_never_follows_a_final_link() {
        let work = WorkDir::new().unwrap();
        assert!(metadata(&work.0.join("missing")).unwrap().is_none());
        let target = work.0.join("target");
        fs::write(&target, "kept").unwrap();
        let link = work.0.join("link");
        std::os::unix::fs::symlink(&target, &link).unwrap();
        assert!(metadata(&link).unwrap().unwrap().is_symlink());
        let dangling = work.0.join("dangling");
        std::os::unix::fs::symlink(work.0.join("gone"), &dangling).unwrap();
        assert!(metadata(&dangling).unwrap().unwrap().is_symlink());
        assert_eq!(
            metadata(&target.join("child")).unwrap_err().kind(),
            io::ErrorKind::NotADirectory
        );
    }

    #[test]
    fn exchange_swaps_two_entries_in_place_and_refuses_a_missing_one() {
        let work = WorkDir::new().unwrap();
        let (first, second) = (work.0.join("first"), work.0.join("second"));
        fs::create_dir(&first).unwrap();
        fs::write(first.join("name"), "first").unwrap();
        fs::write(&second, "second").unwrap();
        exchange(&first, &second).unwrap();
        assert_eq!(fs::read_to_string(&first).unwrap(), "second");
        assert_eq!(fs::read_to_string(second.join("name")).unwrap(), "first");
        assert_eq!(
            exchange(&first, &work.0.join("missing"))
                .unwrap_err()
                .kind(),
            io::ErrorKind::NotFound
        );
        assert_eq!(fs::read_to_string(&first).unwrap(), "second");
    }
}

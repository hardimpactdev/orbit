//! Shared recipe operations for Node convergence and image construction.
pub mod apt;
pub mod artifacts;
pub mod assessment;
pub(crate) mod interruption;
pub mod javascript;
pub mod operations;
pub mod ownership;
pub mod services;
pub mod sources;
pub mod store;
pub mod types;

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
        let deadline = self.0.deadline().min(Instant::now() + invocation.timeout);
        let context = Context::new(self.0.platform, deadline);
        let mut args: Vec<String> = invocation
            .environment
            .iter()
            .map(|(k, v)| format!("{k}={v}"))
            .collect();
        args.extend(invocation.argv.iter().cloned());
        let output = super::incus::bounded(
            &context,
            "/usr/bin/env",
            &args.iter().map(String::as_str).collect::<Vec<_>>(),
            None,
        );
        Ok(Output {
            code: output.code,
            stdout: output.stdout,
            stderr: output.stderr,
            truncated: output.truncated,
        })
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
    interruption::check()?;
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
            file.write_all(content)?;
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
fn chown(path: &Path, uid: u32, gid: u32) -> io::Result<()> {
    rustix::fs::chown(
        path,
        Some(rustix::fs::Uid::from_raw(uid)),
        Some(rustix::fs::Gid::from_raw(gid)),
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

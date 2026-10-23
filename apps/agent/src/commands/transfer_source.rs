//! `instances.transfer-source`: the Node halves of a development Instance transfer, as the managed account. `capture`
//! archives the checkout (a worktree with a Git bundle of `HEAD`) and leaves the selected SQLite file out;
//! `materialize` unpacks an archive into a new checkout and points it at the captured commit; `discard` removes a
//! destination; `cleanup` removes the source after cutover.

use super::source::git_in;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    ffi::OsString,
    fs, io,
    os::unix::fs::PermissionsExt,
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum TransferError {
        Failed { step: String } => "instances.transfer_source_failed", "A transfer step failed on the Node.";
    }
}

fn failed(step: &str) -> TransferError {
    TransferError::Failed { step: step.into() }
}

#[derive(Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum TransferInput {
    Capture {
        source: PathBuf,
        /// `checkout` or `worktree`.
        layout: String,
        sqlite_source: Option<PathBuf>,
    },
    Materialize {
        archive: PathBuf,
        destination: PathBuf,
        head: String,
        branch: Option<String>,
        detached: bool,
        /// The environment file whose access the account keeps to itself.
        environment: PathBuf,
    },
    Discard {
        destination: PathBuf,
    },
    Cleanup {
        source: PathBuf,
        common: Option<PathBuf>,
    },
}

#[derive(Debug, Serialize, PartialEq)]
#[serde(untagged)]
pub enum TransferOutput {
    Captured {
        head: String,
        branch: Option<String>,
        detached: bool,
        archive: PathBuf,
        common: Option<String>,
        refs: Vec<String>,
    },
    Done {},
}

fn git(directory: &Path, arguments: &[&str], step: &str) -> Result<String, TransferError> {
    let output = git_in(directory, arguments, true);
    if !output.status.success() {
        return Err(failed(step));
    }
    Ok(String::from_utf8_lossy(&output.stdout)
        .trim_end()
        .to_string())
}

fn tar(directory: &Path, arguments: &[OsString], step: &str) -> Result<(), TransferError> {
    let status = Process::new("tar")
        .args(arguments)
        .current_dir(directory)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .status()
        .map_err(|_| failed(step))?;
    status.success().then_some(()).ok_or_else(|| failed(step))
}

fn remove_tree(path: &Path) -> io::Result<()> {
    match fs::symlink_metadata(path) {
        Err(error) if error.kind() == io::ErrorKind::NotFound => Ok(()),
        Err(error) => Err(error),
        Ok(metadata) if metadata.is_dir() => fs::remove_dir_all(path),
        Ok(_) => fs::remove_file(path),
    }
}

fn capture(
    source: &Path,
    layout: &str,
    sqlite_source: Option<&Path>,
) -> Result<TransferOutput, TransferError> {
    let step = "capture";
    let name = source
        .file_name()
        .ok_or_else(|| failed(step))?
        .to_string_lossy();
    let archive = PathBuf::from(format!(
        "/tmp/orbit-transfer-{name}-{}.tar",
        std::process::id()
    ));
    let head = git(source, &["rev-parse", "HEAD"], step)?;
    let branch = git(source, &["rev-parse", "--abbrev-ref", "HEAD"], step).unwrap_or_default();
    let detached = branch == "HEAD";
    let common = source
        .join(".git")
        .is_file()
        .then(|| git(source, &["rev-parse", "--git-common-dir"], step))
        .transpose()?;
    let refs = git(
        source,
        &["for-each-ref", "--format=%(refname:short)", "refs/heads"],
        step,
    )?;
    let mut exclusions: Vec<OsString> = Vec::new();
    if let Some(sqlite) = sqlite_source {
        let relative = sqlite.strip_prefix(source).map_err(|_| failed(step))?;
        if relative.as_os_str().is_empty() {
            return Err(failed(step));
        }
        let relative = format!("./{}", relative.display());
        exclusions.extend(["--no-wildcards".into(), "--anchored".into()]);
        for suffix in ["", "-wal", "-shm"] {
            exclusions.push(format!("--exclude={relative}{suffix}").into());
        }
    }
    let mut arguments: Vec<OsString> = Vec::new();
    if layout == "worktree" {
        let mut bundle = archive.as_os_str().to_owned();
        bundle.push(".bundle");
        let bundle = PathBuf::from(bundle);
        git(
            source,
            &["bundle", "create", &bundle.to_string_lossy(), "HEAD"],
            step,
        )?;
        arguments.push("--exclude=.git".into());
        arguments.extend(exclusions);
        arguments.extend(["-cf".into(), archive.clone().into_os_string(), ".".into()]);
        let appended = tar(source, &arguments, step).and_then(|()| {
            tar(
                source,
                &[
                    "-rf".into(),
                    archive.clone().into_os_string(),
                    "-C".into(),
                    "/tmp".into(),
                    bundle.file_name().unwrap_or_default().to_owned(),
                ],
                step,
            )
        });
        let _ = fs::remove_file(&bundle);
        appended?;
    } else {
        arguments.extend(exclusions);
        arguments.extend(["-cf".into(), archive.clone().into_os_string(), ".".into()]);
        tar(source, &arguments, step)?;
    }
    Ok(TransferOutput::Captured {
        head,
        branch: (!detached && !branch.is_empty()).then_some(branch),
        detached,
        archive,
        common: common.filter(|common| !common.is_empty()),
        refs: refs.split_whitespace().map(String::from).collect(),
    })
}

fn materialize(
    archive: &Path,
    destination: &Path,
    head: &str,
    branch: Option<&str>,
    detached: bool,
    environment: &Path,
) -> Result<TransferOutput, TransferError> {
    let step = "materialize";
    let io_failed = |_: io::Error| failed(step);
    if let Some(parent) = destination.parent() {
        fs::create_dir_all(parent).map_err(io_failed)?;
    }
    fs::create_dir(destination).map_err(io_failed)?;
    tar(
        destination,
        &["-xf".into(), archive.as_os_str().to_owned()],
        step,
    )?;
    fs::remove_file(archive).map_err(io_failed)?;
    if !destination.join(".git").is_dir() {
        git(destination, &["init", "--quiet"], step)?;
        let bundles: Vec<PathBuf> = fs::read_dir(destination)
            .map_err(io_failed)?
            .filter_map(Result::ok)
            .map(|entry| entry.path())
            .filter(|path| {
                path.is_file()
                    && path
                        .extension()
                        .is_some_and(|extension| extension == "bundle")
            })
            .collect();
        if let [bundle] = bundles.as_slice() {
            git(
                destination,
                &["fetch", "--quiet", &bundle.to_string_lossy(), "HEAD"],
                step,
            )?;
            fs::remove_file(bundle).map_err(io_failed)?;
        }
        match branch.filter(|_| !detached) {
            Some(branch) => {
                git(
                    destination,
                    &["symbolic-ref", "HEAD", &format!("refs/heads/{branch}")],
                    step,
                )?;
                git(destination, &["update-ref", "HEAD", head], step)?;
            }
            None => {
                git(
                    destination,
                    &["update-ref", "--no-deref", "HEAD", head],
                    step,
                )?;
            }
        }
        // Populate the index without replacing dirty files or restoring excluded SQLite files.
        git(destination, &["reset", "--mixed", "--quiet", head], step)?;
    }
    // Other local users, the Node agent included, never read an Instance's environment (ADR 0151).
    if !environment.starts_with(destination) || environment == destination {
        return Err(failed(step));
    }
    if let Ok(metadata) = fs::symlink_metadata(environment) {
        if metadata.is_file() {
            if fs::canonicalize(environment).ok().as_deref() != Some(environment) {
                return Err(failed(step));
            }
            let mode = metadata.permissions().mode() & !0o007;
            fs::set_permissions(environment, fs::Permissions::from_mode(mode))
                .map_err(io_failed)?;
        }
    }
    Ok(TransferOutput::Done {})
}

pub fn transfer(input: &TransferInput) -> Result<TransferOutput, TransferError> {
    match input {
        TransferInput::Capture {
            source,
            layout,
            sqlite_source,
        } => capture(source, layout, sqlite_source.as_deref()),
        TransferInput::Materialize {
            archive,
            destination,
            head,
            branch,
            detached,
            environment,
        } => materialize(
            archive,
            destination,
            head,
            branch.as_deref(),
            *detached,
            environment,
        ),
        TransferInput::Discard { destination } => remove_tree(destination)
            .map(|()| TransferOutput::Done {})
            .map_err(|_| failed("discard")),
        TransferInput::Cleanup { source, common } => {
            if common.as_deref() == Some(source.as_path()) {
                return Err(failed("cleanup"));
            }
            remove_tree(source)
                .map(|()| TransferOutput::Done {})
                .map_err(|_| failed("cleanup"))
        }
    }
}

pub struct TransferSource;

impl Command for TransferSource {
    const NAME: &'static str = "instances.transfer-source";
    const SUMMARY: &'static str =
        "Capture, materialize, discard, or clean up a development Instance transfer.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(600);
    type Input = TransferInput;
    type Output = TransferOutput;
    type Error = TransferError;

    /// The managed account owns every development checkout and runs no project code here.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: TransferInput, _: &Context) -> Result<TransferOutput, TransferError> {
        transfer(&input)
    }
}

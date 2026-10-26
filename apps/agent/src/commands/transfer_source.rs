//! `instance.transfer-source`: the Node halves of a development Instance transfer, as the managed account. `capture`
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
    io::{Read, Seek, Write},
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Component, Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum TransferError {
        Archive {} => "instance.transfer_archive_invalid", "The transfer archive or destination is invalid.";
        Failed { step: String } => "instance.transfer_source_failed", "A transfer step failed on the Node.";
    }
}

fn failed(step: &str) -> TransferError {
    TransferError::Failed { step: step.into() }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum TransferInput {
    Capture {
        source: PathBuf,
        /// `checkout` or `worktree`.
        layout: String,
        sqlite_source: Option<PathBuf>,
    },
    Discard {
        destination: PathBuf,
    },
    Cleanup {
        source: PathBuf,
        common: Option<PathBuf>,
    },
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
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
    if !super::doctor_route::absolute(source)
        || !matches!(layout, "checkout" | "worktree")
        || sqlite_source.is_some_and(|path| {
            !super::doctor_route::absolute(path) || !path.starts_with(source) || path == source
        })
    {
        return Err(TransferError::Archive {});
    }
    let name = source
        .file_name()
        .ok_or_else(|| failed(step))?
        .to_string_lossy();
    let archive = PathBuf::from(format!(
        "/tmp/orbit-transfer-{name}-{:016x}.tar",
        rand::random::<u64>()
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
    let file = fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&archive)
        .map_err(|_| failed(step))?;
    drop(file);
    let mut arguments: Vec<OsString> = Vec::new();
    let captured = (|| {
        if layout == "worktree" {
            let bundle_directory = super::recipe::WorkDir::new().map_err(|_| failed(step))?;
            let bundle = bundle_directory.0.join("checkout.bundle");
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
                        bundle_directory.0.clone().into_os_string(),
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
        Ok::<(), TransferError>(())
    })();
    if let Err(error) = captured {
        let _ = fs::remove_file(&archive);
        return Err(error);
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
    mut archive: fs::File,
    destination: &Path,
    head: &str,
    branch: Option<&str>,
    detached: bool,
    environment: &Path,
    context: &Context,
) -> Result<TransferOutput, TransferError> {
    let step = "materialize";
    if !super::doctor_route::absolute(destination)
        || !super::doctor_route::absolute(environment)
        || !environment.starts_with(destination)
        || environment == destination
        || !super::task_git::object_id(head)
        || (!detached && branch.is_none())
        || branch
            .is_some_and(|branch| !git2::Reference::is_valid_name(&format!("refs/heads/{branch}")))
        || fs::symlink_metadata(destination).is_ok()
    {
        return Err(TransferError::Archive {});
    }
    if let Some(existing) = destination.ancestors().find(|path| path.exists()) {
        if fs::canonicalize(existing).ok().as_deref() != Some(existing) {
            return Err(TransferError::Archive {});
        }
    }
    validate_archive(&mut archive, context)?;
    let io_failed = |_: io::Error| failed(step);
    if let Some(parent) = destination.parent() {
        fs::create_dir_all(parent).map_err(io_failed)?;
    }
    fs::create_dir(destination).map_err(io_failed)?;
    let status = Process::new("/usr/bin/tar")
        .args(["--extract", "--file=-"])
        .current_dir(destination)
        .stdin(Stdio::from(archive))
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .map_err(|_| failed(step))?;
    if !status.success() {
        return Err(failed(step));
    }
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
    const NAME: &'static str = "instance.transfer-source";
    const SUMMARY: &'static str = "Capture, discard, or clean up a development Instance transfer.";
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

const MAX_ARCHIVE_BYTES: u64 = 1 << 40;

fn validate_archive(file: &mut fs::File, context: &Context) -> Result<(), TransferError> {
    let invalid = |_: io::Error| TransferError::Archive {};
    let mut archive = tar::Archive::new(&mut *file);
    let mut count = 0;
    let mut links = std::collections::HashSet::new();
    for entry in archive.entries().map_err(invalid)? {
        if context.remaining().is_zero() {
            return Err(failed("archive-validation"));
        }
        let mut entry = entry.map_err(invalid)?;
        let path = entry.path().map_err(invalid)?;
        if path
            .components()
            .any(|part| !matches!(part, Component::Normal(_) | Component::CurDir))
        {
            return Err(TransferError::Archive {});
        }
        let normalized: PathBuf = path
            .components()
            .filter(|part| matches!(part, Component::Normal(_)))
            .collect();
        if normalized
            .ancestors()
            .skip(1)
            .any(|ancestor| links.contains(ancestor))
        {
            return Err(TransferError::Archive {});
        }
        if entry.header().entry_type().is_symlink() {
            links.insert(normalized);
        }
        if entry.header().entry_type().is_hard_link() {
            let link = entry
                .link_name()
                .map_err(invalid)?
                .ok_or(TransferError::Archive {})?;
            if link
                .components()
                .any(|part| !matches!(part, Component::Normal(_) | Component::CurDir))
            {
                return Err(TransferError::Archive {});
            }
        }
        let mut buffer = [0; 65536];
        loop {
            if context.remaining().is_zero() {
                return Err(failed("archive-validation"));
            }
            if entry.read(&mut buffer).map_err(invalid)? == 0 {
                break;
            }
        }
        count += 1;
    }
    if count == 0 {
        return Err(TransferError::Archive {});
    }
    file.rewind().map_err(invalid)?;
    Ok(())
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ArchiveInput {
    archive: PathBuf,
}
#[derive(schemars::JsonSchema, Serialize)]
pub struct ArchiveOutput {
    bytes: u64,
}
fn read_archive(
    input: &ArchiveInput,
    context: &Context,
    output: impl Write,
) -> Result<ArchiveOutput, TransferError> {
    let name = input
        .archive
        .file_name()
        .and_then(|name| name.to_str())
        .unwrap_or("");
    if input.archive.parent() != Some(Path::new("/tmp"))
        || !name.starts_with("orbit-transfer-")
        || !name.ends_with(".tar")
    {
        return Err(TransferError::Archive {});
    }
    let mut file = fs::OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW)
        .open(&input.archive)
        .map_err(|_| TransferError::Archive {})?;
    let meta = file.metadata().map_err(|_| failed("download"))?;
    if !meta.is_file()
        || meta.len() > MAX_ARCHIVE_BYTES
        || meta.uid() != crate::command::identity::effective_uid()
        || meta.mode() & 0o7777 != 0o600
        || meta.nlink() != 1
    {
        return Err(TransferError::Archive {});
    }
    validate_archive(&mut file, context)?;
    let mut output = crate::command::binary_output::Frames::new(output, MAX_ARCHIVE_BYTES);
    let mut buffer = [0; 65536];
    loop {
        if context.remaining().is_zero() {
            return Err(failed("download"));
        }
        let count = file.read(&mut buffer).map_err(|_| failed("download"))?;
        if count == 0 {
            break;
        }
        output
            .write_all(&buffer[..count])
            .map_err(|_| failed("download"))?;
    }
    if output.bytes() != meta.len() {
        return Err(failed("download"));
    }
    Ok(ArchiveOutput {
        bytes: output.bytes(),
    })
}
pub struct ReadArchive;
impl Command for ReadArchive {
    const NAME: &'static str = "instance.read-transfer-archive";
    const SUMMARY: &'static str = "Stream a private captured checkout archive to the Gateway.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(1800);
    const OUTPUT_MAX_BYTES: u64 = MAX_ARCHIVE_BYTES;
    type Input = ArchiveInput;
    type Output = ArchiveOutput;
    type Error = TransferError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: ArchiveInput, context: &Context) -> Result<ArchiveOutput, TransferError> {
        read_archive(
            &input,
            context,
            crate::command::binary_output::DeadlineWriter::new(
                libc::STDOUT_FILENO,
                context.deadline(),
            )
            .map_err(|_| failed("download"))?,
        )
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct MaterializeInput {
    destination: PathBuf,
    head: String,
    branch: Option<String>,
    detached: bool,
    environment: PathBuf,
}
pub struct Materialize;
impl Command for Materialize {
    const NAME: &'static str = "instance.materialize-transfer";
    const SUMMARY: &'static str = "Materialize a received checkout archive as the managed account.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(1800);
    const INPUT_MAX_BYTES: u64 = MAX_ARCHIVE_BYTES;
    type Input = MaterializeInput;
    type Output = TransferOutput;
    type Error = TransferError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn target(input: &MaterializeInput) -> Option<String> {
        Some(format!("transfer:{}", input.destination.display()))
    }
    fn run(input: MaterializeInput, context: &Context) -> Result<TransferOutput, TransferError> {
        materialize(
            context
                .take_binary_input()
                .ok_or(TransferError::Archive {})?,
            &input.destination,
            &input.head,
            input.branch.as_deref(),
            input.detached,
            &input.environment,
            context,
        )
    }
}
#[cfg(test)]
#[path = "transfer_source_tests.rs"]
mod tests;

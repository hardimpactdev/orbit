//! `instance.*-release`: the release layout of a production Instance. A home `/home/<user>` holds `releases/<name>`
//! checkouts, a `current` link to the selected one, and the stable `.env`. Each command checks the root-owned layout
//! marker first. It runs as root, and Git and every file it writes in the home run as the production user, so the
//! release stays the user's alone.

use super::git_read::GitRead;
use super::source::{is_real_directory, is_symlink, realpath};
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    ffi::OsStr,
    fs,
    os::unix::fs::{MetadataExt, OpenOptionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

/// Where each production Instance's root-owned source state lives.
pub const STATE_ROOT: &str = "/var/lib/orbit/app-instance-sources";

command_errors! {
    pub enum ReleaseError {
        Invalid { check: String } => "instance.release_invalid", "The release home or the release does not pass its checks.";
        CommitNotOnBranch {} => "instance.commit_not_on_branch", "The branch does not contain the commit.";
        StepFailed { step: String } => "instance.release_step_failed", "A release step failed.";
        ReleaseMissing {} => "instance.release_missing", "No release is selected yet.";
    }
}

pub(super) fn invalid(check: &str) -> ReleaseError {
    ReleaseError::Invalid {
        check: check.into(),
    }
}

pub(super) fn check(condition: bool, what: &str) -> Result<(), ReleaseError> {
    if condition {
        Ok(())
    } else {
        Err(invalid(what))
    }
}

pub(super) fn failed(step: &str) -> ReleaseError {
    ReleaseError::StepFailed { step: step.into() }
}

pub fn valid_name(name: &str) -> bool {
    let bytes = name.as_bytes();
    !bytes.is_empty()
        && bytes.len() <= 128
        && bytes[0].is_ascii_alphanumeric()
        && bytes
            .iter()
            .all(|byte| byte.is_ascii_alphanumeric() || b"._-".contains(byte))
}

/// Where the checks find the homes and the root-owned state, and which uid owns that state. Tests point all three
/// at a sandbox.
pub struct Layout {
    pub home_root: PathBuf,
    pub state_root: PathBuf,
    pub root_uid: u32,
}

impl Layout {
    pub fn system() -> Self {
        Self {
            home_root: PathBuf::from("/home"),
            state_root: PathBuf::from(STATE_ROOT),
            root_uid: 0,
        }
    }
}

/// The production user's uid and gid.
pub(super) fn account(user: &str) -> Result<(u32, u32), ReleaseError> {
    identity::account(user)
        .map(|(uid, gid, _)| (uid, gid))
        .map_err(|_| invalid("the production user exists"))
}

/// Runs a program as the production user, with standard input closed. Its standard output, or `None` when it fails.
pub(super) fn as_user(
    user: &str,
    program: &str,
    arguments: &[&OsStr],
    environment: &GitRead,
) -> Option<Vec<u8>> {
    let mut command = Process::new(program);
    if program == "git" {
        environment.configure(&mut command);
    } else if environment.authenticated() {
        return None;
    }
    command
        .args(arguments)
        .stdin(Stdio::null())
        .stderr(Stdio::null());
    identity::drop_to(&mut command, user).ok()?;
    let output = command.output().ok()?;
    output.status.success().then_some(output.stdout)
}

/// Runs a program as the production user with `input` on its standard input. Whether it succeeded.
pub(super) fn as_user_with_input(
    user: &str,
    program: &str,
    arguments: &[&OsStr],
    input: &[u8],
) -> bool {
    use std::io::Write;
    let mut command = Process::new(program);
    command
        .args(arguments)
        .stdin(Stdio::piped())
        .stdout(Stdio::null())
        .stderr(Stdio::null());
    if identity::drop_to(&mut command, user).is_err() {
        return false;
    }
    let Ok(mut child) = command.spawn() else {
        return false;
    };
    let written = child
        .stdin
        .take()
        .is_some_and(|mut stdin| stdin.write_all(input).is_ok());
    child.wait().is_ok_and(|status| status.success()) && written
}

/// Git as the production user, without hooks or fsmonitor.
pub(super) fn git(
    user: &str,
    release: &Path,
    arguments: &[&str],
    environment: &GitRead,
) -> Option<String> {
    let mut all: Vec<&OsStr> = ["-C"].map(OsStr::new).to_vec();
    all.push(release.as_os_str());
    all.extend(arguments.iter().map(OsStr::new));
    as_user(user, "git", &all, environment).map(|output| {
        String::from_utf8_lossy(&output)
            .trim_end_matches('\n')
            .to_string()
    })
}

pub(super) fn none() -> GitRead {
    GitRead::default()
}

/// A path relative to a release: no leading slash, no `..`, not empty.
pub(super) fn relative_path(path: &str) -> bool {
    !path.is_empty() && !path.starts_with('/') && path.split('/').all(|segment| segment != "..")
}

// ---- repository binding -----------------------------------------------------------------------------------------

/// Reads which repository URL each root-owned marker and each release records, for a Project URL change.
pub struct InspectRepositoryBinding;

#[derive(schemars::JsonSchema, Deserialize, Clone)]
#[serde(deny_unknown_fields)]
pub struct BindingHome {
    pub user: String,
    pub home: PathBuf,
    pub instance: u64,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct BindingOutput {
    /// The content of each marker that exists, by kind, base64-encoded because it holds NUL separators.
    pub markers: BTreeMap<String, String>,
    /// The origin of each release, or `None` when the release has no readable origin.
    pub releases: BTreeMap<String, Option<String>>,
}

const MARKERS: [&str; 2] = ["release-layout", "initial-clone"];

fn state_directory(binding: &BindingHome, layout: &Layout) -> Result<PathBuf, ReleaseError> {
    check(
        binding.home == layout.home_root.join(&binding.user),
        "the home is the user's",
    )?;
    Ok(layout.state_root.join(binding.instance.to_string()))
}

pub(super) fn root_owned(path: &Path, layout: &Layout, mode: u32, directory: bool) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| {
        (if directory {
            metadata.is_dir()
        } else {
            metadata.is_file()
        }) && metadata.uid() == layout.root_uid
            && metadata.gid() == layout.root_uid
            && metadata.mode() & 0o7777 == mode
    })
}

pub fn inspect_binding(
    binding: &BindingHome,
    layout: &Layout,
) -> Result<BindingOutput, ReleaseError> {
    use base64::Engine;
    let state = state_directory(binding, layout)?;
    let mut markers = BTreeMap::new();
    if fs::symlink_metadata(&state).is_ok() {
        check(
            root_owned(&state, layout, 0o700, true),
            "the source state directory is root's",
        )?;
        for kind in MARKERS {
            let marker = state.join(kind);
            if fs::symlink_metadata(&marker).is_ok() {
                check(
                    root_owned(&marker, layout, 0o600, false),
                    "each marker is root's",
                )?;
                let content = fs::read(&marker).map_err(|_| invalid("each marker can be read"))?;
                markers.insert(
                    kind.to_string(),
                    base64::engine::general_purpose::STANDARD.encode(content),
                );
            }
        }
    }
    let releases_directory = binding.home.join("releases");
    let mut releases = BTreeMap::new();
    if is_real_directory(&releases_directory) {
        let mut names: Vec<String> = fs::read_dir(&releases_directory)
            .map_err(|_| invalid("the releases directory can be read"))?
            .flatten()
            .filter(|entry| entry.file_type().is_ok_and(|kind| kind.is_dir()))
            .filter_map(|entry| entry.file_name().into_string().ok())
            .filter(|name| valid_name(name))
            .collect();
        names.sort();
        for name in names {
            let release = releases_directory.join(&name);
            if is_symlink(&release) || !is_real_directory(&release.join(".git")) {
                continue;
            }
            let origin = git(
                &binding.user,
                &release,
                &["config", "--null", "--get", "remote.origin.url"],
                &none(),
            )
            .and_then(|value| value.strip_suffix('\0').map(str::to_string))
            .filter(|value| !value.is_empty() && !value.contains('\0'));
            releases.insert(name, origin);
        }
    }
    Ok(BindingOutput { markers, releases })
}

impl Command for InspectRepositoryBinding {
    const NAME: &'static str = "instance.inspect-repository-binding";
    const SUMMARY: &'static str =
        "Read the repository URL that each production marker and release records.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = BindingHome;
    type Output = BindingOutput;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: BindingHome, _: &Context) -> Result<BindingOutput, ReleaseError> {
        inspect_binding(&input, &Layout::system())
    }
}

/// Moves each recorded URL from the expected one to the target one. A value already at the target is left alone, and
/// any other value is refused, so a repeated rebind converges and a concurrent change is never overwritten.
pub struct RebindRepository;

#[derive(schemars::JsonSchema, Deserialize, Clone)]
#[serde(deny_unknown_fields)]
pub struct BindingChange {
    /// `release`, `initial-clone`, or `release-layout`.
    pub kind: String,
    /// The release name for a `release` change.
    #[serde(default)]
    pub name: Option<String>,
    pub expected: String,
    pub target: String,
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RebindInput {
    #[serde(flatten)]
    pub binding: BindingHome,
    /// Applied in order; the Gateway puts the release layout marker last, because deployment checks it first.
    pub changes: Vec<BindingChange>,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct RebindOutput {}

fn marker_content(kind: &str, repository: &str, binding: &BindingHome) -> Vec<u8> {
    let home = binding.home.display();
    match kind {
        "release-layout" => format!("{repository}\0{}\0{home}\0initial\0", binding.user),
        _ => format!("{repository}\0{}\0{home}\0", binding.user),
    }
    .into_bytes()
}

fn rebind_marker(
    binding: &BindingHome,
    layout: &Layout,
    change: &BindingChange,
) -> Result<(), ReleaseError> {
    let state = state_directory(binding, layout)?;
    let marker = state.join(&change.kind);
    check(
        root_owned(&state, layout, 0o700, true),
        "the source state directory is root's",
    )?;
    check(
        root_owned(&marker, layout, 0o600, false),
        "the marker is root's",
    )?;
    let actual = fs::read(&marker).map_err(|_| invalid("the marker can be read"))?;
    let wanted = marker_content(&change.kind, &change.target, binding);
    if actual == wanted {
        return Ok(());
    }
    check(
        actual == marker_content(&change.kind, &change.expected, binding),
        "the marker records the expected URL",
    )?;
    let temporary = state.join(format!(".{}.{:016x}", change.kind, rand::random::<u64>()));
    let written = (|| -> std::io::Result<()> {
        use std::io::Write;
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&temporary)?;
        file.write_all(&wanted)?;
        file.sync_all()?;
        rustix::fs::chown(
            &temporary,
            Some(rustix::fs::Uid::from_raw(layout.root_uid)),
            Some(rustix::fs::Gid::from_raw(layout.root_uid)),
        )?;
        fs::rename(&temporary, &marker)
    })();
    if written.is_err() {
        let _ = fs::remove_file(&temporary);
        return Err(failed("write the marker"));
    }
    check(
        fs::read(&marker).is_ok_and(|content| content == wanted),
        "the marker records the target URL",
    )
}

fn rebind_release(binding: &BindingHome, change: &BindingChange) -> Result<(), ReleaseError> {
    let name = change
        .name
        .as_deref()
        .filter(|name| valid_name(name))
        .ok_or_else(|| invalid("the release name is valid"))?;
    let release = binding.home.join("releases").join(name);
    check(
        !is_symlink(&release) && is_real_directory(&release.join(".git")),
        "the release has its Git directory",
    )?;
    check(
        realpath(&release).as_deref() == Some(release.as_path()),
        "the release is canonical",
    )?;
    let origin = || {
        git(
            &binding.user,
            &release,
            &["config", "--null", "--get", "remote.origin.url"],
            &none(),
        )
    };
    let actual = origin();
    if actual.as_deref() == Some(format!("{}\0", change.target).as_str()) {
        return Ok(());
    }
    check(
        actual.as_deref() == Some(format!("{}\0", change.expected).as_str()),
        "the release records the expected URL",
    )?;
    git(
        &binding.user,
        &release,
        &["remote", "set-url", "origin", "--", &change.target],
        &none(),
    )
    .ok_or_else(|| failed("set the origin"))?;
    check(
        origin().as_deref() == Some(format!("{}\0", change.target).as_str()),
        "the release records the target URL",
    )
}

pub fn rebind(input: &RebindInput, layout: &Layout) -> Result<(), ReleaseError> {
    state_directory(&input.binding, layout)?;
    for change in &input.changes {
        match change.kind.as_str() {
            "release" => rebind_release(&input.binding, change)?,
            "release-layout" | "initial-clone" => rebind_marker(&input.binding, layout, change)?,
            _ => return Err(invalid("each change names a marker or a release")),
        }
    }
    Ok(())
}

impl Command for RebindRepository {
    const NAME: &'static str = "instance.rebind-repository";
    const SUMMARY: &'static str = "Move the repository URL that production markers and releases record to the Project's new URL.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = RebindInput;
    type Output = RebindOutput;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &RebindInput) -> Option<String> {
        Some(format!("release-home:{}", input.binding.instance))
    }

    fn run(input: RebindInput, _: &Context) -> Result<RebindOutput, ReleaseError> {
        rebind(&input, &Layout::system())?;
        Ok(RebindOutput {})
    }
}

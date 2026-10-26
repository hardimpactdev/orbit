//! `instance.*-registration-*`: adopt an existing checkout as a development Instance. Inspection reads its Git
//! identity and a digest of its content; relocation moves it, or its whole worktree set, into the Project's apps
//! directory and resumes from any interruption; the Laravel receipt keeps the URL files that registration rewrites.
//! These replace the Python that `RemoteRegistrationSourceManager` sent, step for step.

use super::source::{canonical, group_name, is_symlink, realpath, user_name};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    fs,
    io::Read,
    os::unix::fs::{MetadataExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum RegistrationError {
        Invalid { check: String } => "instance.registration_invalid", "The source does not match its registration evidence.";
        GitFailed { step: String } => "instance.git_failed", "A Git step failed.";
    }
}

fn invalid(check: &str) -> RegistrationError {
    RegistrationError::Invalid {
        check: check.into(),
    }
}

fn check(condition: bool, what: &str) -> Result<(), RegistrationError> {
    if condition {
        Ok(())
    } else {
        Err(invalid(what))
    }
}

/// Git in `path`, without hooks, fsmonitor, or optional locks: whether it succeeded, and its full standard output.
fn git_raw(path: &Path, arguments: &[&str]) -> (bool, Vec<u8>) {
    let output = super::source::git_in(path, arguments, true);
    (output.status.success(), output.stdout)
}

/// Git's trimmed output, or a failure that names the step.
fn git(path: &Path, arguments: &[&str]) -> Result<String, RegistrationError> {
    match git_raw(path, arguments) {
        (true, output) => Ok(String::from_utf8_lossy(&output).trim().to_string()),
        (false, _) => Err(RegistrationError::GitFailed {
            step: arguments.join(" "),
        }),
    }
}

/// A digest of everything a registration must keep: `HEAD`, its branch, the content status, the local Git
/// configuration, the index, and every path under the checkout except `.git`, with its mode and content.
pub fn source_digest(root: &Path) -> Result<String, RegistrationError> {
    let mut hash = Sha256::new();
    let commands: [&[&str]; 5] = [
        &["rev-parse", "HEAD"],
        &["symbolic-ref", "-q", "HEAD"],
        &["status", "--porcelain=v2", "--untracked-files=all"],
        &["config", "--local", "--null", "--list"],
        &["ls-files", "--stage", "-v"],
    ];
    for arguments in commands {
        let (ok, value) = git_raw(root, arguments);
        // The status must succeed; the others hash what Git printed either way.
        if arguments[0] == "status" && !ok {
            return Err(RegistrationError::GitFailed {
                step: "status".into(),
            });
        }
        hash.update(b"git\0");
        hash.update(arguments.join("\0").as_bytes());
        hash.update(b"\0");
        hash.update(&value);
    }
    hash_tree(root, root, &mut hash).map_err(|_| RegistrationError::GitFailed {
        step: "read the content".into(),
    })?;
    Ok(format!("{:x}", hash.finalize()))
}

/// Each directory's entries in name order, before its subdirectories in name order. A link is never followed. A
/// directory that cannot be listed is skipped; a file that cannot be read fails the digest.
fn hash_tree(root: &Path, directory: &Path, hash: &mut Sha256) -> std::io::Result<()> {
    let Ok(entries) = fs::read_dir(directory) else {
        return Ok(());
    };
    let mut names: Vec<_> = entries
        .filter_map(|entry| entry.ok().map(|entry| entry.file_name()))
        .collect();
    names.sort();
    let mut subdirectories = Vec::new();
    for name in names {
        let entry = directory.join(&name);
        if directory == root && name == ".git" {
            continue;
        }
        let Ok(metadata) = fs::symlink_metadata(&entry) else {
            continue;
        };
        let relative = entry.strip_prefix(root).unwrap_or(&entry);
        hash.update(relative.as_os_str().as_encoded_bytes());
        hash.update(format!("\0{}\0", metadata.mode() & 0o7777).as_bytes());
        if metadata.file_type().is_symlink() {
            hash.update(b"L");
            if let Ok(target) = fs::read_link(&entry) {
                hash.update(target.as_os_str().as_encoded_bytes());
            }
        } else if metadata.is_file() {
            hash.update(b"F");
            let mut file = fs::File::open(&entry)?;
            let mut block = vec![0; 1 << 20];
            loop {
                let read = file.read(&mut block)?;
                if read == 0 {
                    break;
                }
                hash.update(&block[..read]);
            }
        } else {
            hash.update(b"D");
            if metadata.is_dir() {
                subdirectories.push(entry);
            }
        }
    }
    for subdirectory in subdirectories {
        hash_tree(root, &subdirectory, hash)?;
    }
    Ok(())
}

// ---- inspect ----------------------------------------------------------------------------------------------------

/// Reads a checkout, or with `include_worktrees` its whole worktree set, for registration.
pub struct InspectRegistrationSource;

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectInput {
    pub path: PathBuf,
    pub include_worktrees: bool,
    pub user: String,
    pub group: String,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct SourceRow {
    pub path: String,
    pub layout: &'static str,
    pub repository_url: String,
    pub branch: Option<String>,
    pub detached: bool,
    pub commit: String,
    pub default_branch: Option<String>,
    pub inferred_slug: String,
    pub inferred_root: Option<&'static str>,
    pub common_repository_path: String,
    pub worktree_paths: Vec<String>,
    pub source_digest: String,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct InspectOutput {
    pub rows: Vec<SourceRow>,
}

/// The account owns the entry, it is of the expected kind and no link, and it has no special bits or write for others.
fn safe_metadata(
    path: &Path,
    directory: bool,
    user: &str,
    group: &str,
) -> Result<(), RegistrationError> {
    check(!is_symlink(path), "the entry is not a link")?;
    let metadata = fs::metadata(path).map_err(|_| invalid("the entry exists"))?;
    check(
        if directory {
            metadata.is_dir()
        } else {
            metadata.is_file()
        },
        "the entry has the expected kind",
    )?;
    check(
        user_name(metadata.uid()) == user && group_name(metadata.gid()) == group,
        "the account owns the entry",
    )?;
    check(
        metadata.mode() & 0o7002 == 0,
        "the entry has no special bits and no write for others",
    )
}

/// The repository name of an origin, lowercase, as a slug.
fn inferred_slug(origin: &str) -> String {
    let path = if let Some(rest) = origin.strip_prefix("git@") {
        rest.split_once(':').map_or("", |(_, path)| path)
    } else if let Some((_, rest)) = origin.split_once("://") {
        let rest = rest.split(['?', '#']).next().unwrap_or("");
        rest.find('/').map_or("", |slash| &rest[slash..])
    } else {
        origin
    };
    let trimmed = path
        .strip_suffix(".git")
        .unwrap_or(path)
        .trim_end_matches('/');
    trimmed.rsplit('/').next().unwrap_or("").to_lowercase()
}

fn inferred_root(path: &Path) -> Option<&'static str> {
    let framework = path.join("artisan").is_file() || path.join("bin/console").is_file();
    (path.join("composer.json").is_file() && framework && path.join("public").is_dir())
        .then_some("public")
}

pub fn inspect(input: &InspectInput) -> Result<Vec<SourceRow>, RegistrationError> {
    let (user, group) = (input.user.as_str(), input.group.as_str());
    let top = PathBuf::from(git(&input.path, &["rev-parse", "--show-toplevel"])?);
    check(
        top.is_absolute() && canonical(&top) && top.is_dir(),
        "the checkout's top is canonical",
    )?;
    let origin = git(&top, &["config", "--get", "remote.origin.url"])?;
    let common = realpath(Path::new(&git(
        &top,
        &["rev-parse", "--path-format=absolute", "--git-common-dir"],
    )?))
    .ok_or_else(|| invalid("the common Git directory exists"))?;
    let worktrees: Vec<String> = git(&top, &["worktree", "list", "--porcelain"])?
        .lines()
        .filter_map(|line| line.strip_prefix("worktree ").map(str::to_string))
        .collect();
    let paths = if input.include_worktrees {
        worktrees.clone()
    } else {
        vec![top.display().to_string()]
    };
    let default_branch = git(
        &top,
        &["symbolic-ref", "--short", "refs/remotes/origin/HEAD"],
    )
    .ok()
    .map(|reference| {
        reference
            .strip_prefix("origin/")
            .map_or(reference.clone(), str::to_string)
    });
    let slug = inferred_slug(&origin);
    let mut rows = Vec::new();
    for path in paths {
        let directory = PathBuf::from(&path);
        check(
            git(&directory, &["rev-parse", "--show-toplevel"])? == path && canonical(&directory),
            "each worktree is canonical",
        )?;
        safe_metadata(&directory, true, user, group)?;
        let dot_git = directory.join(".git");
        let layout = if dot_git.is_dir() && !is_symlink(&dot_git) {
            "checkout"
        } else {
            "worktree"
        };
        if layout == "worktree" {
            check(
                dot_git.is_file() && !is_symlink(&dot_git),
                "a worktree has a Git link file",
            )?;
        }
        safe_metadata(&dot_git, layout == "checkout", user, group)?;
        let git_directory = realpath(Path::new(&git(
            &directory,
            &["rev-parse", "--absolute-git-dir"],
        )?))
        .ok_or_else(|| invalid("the Git directory exists"))?;
        safe_metadata(&git_directory, true, user, group)?;
        safe_metadata(&common, true, user, group)?;
        let branch = git(&directory, &["symbolic-ref", "--short", "HEAD"]).ok();
        rows.push(SourceRow {
            commit: git(&directory, &["rev-parse", "--verify", "HEAD^{commit}"])?,
            source_digest: source_digest(&directory)?,
            inferred_root: inferred_root(&directory),
            detached: branch.is_none(),
            branch,
            path,
            layout,
            repository_url: origin.clone(),
            default_branch: default_branch.clone(),
            inferred_slug: slug.clone(),
            common_repository_path: common.display().to_string(),
            worktree_paths: worktrees.clone(),
        });
    }
    Ok(rows)
}

impl Command for InspectRegistrationSource {
    const NAME: &'static str = "instance.inspect-registration-source";
    const SUMMARY: &'static str = "Read a checkout, or its worktree set, with the identity and digest that registration keeps.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = InspectInput;
    type Output = InspectOutput;
    type Error = RegistrationError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: InspectInput, _: &Context) -> Result<InspectOutput, RegistrationError> {
        Ok(InspectOutput {
            rows: inspect(&input)?,
        })
    }
}

// ---- relocate ---------------------------------------------------------------------------------------------------

/// Adopts a source set in place, or moves it into the apps directory, in the step the Gateway recorded.
pub struct RelocateRegistrationSource;

#[derive(schemars::JsonSchema, Deserialize, Clone, Copy, PartialEq, Eq, Debug)]
#[serde(rename_all = "kebab-case")]
pub enum Operation {
    Adopt,
    AdoptFinalize,
    Prepare,
    Cleanup,
}

#[derive(schemars::JsonSchema, Deserialize, Clone)]
#[serde(deny_unknown_fields)]
pub struct Member {
    pub id: u64,
    pub source: PathBuf,
    pub destination: PathBuf,
    pub application: PathBuf,
    pub layout: String,
    pub commit: String,
    pub branch: Option<String>,
    pub detached: bool,
    pub digest: String,
    pub common: PathBuf,
    pub worktrees: Vec<PathBuf>,
    pub source_device: Option<u64>,
    pub source_inode: Option<u64>,
}

impl Member {
    fn stage(&self) -> PathBuf {
        let mut stage = self.destination.clone().into_os_string();
        stage.push(format!(".orbit-stage-{}", self.id));
        PathBuf::from(stage)
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RelocateInput {
    pub operation: Operation,
    pub members: Vec<Member>,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Identity {
    pub id: u64,
    pub source_device: Option<u64>,
    pub source_inode: Option<u64>,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct RelocateOutput {
    /// The identity of each original that `prepare` left in place, for cleanup to check.
    pub identities: Vec<Identity>,
}

/// The path holds the member's recorded source: the same commit, branch, and digest.
pub fn verify(path: &Path, member: &Member) -> Result<(), RegistrationError> {
    check(
        path.is_dir() && canonical(path),
        "the source is a canonical directory",
    )?;
    check(
        git(path, &["rev-parse", "--verify", "HEAD^{commit}"])? == member.commit,
        "the commit is the recorded one",
    )?;
    let branch = git(path, &["symbolic-ref", "--short", "HEAD"]).ok();
    check(
        branch == member.branch && branch.is_none() == member.detached,
        "the branch is the recorded one",
    )?;
    check(
        source_digest(path)? == member.digest,
        "the content is the recorded one",
    )
}

fn remove_stage(path: &Path) -> std::io::Result<()> {
    if is_symlink(path) || path.is_file() {
        fs::remove_file(path)
    } else {
        fs::remove_dir_all(path)
    }
}

fn source_identity(path: &Path) -> Result<(u64, u64), RegistrationError> {
    check(
        path.is_dir() && !is_symlink(path) && canonical(path),
        "the original is a canonical directory",
    )?;
    let metadata = fs::symlink_metadata(path).map_err(|_| invalid("the original exists"))?;
    Ok((metadata.dev(), metadata.ino()))
}

fn io(step: &'static str) -> impl Fn(std::io::Error) -> RegistrationError {
    move |_| RegistrationError::GitFailed { step: step.into() }
}

/// Copies a tree with its links, modes, and times, as a move across filesystems needs. A link keeps its own times.
fn copy_tree(from: &Path, to: &Path) -> std::io::Result<()> {
    let metadata = fs::symlink_metadata(from)?;
    if metadata.file_type().is_symlink() {
        std::os::unix::fs::symlink(fs::read_link(from)?, to)?;
    } else if metadata.is_dir() {
        fs::create_dir(to)?;
        for entry in fs::read_dir(from)? {
            let entry = entry?;
            copy_tree(&entry.path(), &to.join(entry.file_name()))?;
        }
        fs::set_permissions(to, fs::Permissions::from_mode(metadata.mode() & 0o7777))?;
    } else {
        fs::copy(from, to)?;
        fs::set_permissions(to, fs::Permissions::from_mode(metadata.mode() & 0o7777))?;
    }
    let times = rustix::fs::Timestamps {
        last_access: rustix::fs::Timespec {
            tv_sec: metadata.atime(),
            tv_nsec: metadata.atime_nsec() as _,
        },
        last_modification: rustix::fs::Timespec {
            tv_sec: metadata.mtime(),
            tv_nsec: metadata.mtime_nsec() as _,
        },
    };
    rustix::fs::utimensat(
        rustix::fs::CWD,
        to,
        &times,
        rustix::fs::AtFlags::SYMLINK_NOFOLLOW,
    )?;
    Ok(())
}

fn identity_of(source: &Path) -> Result<(Option<u64>, Option<u64>), RegistrationError> {
    if source.exists() {
        let (device, inode) = source_identity(source)?;
        Ok((Some(device), Some(inode)))
    } else {
        Ok((None, None))
    }
}

/// Moves one member to its destination, or resumes the move from the state an interruption left.
fn prepare(member: &Member) -> Result<Identity, RegistrationError> {
    let (source, destination, stage) = (&member.source, &member.destination, member.stage());
    let identity = |(device, inode): (Option<u64>, Option<u64>)| Identity {
        id: member.id,
        source_device: device,
        source_inode: inode,
    };
    if source == destination {
        verify(destination, member)?;
        return Ok(identity((None, None)));
    }
    if let Some(parent) = destination.parent() {
        fs::create_dir_all(parent).map_err(io("create the destination's parent"))?;
    }
    if destination.exists() {
        verify(destination, member)?;
        let original = if source.exists() {
            verify(source, member)?;
            identity_of(source)?
        } else {
            (None, None)
        };
        if fs::symlink_metadata(&stage).is_ok() {
            remove_stage(&stage).map_err(io("remove the stage"))?;
        }
        return Ok(identity(original));
    }
    if fs::symlink_metadata(&stage).is_ok() {
        if verify(&stage, member).is_ok() {
            let original = identity_of(source)?;
            fs::rename(&stage, destination).map_err(io("move the stage"))?;
            verify(destination, member)?;
            return Ok(identity(original));
        }
        // An incomplete stage is only a copy; the verified original makes it again.
        check(source.exists(), "an incomplete stage has its original")?;
        verify(source, member)?;
        remove_stage(&stage).map_err(io("remove the stage"))?;
    }
    check(source.exists(), "the original exists")?;
    verify(source, member)?;
    let (device, inode) = source_identity(source)?;
    match fs::rename(source, destination) {
        Ok(()) => {}
        Err(error) if error.raw_os_error() == Some(libc::EXDEV) => {
            copy_tree(source, &stage).map_err(io("copy the source"))?;
            verify(&stage, member)?;
            fs::rename(&stage, destination).map_err(io("move the stage"))?;
        }
        Err(_) => {
            return Err(RegistrationError::GitFailed {
                step: "move the source".into(),
            })
        }
    }
    verify(destination, member)?;
    Ok(identity((Some(device), Some(inode))))
}

/// Points Git's worktree links at the new paths.
fn repair(members: &[Member]) -> Result<(), RegistrationError> {
    let moved = |path: &PathBuf| {
        members
            .iter()
            .find(|member| &member.source == path)
            .map_or(path.clone(), |member| member.destination.clone())
    };
    let mut command = Process::new("git");
    command.args([
        "-c",
        "core.hooksPath=/dev/null",
        "-c",
        "core.fsmonitor=false",
    ]);
    if let Some(checkout) = members.iter().find(|member| member.layout == "checkout") {
        command
            .arg("-C")
            .arg(&checkout.destination)
            .args(["worktree", "repair"]);
        command.args(
            checkout
                .worktrees
                .iter()
                .filter(|path| *path != &checkout.source)
                .map(moved),
        );
    } else {
        let member = members
            .first()
            .ok_or_else(|| invalid("the set has a member"))?;
        command
            .arg("--git-dir")
            .arg(&member.common)
            .args(["worktree", "repair"])
            .arg(&member.destination);
    }
    let repaired = command
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status();
    check(
        repaired.is_ok_and(|status| status.success()),
        "Git repairs the worktree links",
    )
}

fn cleanup(member: &Member) -> Result<(), RegistrationError> {
    verify(&member.destination, member)?;
    if member.source != member.destination && member.source.exists() {
        let (device, inode) = source_identity(&member.source)?;
        check(
            Some(device) == member.source_device && Some(inode) == member.source_inode,
            "the original is the one prepare left",
        )?;
    }
    let stage = member.stage();
    if fs::symlink_metadata(&stage).is_ok() {
        remove_stage(&stage).map_err(io("remove the stage"))?;
    }
    Ok(())
}

fn remove_original(member: &Member) -> Result<(), RegistrationError> {
    if member.source == member.destination || !member.source.exists() {
        return Ok(());
    }
    fs::remove_dir_all(&member.source).map_err(io("remove the original"))
}

/// Other local users, the Node agent included, never read an Instance's environment.
fn close_environment(members: &[Member]) -> Result<(), RegistrationError> {
    for member in members {
        check(
            canonical(&member.application),
            "the application directory is canonical",
        )?;
        let environment = member.application.join(".env");
        if let Ok(metadata) = fs::symlink_metadata(&environment) {
            if metadata.is_file() && metadata.mode() & 0o007 != 0 {
                fs::set_permissions(
                    &environment,
                    fs::Permissions::from_mode(metadata.mode() & 0o770),
                )
                .map_err(io("close the environment file"))?;
            }
        }
    }
    Ok(())
}

pub fn relocate(
    operation: Operation,
    members: &[Member],
) -> Result<Vec<Identity>, RegistrationError> {
    // Worktrees move before their checkout, so the checkout's links can be repaired last.
    let mut ordered = members.to_vec();
    ordered.sort_by_key(|member| member.layout == "checkout");
    match operation {
        Operation::Adopt => {
            check(
                members
                    .iter()
                    .all(|member| member.source == member.destination),
                "adoption keeps every path",
            )?;
            for member in members {
                verify(&member.destination, member)?;
            }
            Ok(Vec::new())
        }
        Operation::AdoptFinalize => {
            for member in members {
                check(
                    member.source == member.destination,
                    "adoption keeps every path",
                )?;
                source_identity(&member.destination)?;
            }
            close_environment(members)?;
            Ok(Vec::new())
        }
        Operation::Prepare => {
            let identities = ordered.iter().map(prepare).collect::<Result<Vec<_>, _>>()?;
            repair(members)?;
            for member in members {
                verify(&member.destination, member)?;
            }
            Ok(identities)
        }
        Operation::Cleanup => {
            for member in members {
                cleanup(member)?;
            }
            for member in &ordered {
                remove_original(member)?;
            }
            for member in members {
                verify(&member.destination, member)?;
            }
            close_environment(members)?;
            Ok(Vec::new())
        }
    }
}

impl Command for RelocateRegistrationSource {
    const NAME: &'static str = "instance.relocate-registration-source";
    const SUMMARY: &'static str = "Adopt a registered source set in place, or move it into the apps directory and resume an interrupted move.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = RelocateInput;
    type Output = RelocateOutput;
    type Error = RegistrationError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn target(input: &RelocateInput) -> Option<String> {
        let first = input
            .members
            .iter()
            .map(|member| member.destination.display().to_string())
            .min()?;
        Some(format!("registration:{first}"))
    }

    fn run(input: RelocateInput, _: &Context) -> Result<RelocateOutput, RegistrationError> {
        check(!input.members.is_empty(), "the set has a member")?;
        Ok(RelocateOutput {
            identities: relocate(input.operation, &input.members)?,
        })
    }
}

// ---- Laravel receipt --------------------------------------------------------------------------------------------

/// Keeps, restores, or drops a copy of the Laravel files that registration rewrites for its URL: `.env` and the
/// cached configuration, with the times of the directories that hold them.
pub struct LaravelRegistrationReceipt;

#[derive(schemars::JsonSchema, Deserialize, Clone, Copy, PartialEq, Eq, Debug)]
#[serde(rename_all = "kebab-case")]
pub enum ReceiptOperation {
    Prepare,
    Restore,
    Discard,
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReceiptInput {
    pub checkout: PathBuf,
    pub instance_id: u64,
    pub operation: ReceiptOperation,
    pub application: PathBuf,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct ReceiptOutput {}

#[derive(Serialize, Deserialize, PartialEq, Debug)]
#[serde(deny_unknown_fields)]
struct Manifest {
    files: Vec<bool>,
    directories: Vec<Option<[i128; 2]>>,
}

struct Receipt {
    directory: PathBuf,
    files: [PathBuf; 2],
    directories: [PathBuf; 3],
}

impl Receipt {
    fn new(input: &ReceiptInput) -> Result<Self, RegistrationError> {
        check(
            canonical(&input.application),
            "the application directory is canonical",
        )?;
        let parent = input
            .checkout
            .parent()
            .ok_or_else(|| invalid("the checkout has a parent"))?;
        let application = &input.application;
        Ok(Self {
            directory: parent.join(format!(".orbit-registration-url-{}", input.instance_id)),
            files: [
                application.join(".env"),
                application.join("bootstrap/cache/config.php"),
            ],
            directories: [
                application.clone(),
                application.join("bootstrap"),
                application.join("bootstrap/cache"),
            ],
        })
    }

    fn validate(&self) -> Result<Manifest, RegistrationError> {
        check(
            self.directory.is_dir() && !is_symlink(&self.directory),
            "the receipt is a directory",
        )?;
        let manifest = self.directory.join("manifest");
        check(
            manifest.is_file() && !is_symlink(&manifest),
            "the receipt has its manifest",
        )?;
        let manifest: Manifest = fs::read(&manifest)
            .ok()
            .and_then(|bytes| serde_json::from_slice(&bytes).ok())
            .ok_or_else(|| invalid("the manifest is valid"))?;
        check(
            manifest.files.len() == self.files.len()
                && manifest.directories.len() == self.directories.len(),
            "the manifest covers every file",
        )?;
        for (index, kept) in manifest.files.iter().enumerate() {
            let backup = self.directory.join(index.to_string());
            check(
                *kept == (backup.is_file() && !is_symlink(&backup)),
                "each kept file has its copy",
            )?;
        }
        Ok(manifest)
    }
}

fn nanoseconds(seconds: i64, nanoseconds: i64) -> i128 {
    i128::from(seconds) * 1_000_000_000 + i128::from(nanoseconds)
}

fn copy_with_times(from: &Path, to: &Path) -> std::io::Result<()> {
    let metadata = fs::metadata(from)?;
    fs::copy(from, to)?;
    set_times(
        to,
        nanoseconds(metadata.atime(), metadata.atime_nsec()),
        nanoseconds(metadata.mtime(), metadata.mtime_nsec()),
    )
}

fn set_times(path: &Path, access: i128, modification: i128) -> std::io::Result<()> {
    let spec = |value: i128| rustix::fs::Timespec {
        tv_sec: (value.div_euclid(1_000_000_000)) as _,
        tv_nsec: (value.rem_euclid(1_000_000_000)) as _,
    };
    let times = rustix::fs::Timestamps {
        last_access: spec(access),
        last_modification: spec(modification),
    };
    rustix::fs::utimensat(rustix::fs::CWD, path, &times, rustix::fs::AtFlags::empty())?;
    Ok(())
}

pub fn receipt(input: &ReceiptInput) -> Result<(), RegistrationError> {
    let receipt = Receipt::new(input)?;
    let exists = fs::symlink_metadata(&receipt.directory).is_ok();
    match input.operation {
        ReceiptOperation::Prepare => {
            if exists {
                receipt.validate()?;
                return Ok(());
            }
            let mut name = receipt.directory.clone().into_os_string();
            name.push(".preparing");
            let preparing = PathBuf::from(name);
            if let Ok(metadata) = fs::symlink_metadata(&preparing) {
                check(metadata.is_dir(), "an earlier preparation is a directory")?;
                fs::remove_dir_all(&preparing).map_err(io("remove the earlier preparation"))?;
            }
            std::os::unix::fs::DirBuilderExt::mode(&mut fs::DirBuilder::new(), 0o700)
                .create(&preparing)
                .map_err(io("create the receipt"))?;
            let mut manifest = Manifest {
                files: Vec::new(),
                directories: Vec::new(),
            };
            for (index, path) in receipt.files.iter().enumerate() {
                let kept = path.is_file() && !is_symlink(path);
                manifest.files.push(kept);
                if kept {
                    copy_with_times(path, &preparing.join(index.to_string()))
                        .map_err(io("keep a file"))?;
                }
            }
            for path in &receipt.directories {
                manifest.directories.push(
                    fs::metadata(path)
                        .ok()
                        .filter(|_| path.is_dir() && !is_symlink(path))
                        .map(|metadata| {
                            [
                                nanoseconds(metadata.atime(), metadata.atime_nsec()),
                                nanoseconds(metadata.mtime(), metadata.mtime_nsec()),
                            ]
                        }),
                );
            }
            let written = preparing.join("manifest");
            fs::write(
                &written,
                serde_json::to_vec(&manifest).expect("a manifest serializes"),
            )
            .map_err(io("write the manifest"))?;
            fs::set_permissions(&written, fs::Permissions::from_mode(0o600))
                .map_err(io("write the manifest"))?;
            fs::rename(&preparing, &receipt.directory).map_err(io("publish the receipt"))?;
            receipt.validate()?;
        }
        ReceiptOperation::Restore => {
            let manifest = receipt.validate()?;
            for (index, path) in receipt.files.iter().enumerate() {
                if manifest.files[index] {
                    if let Some(parent) = path.parent() {
                        fs::create_dir_all(parent).map_err(io("restore a directory"))?;
                    }
                    copy_with_times(&receipt.directory.join(index.to_string()), path)
                        .map_err(io("restore a file"))?;
                } else if path.exists() && !is_symlink(path) {
                    fs::remove_file(path).map_err(io("remove a new file"))?;
                }
            }
            for (path, times) in receipt.directories.iter().zip(&manifest.directories).rev() {
                if let Some([access, modification]) = times {
                    if path.is_dir() && !is_symlink(path) {
                        set_times(path, *access, *modification)
                            .map_err(io("restore a directory time"))?;
                    }
                }
            }
            fs::remove_dir_all(&receipt.directory).map_err(io("remove the receipt"))?;
        }
        ReceiptOperation::Discard => {
            if exists {
                receipt.validate()?;
                fs::remove_dir_all(&receipt.directory).map_err(io("remove the receipt"))?;
            }
        }
    }
    Ok(())
}

impl Command for LaravelRegistrationReceipt {
    const NAME: &'static str = "instance.laravel-registration-receipt";
    const SUMMARY: &'static str =
        "Keep, restore, or drop the Laravel URL files that registration rewrites.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = ReceiptInput;
    type Output = ReceiptOutput;
    type Error = RegistrationError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn target(input: &ReceiptInput) -> Option<String> {
        Some(format!("registration-receipt:{}", input.instance_id))
    }

    fn run(input: ReceiptInput, _: &Context) -> Result<ReceiptOutput, RegistrationError> {
        receipt(&input)?;
        Ok(ReceiptOutput {})
    }
}

#[cfg(test)]
#[path = "registration_tests.rs"]
mod tests;

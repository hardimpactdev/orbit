//! Account-owned release preparation. PHP orders web installation and root Caddy access between
//! preparation and completion; no caller chooses an executable, environment, or script.
use super::{
    gateway_adoption::{self, directory, hex, owned, preparation::commit_at, Layout},
    gateway_releases::{self, prepared, release_id},
    git_read::GitRead,
    incus,
};
use crate::{
    command::{lock, Command, CommandError, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{
    fs,
    io::{self, Write},
    os::unix::fs::{symlink, DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::Command as Process,
    time::{Duration, Instant},
};

command_errors! {
    enum Failure {
        Invalid {} => "gateway.release_layout_invalid", "The Gateway release layout is invalid.";
        CommitInvalid {} => "gateway.release_commit_invalid", "Name the commit by its hexadecimal SHA, 7 to 40 characters.";
        LayoutMissing {} => "gateway.release_layout_missing", "The shared release repository or environment is missing. Run gateway:release:adopt first.";
        Unsafe {} => "gateway.release_path_conflict", "A release path has unexpected type or ownership.";
        DiskLow {free: u64, needed: u64} => "gateway.release_disk_low", "The release filesystem has too little space for the configured floor and database snapshot.";
        Fetch {} => "gateway.release_fetch_failed", "The shared release repository could not be fetched.";
        Unknown {} => "gateway.release_commit_unknown", "The commit is missing or its prefix is ambiguous.";
        Conflict {} => "gateway.release_conflict", "The release already holds another commit. Remove it before preparing it again.";
        CurrentIncomplete {} => "gateway.release_current_incomplete", "The current release is incomplete. Repair it before preparing it again.";
        Worktree {} => "gateway.release_worktree_failed", "The release worktree could not be created or removed.";
        Link {} => "gateway.release_link_failed", "The shared environment and storage could not be linked into the release.";
        Dependencies {} => "gateway.release_dependencies_failed", "The release runtime dependencies could not be installed or verified.";
        Access {} => "gateway.release_access_failed", "The release cache or source permissions could not be set.";
        Revision {} => "gateway.release_revision_failed", "The release revision could not be published.";
        Configuration {} => "gateway.release_configuration_failed", "The release configuration could not be cached privately.";
        Migration {} => "gateway.release_migrate_failed", "The release migrations failed. The database may be partly migrated; the snapshot holds the state before.";
        SchemaPending {} => "gateway.release_schema_pending", "Migration status is unavailable or migrations remain pending. Keep the hold.";
        Handoff {} => "gateway.release_handoff_failed", "The prepared release did not return a complete runtime handoff report.";
        Current {} => "gateway.release_current", "The current release cannot be removed.";
        NotPrepared {} => "gateway.release_not_prepared", "The release is not prepared from its named commit.";
        LockUnavailable {} => "gateway.release_lock_unavailable", "The private release step lock could not be opened or inspected.";
        InProgress {} => "gateway.release_in_progress", "A command from an earlier Gateway release step still runs.";
    }
}
#[derive(Debug, PartialEq)]
pub struct Error {
    failure: Failure,
    sha: Option<String>,
}
impl From<Failure> for Error {
    fn from(failure: Failure) -> Self {
        Self { failure, sha: None }
    }
}
impl Error {
    fn with_sha(mut self, sha: &str) -> Self {
        self.sha = Some(sha.into());
        self
    }
}
impl CommandError for Error {
    const CODES: &'static [&'static str] = Failure::CODES;
    fn code(&self) -> &'static str {
        self.failure.code()
    }
    fn message(&self) -> String {
        self.failure.message()
    }
    fn details(&self) -> Value {
        let mut details = self.failure.details();
        if let Some(sha) = &self.sha {
            details["sha"] = json!(sha);
        }
        details
    }
}
impl From<gateway_adoption::Error> for Error {
    fn from(error: gateway_adoption::Error) -> Self {
        match error {
            gateway_adoption::Error::Invalid {} => Failure::Invalid {},
            _ => Failure::Unsafe {},
        }
        .into()
    }
}
impl From<gateway_releases::Error> for Error {
    fn from(error: gateway_releases::Error) -> Self {
        match error {
            gateway_releases::Error::Invalid {} => Failure::Invalid {},
            _ => Failure::Unsafe {},
        }
        .into()
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectInput {
    pub checkout: String,
    pub revision: String,
    pub minimum_free_bytes: u64,
    pub reserved_bytes: u64,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PrepareInput {
    pub checkout: String,
    pub revision: String,
    pub minimum_free_bytes: u64,
    pub reserved_bytes: u64,
    pub git_read: Option<GitRead>,
    pub step_lock: PathBuf,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct FinishInput {
    pub checkout: String,
    pub sha: String,
    pub step_lock: PathBuf,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReleaseInput {
    pub checkout: String,
    pub release: String,
    pub step_lock: PathBuf,
}
#[derive(Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum HandoffPhase {
    Serve,
    Schedule,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct HandoffInput {
    pub checkout: String,
    pub release: String,
    pub step_lock: PathBuf,
    pub phase: HandoffPhase,
}
#[derive(Debug, Serialize)]
pub struct HandoffReport {
    succeeded: bool,
    report: serde_json::Map<String, Value>,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct StepInput {
    pub step_lock: PathBuf,
}
#[derive(Debug, Serialize)]
pub struct Inspection {
    sha: Option<String>,
    origin: Option<String>,
}
#[derive(Debug, Serialize)]
pub struct Preparation {
    sha: String,
    reused: bool,
}
#[derive(Debug, Serialize)]
pub struct Changed {
    changed: bool,
}
#[derive(Debug, Serialize)]
pub struct StepState {
    busy: bool,
}
#[derive(Debug, Serialize)]
pub struct SchemaState {
    complete: bool,
}

trait Host {
    fn run(&mut self, command: Process, seconds: u64) -> io::Result<incus::Output>;
    fn free_space(&self, path: &Path) -> Option<u64>;
}
struct Native<'a>(&'a Context);
impl Host for Native<'_> {
    fn run(&mut self, command: Process, seconds: u64) -> io::Result<incus::Output> {
        let remaining = self
            .0
            .remaining()
            .min(Duration::from_secs(seconds))
            .as_secs_f64();
        if remaining <= 0.0 {
            return Err(io::Error::other("release deadline expired"));
        }
        let mut bounded = Process::new("/usr/bin/timeout");
        bounded
            .args([
                "--signal=TERM",
                "--kill-after=1",
                &format!("{remaining:.6}s"),
            ])
            .arg(command.get_program())
            .args(command.get_args())
            .env_clear();
        for (key, value) in command.get_envs() {
            if let Some(value) = value {
                bounded.env(key, value);
            }
        }
        if let Some(directory) = command.get_current_dir() {
            bounded.current_dir(directory);
        }
        incus::capture_input(&mut bounded, None)
    }
    fn free_space(&self, path: &Path) -> Option<u64> {
        rustix::fs::statvfs(path)
            .ok()
            .map(|s| s.f_bavail.saturating_mul(s.f_frsize))
    }
}
fn process(program: &str) -> Process {
    let mut command = Process::new(program);
    command
        .env_clear()
        .env(
            "HOME",
            std::env::var_os("HOME").unwrap_or_else(|| "/home/orbit".into()),
        )
        .env(
            "PATH",
            "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
        )
        .env("LANG", "C.UTF-8");
    command
}
fn execute(
    host: &mut impl Host,
    command: Process,
    seconds: u64,
    failure: Failure,
) -> Result<String, Error> {
    let output = match host.run(command, seconds) {
        Ok(output) => output,
        Err(_) => return Err(failure.into()),
    };
    if output.code != 0 || output.truncated {
        // Configuration output can contain environment secrets. Its fixed refusal is sufficient.
        if failure != (Failure::Configuration {}) {
            eprintln!(
                "{}: {}",
                failure.code(),
                super::recipe::diagnostic(&output.stderr)
            );
        }
        return Err(failure.into());
    }
    Ok(output.stdout)
}
fn git(
    host: &mut impl Host,
    at: &Path,
    args: &[&str],
    read: &GitRead,
    failure: Failure,
) -> Result<String, Error> {
    let mut command = process("/usr/bin/git");
    read.configure(&mut command);
    command.args(read.fetch_options(&[
        "https".into(),
        "http".into(),
        "ssh".into(),
        "git".into(),
        "file".into(),
    ]));
    command.arg("-C").arg(at).args(args);
    execute(host, command, 300, failure)
}
fn revision(value: &str) -> Result<(), Error> {
    if !(7..=40).contains(&value.len()) || !hex(value, value.len()) {
        return Err(Failure::CommitInvalid {}.into());
    }
    Ok(())
}
fn repository_layout(checkout: &str) -> Result<Layout, Error> {
    let layout = Layout::new(checkout)?;
    directory(&layout.shared()).map_err(|_| Failure::LayoutMissing {})?;
    let repository = layout.shared().join("orbit.git");
    if !owned(&repository)?.is_some_and(|m| m.is_dir())
        || git2::Repository::open_bare(&repository).is_err()
    {
        return Err(Failure::LayoutMissing {}.into());
    }
    let releases = layout.base.join("releases");
    if owned(&releases)?.is_some() {
        directory(&releases)?;
    }
    Ok(layout)
}
const STORAGE_DIRECTORIES: &[&str] = &[
    "app/private",
    "app/public",
    "framework/cache/data",
    "framework/sessions",
    "framework/views",
    "logs",
];
fn layout(checkout: &str) -> Result<Layout, Error> {
    let layout = repository_layout(checkout)?;
    if !owned(&layout.shared().join("gateway.env"))?.is_some_and(|m| m.is_file()) {
        return Err(Failure::LayoutMissing {}.into());
    }
    let storage = layout.shared().join("gateway-storage");
    if owned(&storage)?.is_some() {
        directory(&storage)?;
        for relative in STORAGE_DIRECTORIES {
            let mut child = storage.clone();
            for part in relative.split('/') {
                child.push(part);
                if owned(&child)?.is_none() {
                    break;
                }
                directory(&child)?;
            }
        }
    }
    Ok(layout)
}
fn reserve(layout: &Layout, host: &impl Host, minimum: u64, reserved: u64) -> Result<(), Error> {
    let needed = minimum.checked_add(reserved).ok_or(Failure::Invalid {})?;
    let releases = layout.base.join("releases");
    let at = if releases.exists() {
        &releases
    } else {
        &layout.base
    };
    if let Some(free) = host.free_space(at) {
        if free < needed {
            return Err(Failure::DiskLow { free, needed }.into());
        }
    }
    Ok(())
}
fn inspect(input: &InspectInput, host: &impl Host) -> Result<Inspection, Error> {
    revision(&input.revision)?;
    let layout = layout(&input.checkout)?;
    reserve(
        &layout,
        host,
        input.minimum_free_bytes,
        input.reserved_bytes,
    )?;
    let repository = layout.shared().join("orbit.git");
    let sha = (input.revision.len() == 40)
        .then(|| commit_at(&repository, &input.revision))
        .flatten()
        .filter(|sha| sha == &input.revision);
    let origin = if sha.is_none() {
        let repo = git2::Repository::open_bare(&repository).map_err(|_| Failure::Fetch {})?;
        let remote = repo.find_remote("origin").map_err(|_| Failure::Fetch {})?;
        let origin = remote
            .url()
            .filter(|s| {
                super::source::repository_identity(s).is_some()
                    || (Path::new(s).is_absolute() && !s.bytes().any(|b| b.is_ascii_control()))
            })
            .ok_or(Failure::Fetch {})?;
        Some(origin.into())
    } else {
        None
    };
    Ok(Inspection { sha, origin })
}
fn step_path(path: &Path) -> Result<(), Error> {
    let parent = path.parent().ok_or(Failure::LockUnavailable {})?;
    if !path.is_absolute()
        || path
            .file_name()
            .is_none_or(|name| name != "gateway-release-step.lock")
        || fs::canonicalize(parent).ok().as_deref() != Some(parent)
    {
        return Err(Failure::LockUnavailable {}.into());
    }
    directory(parent).map_err(|_| Failure::LockUnavailable {})?;
    if let Some(meta) = owned(path).map_err(|_| Failure::LockUnavailable {})? {
        if !meta.is_file() || meta.mode() & 0o7777 != 0o600 {
            return Err(Failure::LockUnavailable {}.into());
        }
    }
    Ok(())
}
fn step(input: &StepInput) -> Result<StepState, Error> {
    step_path(&input.step_lock)?;
    Ok(StepState {
        busy: lock::is_busy(&input.step_lock).map_err(|_| Failure::LockUnavailable {})?,
    })
}
pub(super) fn hold_step(path: &Path, deadline: Instant) -> Result<lock::Held, Error> {
    step_path(path)?;
    match fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(path)
    {
        Ok(_) => {}
        Err(error) if error.kind() == io::ErrorKind::AlreadyExists => {}
        Err(_) => return Err(Failure::LockUnavailable {}.into()),
    }
    step_path(path)?;
    lock::acquire(path, deadline.min(Instant::now() + Duration::from_secs(30))).map_err(|error| {
        match error {
            lock::LockError::Busy(_) => Failure::InProgress {}.into(),
            lock::LockError::Io(..) => Failure::LockUnavailable {}.into(),
        }
    })
}
fn resolve(input: &PrepareInput, layout: &Layout, host: &mut impl Host) -> Result<String, Error> {
    let repository = layout.shared().join("orbit.git");
    let commit =
        || commit_at(&repository, &input.revision).filter(|sha| sha.starts_with(&input.revision));
    if input.revision.len() == 40 {
        if let Some(sha) = commit() {
            return Ok(sha);
        }
    }
    let read = input.git_read.as_ref().ok_or(Failure::Fetch {})?;
    git(
        host,
        &repository,
        &[
            "fetch",
            "--no-tags",
            "--prune",
            "origin",
            "+refs/heads/*:refs/remotes/origin/*",
        ],
        read,
        Failure::Fetch {},
    )?;
    let mut sha = commit();
    if sha.is_none() && input.revision.len() == 40 {
        let _ = git(
            host,
            &repository,
            &["fetch", "--no-tags", "origin", &input.revision],
            read,
            Failure::Fetch {},
        );
        sha = commit();
    }
    sha.ok_or_else(|| Failure::Unknown {}.into())
}
fn mkdir(path: &Path, mode: u32, failure: Failure) -> Result<(), Error> {
    if owned(path)?.is_some() {
        directory(path)?;
    } else {
        fs::DirBuilder::new()
            .mode(mode)
            .create(path)
            .map_err(|_| Error::from(failure))?;
    }
    Ok(())
}
fn app_directory(root: &Path, relative: &str) -> Result<PathBuf, Error> {
    let mut path = root.to_path_buf();
    directory(&path)?;
    for part in relative.split('/') {
        path.push(part);
        directory(&path)?;
    }
    Ok(path)
}
fn permissions(path: &Path, mode: u32, failure: Failure) -> Result<(), Error> {
    fs::set_permissions(path, fs::Permissions::from_mode(mode)).map_err(|_| failure.into())
}
/// Snapshot the tree without following links, before changing directory modes or removing anything.
fn entries(root: &Path, skip: &[PathBuf]) -> Result<Vec<(PathBuf, fs::Metadata)>, Error> {
    let mut pending = vec![root.to_path_buf()];
    let mut entries = vec![];
    while let Some(path) = pending.pop() {
        if skip.contains(&path) {
            continue;
        }
        let Some(meta) = owned(&path)? else {
            continue;
        };
        if meta.is_dir() {
            for entry in fs::read_dir(&path).map_err(|_| Failure::Worktree {})? {
                pending.push(entry.map_err(|_| Failure::Worktree {})?.path());
            }
        }
        entries.push((path, meta));
    }
    Ok(entries)
}
fn remove_tree(path: &Path) -> Result<bool, Error> {
    if owned(path)?.is_none() {
        return Ok(false);
    }
    directory(path)?;
    for (entry, meta) in entries(path, &[])? {
        if meta.is_dir() {
            permissions(&entry, meta.mode() | 0o200, Failure::Worktree {})?;
        }
    }
    fs::remove_dir_all(path).map_err(|_| Failure::Worktree {})?;
    Ok(true)
}
fn remove_partial(layout: &Layout, id: &str, host: &mut impl Host) -> Result<bool, Error> {
    let changed = remove_tree(&layout.base.join("releases").join(id))?;
    git(
        host,
        &layout.shared().join("orbit.git"),
        &["worktree", "prune"],
        &GitRead::default(),
        Failure::Worktree {},
    )?;
    Ok(changed)
}
fn shared(layout: &Layout, path: &Path, host: &mut impl Host) -> Result<(), Error> {
    let app = app_directory(path, "apps/gateway")?;
    let storage = layout.shared().join("gateway-storage");
    mkdir(&storage, 0o750, Failure::Link {})?;
    for relative in STORAGE_DIRECTORIES {
        let mut child = storage.clone();
        for part in relative.split('/') {
            child.push(part);
            mkdir(&child, 0o750, Failure::Link {})?;
        }
    }
    symlink(layout.shared().join("gateway.env"), app.join(".env")).map_err(|_| Failure::Link {})?;
    let tracked = git(
        host,
        path,
        &["ls-files", "-z", "--", "apps/gateway/storage"],
        &GitRead::default(),
        Failure::Link {},
    )?;
    let paths: Vec<_> = tracked.split('\0').filter(|s| !s.is_empty()).collect();
    if !paths.is_empty() {
        let mut args = vec!["update-index", "--skip-worktree", "--"];
        args.extend(paths);
        git(host, path, &args, &GitRead::default(), Failure::Link {})?;
    }
    remove_tree(&app.join("storage"))?;
    let info = layout.shared().join("orbit.git/info");
    mkdir(&info, 0o755, Failure::Link {})?;
    let exclude = info.join("exclude");
    if owned(&exclude)?.is_some_and(|m| !m.is_file()) {
        return Err(Failure::Link {}.into());
    }
    let mut bytes = match fs::read(&exclude) {
        Ok(bytes) => bytes,
        Err(error) if error.kind() == io::ErrorKind::NotFound => vec![],
        Err(_) => return Err(Failure::Link {}.into()),
    };
    if !bytes
        .split(|b| *b == b'\n')
        .any(|line| line == b"/apps/gateway/storage")
    {
        if !bytes.is_empty() && !bytes.ends_with(b"\n") {
            bytes.push(b'\n');
        }
        bytes.extend_from_slice(b"/apps/gateway/storage\n");
        fs::write(exclude, bytes).map_err(|_| Failure::Link {})?;
    }
    symlink(storage, app.join("storage")).map_err(|_| Failure::Link {})?;
    Ok(())
}
fn dependencies(path: &Path, host: &mut impl Host) -> Result<(), Error> {
    for project in ["apps/cli", "apps/gateway"] {
        let directory = app_directory(path, project)?;
        for args in [
            vec![
                "install",
                "--no-dev",
                "--prefer-dist",
                "--no-interaction",
                "--no-progress",
            ],
            vec!["check-platform-reqs", "--no-dev", "--no-interaction"],
        ] {
            let seconds = if args[0] == "install" { 900 } else { 120 };
            let mut command = process("/usr/bin/composer");
            command
                .arg(format!("--working-dir={}", directory.display()))
                .args(args);
            execute(host, command, seconds, Failure::Dependencies {})?;
        }
    }
    Ok(())
}
fn prepare(
    input: &PrepareInput,
    host: &mut impl Host,
    deadline: Instant,
) -> Result<Preparation, Error> {
    revision(&input.revision)?;
    let layout = layout(&input.checkout)?;
    reserve(
        &layout,
        host,
        input.minimum_free_bytes,
        input.reserved_bytes,
    )?;
    let _step = hold_step(&input.step_lock, deadline)?;
    let sha = resolve(input, &layout, host)?;
    let id = &sha[..12];
    let mut build = || -> Result<Preparation, Error> {
        let releases = layout.base.join("releases");
        if prepared(&layout, id)?.as_ref() == Some(&sha) {
            return Ok(Preparation {
                sha: sha.clone(),
                reused: true,
            });
        }
        let path = releases.join(id);
        if owned(&path.join("REVISION"))?.is_some() {
            return Err(Failure::Conflict {}.into());
        }
        if release_id(&layout, &layout.current)?.as_deref() == Some(id) {
            return Err(Failure::CurrentIncomplete {}.into());
        }
        mkdir(&releases, 0o710, Failure::Worktree {})?;
        remove_partial(&layout, id, host)?;
        git(
            host,
            &layout.shared().join("orbit.git"),
            &[
                "worktree",
                "add",
                "--detach",
                "--force",
                path.to_str().unwrap(),
                &sha,
            ],
            &GitRead::default(),
            Failure::Worktree {},
        )?;
        let cache =
            app_directory(&path, "apps/gateway/bootstrap/cache").map_err(|_| Failure::Access {})?;
        app_directory(&path, "apps/cli")?;
        permissions(&cache, 0o700, Failure::Access {})?;
        shared(&layout, &path, host)?;
        dependencies(&path, host)?;
        Ok(Preparation {
            sha: sha.clone(),
            reused: false,
        })
    };
    build().map_err(|error| error.with_sha(&sha))
}
fn release(layout: &Layout, id: &str) -> Result<PathBuf, Error> {
    if !hex(id, 12) {
        return Err(Failure::Invalid {}.into());
    }
    directory(&layout.base.join("releases"))?;
    let path = layout.base.join("releases").join(id);
    directory(&path)?;
    Ok(path)
}
fn cache(path: &Path, host: &mut impl Host) -> Result<(), Error> {
    let application = app_directory(path, "apps/gateway")?;
    let cache = app_directory(path, "apps/gateway/bootstrap/cache")?;
    permissions(&cache, 0o700, Failure::Access {})?;
    let candidate = cache.join(format!("config.next-{:016x}.php", rand::random::<u64>()));
    let result = (|| {
        let mut command = process("/usr/bin/php8.5");
        command
            .arg(application.join("artisan"))
            .args(["config:cache", "--no-interaction"])
            .env("APP_CONFIG_CACHE", &candidate);
        execute(host, command, 120, Failure::Configuration {})?;
        if !owned(&candidate)?.is_some_and(|m| m.is_file()) {
            return Err(Failure::Configuration {}.into());
        }
        permissions(&candidate, 0o600, Failure::Configuration {})?;
        fs::rename(&candidate, cache.join("config.php")).map_err(|_| Failure::Configuration {})?;
        Ok(())
    })();
    if result.is_err() {
        let _ = fs::remove_file(candidate);
    }
    result
}
fn finish(input: &FinishInput, host: &mut impl Host, deadline: Instant) -> Result<Changed, Error> {
    if !hex(&input.sha, 40) {
        return Err(Failure::CommitInvalid {}.into());
    }
    let layout = layout(&input.checkout)?;
    let id = &input.sha[..12];
    let path = release(&layout, id)?;
    if commit_at(&path, "HEAD").as_ref() != Some(&input.sha) {
        return Err(Failure::Conflict {}.into());
    }
    if prepared(&layout, id)?.is_some_and(|sha| sha != input.sha) {
        return Err(Failure::Conflict {}.into());
    }
    if release_id(&layout, &layout.current)?.as_deref() == Some(id) {
        return Err(Failure::Current {}.into());
    }
    let _step = hold_step(&input.step_lock, deadline)?;
    let result = (|| {
        let cache_dir = app_directory(&path, "apps/gateway/bootstrap/cache")?;
        if prepared(&layout, id)?.as_ref() == Some(&input.sha)
            && owned(&cache_dir.join("config.php"))?
                .is_some_and(|m| m.is_file() && m.mode() & 0o7777 == 0o600)
        {
            return Ok(Changed { changed: false });
        }
        permissions(&cache_dir, 0o700, Failure::Access {})?;
        for (entry, meta) in entries(&path, &[cache_dir, path.join("apps/cli/storage")])? {
            if meta.is_dir() || meta.is_file() {
                permissions(&entry, meta.mode() & !0o222, Failure::Access {})?;
            }
        }
        permissions(
            &path,
            fs::metadata(&path)
                .map_err(|_| Failure::Revision {})?
                .mode()
                | 0o200,
            Failure::Revision {},
        )?;
        let candidate = path.join(format!(".REVISION.{:016x}", rand::random::<u64>()));
        let revision = (|| -> io::Result<()> {
            let mut file = fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o644)
                .open(&candidate)?;
            writeln!(file, "{}", input.sha)?;
            file.set_permissions(fs::Permissions::from_mode(0o644))?;
            fs::rename(&candidate, path.join("REVISION"))
        })();
        let _ = fs::remove_file(candidate);
        permissions(
            &path,
            fs::metadata(&path)
                .map_err(|_| Failure::Revision {})?
                .mode()
                & !0o222,
            Failure::Revision {},
        )?;
        revision.map_err(|_| Failure::Revision {})?;
        if let Err(error) = cache(&path, host) {
            permissions(
                &path,
                fs::metadata(&path)
                    .map_err(|_| Failure::Configuration {})?
                    .mode()
                    | 0o200,
                Failure::Configuration {},
            )?;
            fs::remove_file(path.join("REVISION")).map_err(|_| Failure::Configuration {})?;
            return Err(error);
        }
        Ok(Changed { changed: true })
    })();
    result.map_err(|error: Error| error.with_sha(&input.sha))
}
fn refresh(
    input: &ReleaseInput,
    host: &mut impl Host,
    deadline: Instant,
) -> Result<Changed, Error> {
    let layout = layout(&input.checkout)?;
    let sha = prepared(&layout, &input.release)?.ok_or(Failure::NotPrepared {})?;
    let path = release(&layout, &input.release)?;
    let _step = hold_step(&input.step_lock, deadline)?;
    cache(&path, host).map_err(|error| error.with_sha(&sha))?;
    Ok(Changed { changed: true })
}
fn remove(input: &ReleaseInput, host: &mut impl Host, deadline: Instant) -> Result<Changed, Error> {
    if !hex(&input.release, 12) {
        return Err(Failure::Invalid {}.into());
    }
    let layout = repository_layout(&input.checkout)?;
    if release_id(&layout, &layout.current)?.as_deref() == Some(&input.release) {
        return Err(Failure::Current {}.into());
    }
    let _step = hold_step(&input.step_lock, deadline)?;
    Ok(Changed {
        changed: remove_partial(&layout, &input.release, host)?,
    })
}
fn prepared_artisan(input: &ReleaseInput) -> Result<(String, Process), Error> {
    let layout = layout(&input.checkout)?;
    let sha = prepared(&layout, &input.release)?.ok_or(Failure::NotPrepared {})?;
    let path = release(&layout, &input.release)?;
    let application = app_directory(&path, "apps/gateway")?;
    let cache = app_directory(&path, "apps/gateway/bootstrap/cache")?.join("config.php");
    if !owned(&cache)?.is_some_and(|m| m.is_file() && m.mode() & 0o7777 == 0o600) {
        return Err(Failure::Configuration {}.into());
    }
    let mut command = process("/usr/bin/php8.5");
    command
        .arg(application.join("artisan"))
        .env("APP_CONFIG_CACHE", cache);
    Ok((sha, command))
}
fn migrate(
    input: &ReleaseInput,
    host: &mut impl Host,
    deadline: Instant,
) -> Result<Changed, Error> {
    let (sha, mut command) = prepared_artisan(input)?;
    let _step = hold_step(&input.step_lock, deadline)?;
    command.args(["migrate", "--force", "--isolated", "--no-interaction"]);
    execute(host, command, 900, Failure::Migration {}).map_err(|error| error.with_sha(&sha))?;
    Ok(Changed { changed: true })
}
fn check_migrations(
    input: &ReleaseInput,
    host: &mut impl Host,
    deadline: Instant,
) -> Result<SchemaState, Error> {
    let (sha, mut command) = prepared_artisan(input)?;
    let _step = hold_step(&input.step_lock, deadline)?;
    command.args(["migrate:status", "--pending=3", "--no-interaction"]);
    execute(host, command, 120, Failure::SchemaPending {}).map_err(|error| error.with_sha(&sha))?;
    Ok(SchemaState { complete: true })
}
fn handoff(
    input: &HandoffInput,
    host: &mut impl Host,
    deadline: Instant,
) -> Result<HandoffReport, Error> {
    let (sha, mut command) = prepared_artisan(&ReleaseInput {
        checkout: input.checkout.clone(),
        release: input.release.clone(),
        step_lock: input.step_lock.clone(),
    })?;
    let _step = hold_step(&input.step_lock, deadline)?;
    command.args([
        "gateway:release:handoff",
        match input.phase {
            HandoffPhase::Serve => "--phase=serve",
            HandoffPhase::Schedule => "--phase=schedule",
        },
        "--no-interaction",
    ]);
    // Allow the maximum scheduler drain, tick-lock wait, serving changes, and agent installation.
    // The supervisor's deadline also covers the lock wait and every child of the handoff.
    let output = host
        .run(command, 4830)
        .map_err(|_| Error::from(Failure::Handoff {}).with_sha(&sha))?;
    if output.truncated {
        return Err(Error::from(Failure::Handoff {}).with_sha(&sha));
    }
    let report = serde_json::from_str::<serde_json::Map<String, Value>>(&output.stdout)
        .map_err(|_| Error::from(Failure::Handoff {}).with_sha(&sha))?;
    // The release's report is control-plane data. PHP owns phase fields and domain-error mapping.
    Ok(HandoffReport {
        succeeded: output.code == 0,
        report,
    })
}
macro_rules! command {
    ($type:ident, $name:literal, $input:ty, $output:ty, $mutates:literal, $seconds:literal, $target:expr, $run:expr) => {
        pub struct $type;
        impl Command for $type {
            const NAME: &'static str = $name;
            const SUMMARY: &'static str =
                "Prepare, complete, or remove an account-owned Gateway release.";
            const MUTATES: bool = $mutates;
            const TIMEOUT: Duration = Duration::from_secs($seconds);
            type Input = $input;
            type Output = $output;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Account)
            }
            fn target(input: &Self::Input) -> Option<String> {
                ($target)(input)
            }
            fn run(input: Self::Input, context: &Context) -> Result<Self::Output, Error> {
                ($run)(input, context)
            }
        }
    };
}
command!(
    Handoff,
    "gateway.handoff-release",
    HandoffInput,
    HandoffReport,
    true,
    4860,
    // The child handoff calls other release commands. Its orchestration lock must not reacquire theirs.
    |input: &HandoffInput| Some(format!("gateway-release-handoff:{}", input.checkout)),
    |input: HandoffInput, context: &Context| handoff(
        &input,
        &mut Native(context),
        context.deadline()
    )
);
command!(
    Inspect,
    "gateway.inspect-release-preparation",
    InspectInput,
    Inspection,
    false,
    30,
    |_: &InspectInput| None,
    |input: InspectInput, context: &Context| inspect(&input, &Native(context))
);
command!(
    Prepare,
    "gateway.prepare-release",
    PrepareInput,
    Preparation,
    true,
    2400,
    |input: &PrepareInput| Some(format!("gateway-release:{}", input.checkout)),
    |input: PrepareInput, context: &Context| prepare(
        &input,
        &mut Native(context),
        context.deadline()
    )
);
command!(
    Finish,
    "gateway.finish-release",
    FinishInput,
    Changed,
    true,
    300,
    |input: &FinishInput| Some(format!("gateway-release:{}", input.checkout)),
    |input: FinishInput, context: &Context| finish(
        &input,
        &mut Native(context),
        context.deadline()
    )
);
command!(
    Refresh,
    "gateway.refresh-release-configuration",
    ReleaseInput,
    Changed,
    true,
    180,
    |input: &ReleaseInput| Some(format!("gateway-release:{}", input.checkout)),
    |input: ReleaseInput, context: &Context| refresh(
        &input,
        &mut Native(context),
        context.deadline()
    )
);
command!(
    Remove,
    "gateway.remove-release",
    ReleaseInput,
    Changed,
    true,
    300,
    |input: &ReleaseInput| Some(format!("gateway-release:{}", input.checkout)),
    |input: ReleaseInput, context: &Context| remove(
        &input,
        &mut Native(context),
        context.deadline()
    )
);
command!(
    Migrate,
    "gateway.migrate-release",
    ReleaseInput,
    Changed,
    true,
    960,
    |input: &ReleaseInput| Some(format!("gateway-release:{}", input.checkout)),
    |input: ReleaseInput, context: &Context| migrate(
        &input,
        &mut Native(context),
        context.deadline()
    )
);
command!(
    CheckMigrations,
    "gateway.check-release-migrations",
    ReleaseInput,
    SchemaState,
    true,
    180,
    |input: &ReleaseInput| Some(format!("gateway-release:{}", input.checkout)),
    |input: ReleaseInput, context: &Context| check_migrations(
        &input,
        &mut Native(context),
        context.deadline()
    )
);
command!(
    InspectStep,
    "gateway.inspect-release-step",
    StepInput,
    StepState,
    false,
    30,
    |_: &StepInput| None,
    |input: StepInput, _: &Context| step(&input)
);

#[cfg(test)]
mod tests;

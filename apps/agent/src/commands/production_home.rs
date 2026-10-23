//! The production home before and around its releases: the system account and its home, the initial clone in
//! `releases/initial` with its two root-owned markers, the branch it checks out, its Composer profile, Caddy's
//! access, the check of the `current` selection, and the home's removal. These replace the Bash that
//! `RemoteProductionInstanceSourceLifecycle` sent, check for check, and keep its marker formats.

use super::laravel::{entries, plain, put};
use super::production::{
    account, as_user, as_user_with_input, check, failed, git, invalid, none, relative_path,
    root_owned, setfacl, walk, Layout, ReleaseError, ReleaseHome,
};
use super::source::{
    exists, is_real_directory, is_regular_file, is_symlink, realpath, realpath_missing,
};
use crate::command::{Command, Context, Platform, Privilege};
use base64::Engine;
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    ffi::{CStr, CString, OsStr},
    fs,
    io::Write,
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

const SHELL: &str = "/usr/sbin/nologin";

/// A production account's passwd entry: its uid, its primary group's name, its home, and its shell.
struct Entry {
    uid: u32,
    group: String,
    home: String,
    shell: String,
}

fn entry(user: &str) -> Option<Entry> {
    let name = CString::new(user).ok()?;
    // SAFETY: getpwnam and getgrgid return pointers into static storage or null; the fields are copied at once.
    unsafe {
        let entry = libc::getpwnam(name.as_ptr());
        if entry.is_null() {
            return None;
        }
        let group = libc::getgrgid((*entry).pw_gid);
        Some(Entry {
            uid: (*entry).pw_uid,
            group: if group.is_null() {
                String::new()
            } else {
                CStr::from_ptr((*group).gr_name)
                    .to_string_lossy()
                    .into_owned()
            },
            home: CStr::from_ptr((*entry).pw_dir)
                .to_string_lossy()
                .into_owned(),
            shell: CStr::from_ptr((*entry).pw_shell)
                .to_string_lossy()
                .into_owned(),
        })
    }
}

/// The account is Orbit's system account for this home.
fn entry_matches(entry: &Entry, user: &str, home: &Path) -> bool {
    Path::new(&entry.home) == home
        && entry.shell == SHELL
        && entry.group == user
        && entry.uid < 1000
}

fn production_user(user: &str) -> bool {
    user.len() <= 32
        && user.strip_prefix("orbit-app-").is_some_and(|id| {
            !id.starts_with('0') && !id.is_empty() && id.bytes().all(|b| b.is_ascii_digit())
        })
}

fn run(program: &str, arguments: &[&OsStr]) -> bool {
    Process::new(program)
        .args(arguments)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok_and(|status| status.success())
}

/// Every file below the root belongs to the user and the user's group.
fn only_owned_by(root: &Path, uid: u32, gid: u32) -> Result<bool, ReleaseError> {
    let mut owned = true;
    walk(root, &mut |_, metadata| {
        owned &= metadata.uid() == uid && metadata.gid() == gid;
    })?;
    Ok(owned)
}

fn write_marker(directory: &Path, path: &Path, content: &[u8]) -> Result<(), ReleaseError> {
    let candidate = directory.join(format!(".marker.{:016x}", rand::random::<u64>()));
    let written = (|| {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&candidate)?;
        file.write_all(content)?;
        file.sync_all()?;
        fs::rename(&candidate, path)
    })();
    if written.is_err() {
        let _ = fs::remove_file(&candidate);
    }
    written.map_err(|_| failed("write the source marker"))
}

/// The repository, user, and home of a production Instance, for the commands before a release exists.
#[derive(Deserialize, Clone)]
#[serde(deny_unknown_fields)]
pub struct Home {
    pub repository: String,
    pub user: String,
    pub home: PathBuf,
    pub instance: u64,
}

impl Home {
    fn state(&self, layout: &Layout) -> PathBuf {
        layout.state_root.join(self.instance.to_string())
    }

    fn clone_marker(&self) -> Vec<u8> {
        format!(
            "{}\0{}\0{}\0",
            self.repository,
            self.user,
            self.home.display()
        )
        .into_bytes()
    }

    fn layout_marker(&self) -> Vec<u8> {
        format!(
            "{}\0{}\0{}\0initial\0",
            self.repository,
            self.user,
            self.home.display()
        )
        .into_bytes()
    }

    fn marker_matches(&self, path: &Path, content: &[u8], layout: &Layout) -> bool {
        root_owned(path, layout, 0o600, false)
            && fs::read(path).is_ok_and(|actual| actual == content)
    }
}

// ---- the account -------------------------------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct UserInput {
    pub user: String,
    pub home: PathBuf,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Prepared {}

/// Creates the system account and its private home, or checks that an existing one is Orbit's.
pub fn prepare_user(input: &UserInput, layout: &Layout) -> Result<Prepared, ReleaseError> {
    let (user, home) = (input.user.as_str(), input.home.as_path());
    check(
        home == layout.home_root.join(user),
        "the home is the user's",
    )?;
    check(production_user(user), "the user is a production user")?;
    match entry(user) {
        Some(found) => check(entry_matches(&found, user, home), "the account is Orbit's")?,
        None => {
            check(!exists(home), "no home exists without the account")?;
            check(
                run(
                    "useradd",
                    &["--system", "--user-group", "--home-dir"]
                        .map(OsStr::new)
                        .into_iter()
                        .chain([
                            home.as_os_str(),
                            OsStr::new("--shell"),
                            OsStr::new(SHELL),
                            OsStr::new("--"),
                            OsStr::new(user),
                        ])
                        .collect::<Vec<_>>(),
                ),
                "the account is created",
            )?;
        }
    }
    let (uid, gid) = account(user)?;
    if exists(home) {
        check(is_real_directory(home), "the home is a real directory")?;
        check(
            fs::metadata(home).is_ok_and(|metadata| metadata.uid() == uid && metadata.gid() == gid),
            "the user owns the home",
        )?;
    } else {
        fs::create_dir(home)
            .and_then(|()| fs::set_permissions(home, fs::Permissions::from_mode(0o700)))
            .and_then(|()| std::os::unix::fs::chown(home, Some(uid), Some(gid)))
            .map_err(|_| failed("create the home"))?;
    }
    Ok(Prepared {})
}

pub struct PrepareUser;

impl Command for PrepareUser {
    const NAME: &'static str = "instances.prepare-production-user";
    const SUMMARY: &'static str =
        "Create a production Instance's system account and private home, or check them.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = UserInput;
    type Output = Prepared;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(input: &UserInput) -> Option<String> {
        Some(format!("production-user:{}", input.user))
    }

    fn run(input: UserInput, _: &Context) -> Result<Prepared, ReleaseError> {
        prepare_user(&input, &Layout::system())
    }
}

// ---- the initial clone -------------------------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SourceInput {
    #[serde(flatten)]
    pub home: Home,
    /// A clone that already exists and matches its markers passes.
    pub allow_existing: bool,
    #[serde(default)]
    pub git_read: BTreeMap<String, String>,
}

/// Clones the repository into `releases/initial` of an empty home, with an empty `.env`, and records the two
/// root-owned markers that later commands check.
pub fn prepare_source(input: &SourceInput, layout: &Layout) -> Result<Prepared, ReleaseError> {
    let home = &input.home;
    let user = home.user.as_str();
    check(
        home.home == layout.home_root.join(user),
        "the home is the user's",
    )?;
    check(home.instance >= 1, "the Instance is valid")?;
    let (uid, gid) = account(user)?;
    check(
        is_real_directory(&home.home),
        "the home is a real directory",
    )?;
    check(
        fs::metadata(&home.home)
            .is_ok_and(|metadata| metadata.uid() == uid && metadata.gid() == gid),
        "the user owns the home",
    )?;
    check(
        only_owned_by(&home.home, uid, gid)?,
        "only the user owns files in the home",
    )?;
    let releases = home.home.join("releases");
    let release = releases.join("initial");
    let environment = home.home.join(".env");
    let state = home.state(layout);

    if exists(&release.join(".git")) {
        check(input.allow_existing, "the initial clone is new")?;
        check(
            fs::symlink_metadata(&layout.state_root).is_ok_and(|metadata| {
                metadata.is_dir()
                    && metadata.uid() == layout.root_uid
                    && metadata.gid() == layout.root_uid
            }),
            "the source state root is root's",
        )?;
        check(
            root_owned(&state, layout, 0o700, true),
            "the source state directory is root's",
        )?;
        check(
            home.marker_matches(&state.join("initial-clone"), &home.clone_marker(), layout),
            "the clone marker names this home",
        )?;
        check(
            home.marker_matches(&state.join("release-layout"), &home.layout_marker(), layout),
            "the layout marker names this home",
        )?;
        check(
            is_real_directory(&releases),
            "the releases directory is real",
        )?;
        check(
            is_regular_file(&environment)
                && fs::metadata(&environment)
                    .is_ok_and(|metadata| metadata.mode() & 0o7777 == 0o600),
            "the stable environment is private",
        )?;
        check(
            !exists(&home.home.join("current")),
            "no release is selected",
        )?;
        check(
            is_real_directory(&release.join(".git")),
            "the clone is a repository",
        )?;
        check(
            git(
                user,
                &release,
                &["rev-parse", "--is-inside-work-tree"],
                &none(),
            )
            .as_deref()
                == Some("true"),
            "the clone is a work tree",
        )?;
        let origin = as_user(
            user,
            "git",
            &[
                "-C".as_ref(),
                release.as_os_str(),
                "config".as_ref(),
                "--null".as_ref(),
                "--get".as_ref(),
                "remote.origin.url".as_ref(),
            ],
            &none(),
        );
        check(
            origin.is_some_and(|origin| origin == format!("{}\0", home.repository).into_bytes()),
            "the clone's origin is the repository",
        )?;
        return Ok(Prepared {});
    }

    check(
        fs::read_dir(&home.home).is_ok_and(|mut entries| entries.next().is_none()),
        "the home is empty",
    )?;
    check(!exists(&state), "no source state exists for a new clone")?;
    as_user(
        user,
        "install",
        &["-d", "-m", "0700", "--"]
            .map(OsStr::new)
            .into_iter()
            .chain([releases.as_os_str()])
            .collect::<Vec<_>>(),
        &none(),
    )
    .ok_or_else(|| failed("create the releases directory"))?;
    as_user(
        user,
        "install",
        &["-m", "0600", "/dev/null"]
            .map(OsStr::new)
            .into_iter()
            .chain([environment.as_os_str()])
            .collect::<Vec<_>>(),
        &none(),
    )
    .ok_or_else(|| failed("create the stable environment"))?;
    let clone: Vec<&OsStr> = [
        "-c",
        "core.hooksPath=/dev/null",
        "-c",
        "core.fsmonitor=false",
        "clone",
        "--no-checkout",
        "--origin",
        "origin",
        "--",
    ]
    .map(OsStr::new)
    .into_iter()
    .chain([OsStr::new(&home.repository), release.as_os_str()])
    .collect();
    as_user(user, "git", &clone, &input.git_read).ok_or_else(|| failed("clone the repository"))?;
    for directory in [&layout.state_root, &state] {
        if !exists(directory) {
            fs::create_dir(directory)
                .and_then(|()| fs::set_permissions(directory, fs::Permissions::from_mode(0o700)))
                .map_err(|_| failed("create the source state"))?;
        }
    }
    check(
        is_real_directory(&layout.state_root)
            && fs::metadata(&layout.state_root).is_ok_and(|metadata| {
                metadata.uid() == layout.root_uid && metadata.gid() == layout.root_uid
            }),
        "the source state root is root's",
    )?;
    check(
        root_owned(&state, layout, 0o700, true),
        "the source state directory is root's",
    )?;
    write_marker(&state, &state.join("initial-clone"), &home.clone_marker())?;
    write_marker(&state, &state.join("release-layout"), &home.layout_marker())?;
    Ok(Prepared {})
}

pub struct PrepareSource;

impl Command for PrepareSource {
    const NAME: &'static str = "instances.prepare-production-source";
    const SUMMARY: &'static str =
        "Clone a production Instance's repository into its initial release and record the source markers.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = SourceInput;
    type Output = Prepared;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &SourceInput) -> Option<String> {
        Some(format!("release-home:{}", input.home.instance))
    }

    fn run(input: SourceInput, _: &Context) -> Result<Prepared, ReleaseError> {
        prepare_source(&input, &Layout::system())
    }
}

// ---- the branch of the initial release ---------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ResolveInput {
    #[serde(flatten)]
    pub home: ReleaseHome,
    pub branch: String,
    /// A clone target may replace a tracked, unchanged `.env` with the link.
    pub clone_target: bool,
    #[serde(default)]
    pub git_read: BTreeMap<String, String>,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Resolved {
    pub branch: String,
    pub commit: String,
}

/// Fetches the origin, checks the branch out in `releases/initial`, and links the application's `.env` to the
/// stable one, before any release is selected.
pub fn resolve(input: &ResolveInput, layout: &Layout) -> Result<Resolved, ReleaseError> {
    let home = &input.home;
    let user = home.user.as_str();
    home.check(layout, false)?;
    let release = home.release("initial");
    let environment = home.environment();
    check(
        is_regular_file(&environment),
        "the stable environment is a file",
    )?;
    check(
        !exists(&home.home.join("current")),
        "no release is selected",
    )?;
    git(
        user,
        &release,
        &["fetch", "--prune", "--", "origin"],
        &input.git_read,
    )
    .ok_or_else(|| failed("fetch the origin"))?;
    let source = format!("refs/remotes/origin/{}", input.branch);
    git(
        user,
        &release,
        &["show-ref", "--verify", "--quiet", &source],
        &none(),
    )
    .ok_or_else(|| failed("find the branch"))?;
    git(
        user,
        &release,
        &["checkout", "--quiet", "-B", &input.branch, &source],
        &none(),
    )
    .ok_or_else(|| failed("check out the branch"))?;
    git(
        user,
        &release,
        &[
            "branch",
            "--quiet",
            &format!("--set-upstream-to=origin/{}", input.branch),
            &input.branch,
        ],
        &none(),
    )
    .ok_or_else(|| failed("track the branch"))?;
    let application = home.application(&release);
    check(
        realpath(&application).as_deref() == Some(application.as_path()),
        "the application directory is canonical",
    )?;
    let link = application.join(".env");
    let tracked = home.application_suffix.trim_start_matches('/');
    let tracked = if tracked.is_empty() {
        ".env".to_string()
    } else {
        format!("{tracked}/.env")
    };
    let ln = || {
        as_user(
            user,
            "ln",
            &[
                "-s".as_ref(),
                OsStr::new(&home.environment_target()),
                link.as_os_str(),
            ],
            &none(),
        )
        .ok_or_else(|| failed("link the environment"))
    };
    if is_symlink(&link) {
        check(
            fs::read_link(&link)
                .is_ok_and(|target| target == Path::new(&home.environment_target())),
            "the release links the stable environment",
        )?;
    } else if exists(&link) {
        check(
            input.clone_target,
            "only a clone target replaces a tracked .env",
        )?;
        check(is_regular_file(&link), "the tracked .env is a file")?;
        for arguments in [
            vec!["ls-files", "--error-unmatch", "--", tracked.as_str()],
            vec!["diff", "--quiet", "--", tracked.as_str()],
            vec!["diff", "--cached", "--quiet", "--", tracked.as_str()],
        ] {
            git(user, &release, &arguments, &none())
                .ok_or_else(|| invalid("the tracked .env is unchanged"))?;
        }
        as_user(user, "rm", &["--".as_ref(), link.as_os_str()], &none())
            .ok_or_else(|| failed("remove the tracked .env"))?;
        ln()?;
    } else {
        ln()?;
    }
    check(
        realpath(&link).as_deref() == Some(environment.as_path()),
        "the release links the stable environment",
    )?;
    let commit = git(user, &release, &["rev-parse", "--verify", "HEAD"], &none())
        .ok_or_else(|| failed("read the commit"))?;
    Ok(Resolved {
        branch: input.branch.clone(),
        commit,
    })
}

pub struct ResolveSource;

impl Command for ResolveSource {
    const NAME: &'static str = "instances.resolve-production-source";
    const SUMMARY: &'static str =
        "Check out a branch in a production Instance's initial release and link its environment.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = ResolveInput;
    type Output = Resolved;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &ResolveInput) -> Option<String> {
        Some(format!("release-home:{}", input.home.instance))
    }

    fn run(input: ResolveInput, _: &Context) -> Result<Resolved, ReleaseError> {
        resolve(&input, &Layout::system())
    }
}

// ---- the Composer profile ------------------------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ProfileInput {
    pub user: String,
    pub relative_root: String,
    pub checkout: PathBuf,
    pub application: PathBuf,
    /// The framework's entry point, such as `artisan`.
    pub entry_point: String,
}

#[derive(Debug, Serialize, PartialEq)]
#[serde(rename_all = "lowercase")]
pub enum ProfileKind {
    /// No `composer.json` and no entry point.
    None,
    /// An entry point without `composer.json`.
    Partial,
    /// A path that escapes the checkout, a file that is not the user's, or a link where a file belongs.
    Unsafe,
    Composer,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Profile {
    pub kind: ProfileKind,
    /// `absent`, `regular`, or `unsafe`, with `composer`.
    pub entry_point: Option<String>,
    /// The base64 of `composer.json`, with `composer`.
    pub composer: Option<String>,
}

fn unsafe_profile() -> Profile {
    Profile {
        kind: ProfileKind::Unsafe,
        entry_point: None,
        composer: None,
    }
}

/// Reads the application's Composer metadata, refusing any path or file the user does not own inside the checkout.
pub fn profile(input: &ProfileInput) -> Result<Profile, ReleaseError> {
    let (uid, gid) = account(&input.user)?;
    let checkout = &input.checkout;
    let application = realpath_missing(&input.application);
    if application != input.application
        || !(application == *checkout || application.starts_with(checkout))
    {
        return Ok(unsafe_profile());
    }
    let resolved = realpath_missing(&checkout.join(&input.relative_root));
    if resolved == *checkout || !resolved.starts_with(checkout) {
        return Ok(unsafe_profile());
    }
    if !only_owned_by(checkout, uid, gid)? {
        return Ok(unsafe_profile());
    }
    if fs::symlink_metadata(&resolved)
        .is_ok_and(|metadata| metadata.uid() != uid || metadata.gid() != gid)
    {
        return Ok(unsafe_profile());
    }
    composer_profile(&application, &input.entry_point, uid)
}

/// The Composer metadata of an application directory, when only the user owns `composer.json` and neither it nor
/// the entry point is a link.
pub(super) fn composer_profile(
    application: &Path,
    entry_point: &str,
    uid: u32,
) -> Result<Profile, ReleaseError> {
    let composer = application.join("composer.json");
    let entry_point = application.join(entry_point);
    if is_symlink(&composer) || (exists(&composer) && !composer.is_file()) {
        return Ok(unsafe_profile());
    }
    if !exists(&composer) {
        return Ok(Profile {
            kind: if exists(&entry_point) {
                ProfileKind::Partial
            } else {
                ProfileKind::None
            },
            entry_point: None,
            composer: None,
        });
    }
    if fs::metadata(&composer).is_ok_and(|metadata| metadata.uid() != uid) {
        return Ok(unsafe_profile());
    }
    let kind = if is_symlink(&entry_point) {
        "unsafe"
    } else if entry_point.is_file() {
        "regular"
    } else if exists(&entry_point) {
        "unsafe"
    } else {
        "absent"
    };
    let content = fs::read(&composer).map_err(|_| invalid("composer.json is readable"))?;
    Ok(Profile {
        kind: ProfileKind::Composer,
        entry_point: Some(kind.into()),
        composer: Some(base64::engine::general_purpose::STANDARD.encode(content)),
    })
}

pub struct InspectProfile;

impl Command for InspectProfile {
    const NAME: &'static str = "instances.inspect-production-profile";
    const SUMMARY: &'static str =
        "Read a production checkout's Composer metadata, refusing files the user does not own.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = ProfileInput;
    type Output = Profile;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: ProfileInput, _: &Context) -> Result<Profile, ReleaseError> {
        profile(&input)
    }
}

// ---- Caddy's access ------------------------------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CaddyInput {
    pub user: String,
    pub home: PathBuf,
    pub relative_root: String,
    /// Before `current` exists, a clone target grants the initial release's web root.
    pub clone_target: bool,
}

/// Denies Caddy the whole home, then lets it traverse to the selected web root and read below it.
pub fn grant_caddy(input: &CaddyInput, layout: &Layout) -> Result<Prepared, ReleaseError> {
    let home = &input.home;
    let (uid, gid) = account(&input.user)?;
    check(
        *home == layout.home_root.join(&input.user),
        "the home is the user's",
    )?;
    check(
        is_real_directory(home) && realpath(home).as_deref() == Some(home.as_path()),
        "the home is canonical",
    )?;
    check(
        relative_path(&input.relative_root),
        "the web root is relative",
    )?;
    let releases = home.join("releases");
    let current = home.join("current");
    let selected = if exists(&current) {
        check(is_symlink(&current), "current is a link")?;
        check(
            fs::symlink_metadata(&current)
                .is_ok_and(|metadata| metadata.uid() == uid && metadata.gid() == gid),
            "the user owns current",
        )?;
        check(
            realpath(&releases).as_deref() == Some(releases.as_path()),
            "the releases directory is canonical",
        )?;
        let selected = realpath(&current).ok_or_else(|| invalid("current names a release"))?;
        check(
            selected.starts_with(&releases) && selected != releases,
            "current names a release",
        )?;
        Some(selected)
    } else if input.clone_target {
        let initial = releases.join("initial");
        check(
            realpath(&initial).as_deref() == Some(initial.as_path()),
            "the initial release is canonical",
        )?;
        Some(initial)
    } else {
        None
    };
    let mut ancestors = Vec::new();
    let mut root = None;
    if let Some(selected) = &selected {
        check(selected.is_dir(), "the selected release is a directory")?;
        let document_root = realpath_missing(&selected.join(&input.relative_root));
        check(
            document_root.starts_with(selected),
            "the web root is in the release",
        )?;
        check(
            document_root.is_dir()
                && realpath(&selected.join(&input.relative_root)).as_deref()
                    == Some(document_root.as_path()),
            "the web root is a directory",
        )?;
        let mut links = false;
        walk(&document_root, &mut |_, metadata| {
            links |= metadata.file_type().is_symlink()
        })?;
        check(!links, "the web root holds no links")?;
        let mut ancestor = document_root.clone();
        while ancestor.pop() && ancestor != *home {
            check(ancestor.starts_with(home), "the web root is in the home")?;
            check(
                is_real_directory(&ancestor),
                "each parent of the web root is a real directory",
            )?;
            ancestors.push(ancestor.clone());
        }
        root = Some(document_root);
    }
    check(
        only_owned_by(home, uid, gid)?,
        "only the user owns files in the home",
    )?;
    setfacl(&["-n", "-P", "-R", "-m", "u:caddy:---"], home)?;
    let mut directories = Vec::new();
    walk(home, &mut |path, metadata| {
        if metadata.is_dir() {
            directories.push(path.to_path_buf());
        }
    })?;
    for directory in &directories {
        setfacl(&["-m", "d:u:caddy:---"], directory)?;
    }
    setfacl(&["-m", "u:caddy:--x"], &layout.home_root)?;
    setfacl(&["-m", "u:caddy:--x"], home)?;
    if let Some(root) = root {
        for ancestor in &ancestors {
            setfacl(&["-m", "u:caddy:--x"], ancestor)?;
        }
        setfacl(&["-P", "-R", "-m", "u:caddy:r-X"], &root)?;
        let mut below = Vec::new();
        walk(&root, &mut |path, metadata| {
            if metadata.is_dir() {
                below.push(path.to_path_buf());
            }
        })?;
        for directory in &below {
            setfacl(&["-m", "d:u:caddy:r-x"], directory)?;
        }
    }
    Ok(Prepared {})
}

pub struct GrantCaddy;

impl Command for GrantCaddy {
    const NAME: &'static str = "instances.grant-production-caddy-access";
    const SUMMARY: &'static str =
        "Deny Caddy a production home except a path to the selected web root.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = CaddyInput;
    type Output = Prepared;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(input: &CaddyInput) -> Option<String> {
        Some(format!("production-user:{}", input.user))
    }

    fn run(input: CaddyInput, _: &Context) -> Result<Prepared, ReleaseError> {
        grant_caddy(&input, &Layout::system())
    }
}

// ---- the current selection -----------------------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LayoutInput {
    #[serde(flatten)]
    pub home: ReleaseHome,
}

/// The home, its releases, and `current` pass every check: `current` names a release whose application links the
/// stable `.env`, and whose web root stays inside it. Returns the selected release, if any.
fn check_layout(home: &ReleaseHome, layout: &Layout) -> Result<Option<String>, ReleaseError> {
    let (uid, gid) = account(&home.user)?;
    home.check(layout, true)?;
    check(
        is_real_directory(&home.home)
            && realpath(&home.home).as_deref() == Some(home.home.as_path()),
        "the home is canonical",
    )?;
    let environment = home.environment();
    check(
        is_regular_file(&environment)
            && fs::metadata(&environment).is_ok_and(|metadata| metadata.mode() & 0o7777 == 0o600),
        "the stable environment is private",
    )?;
    check(
        only_owned_by(&home.home, uid, gid)?,
        "only the user owns files in the home",
    )?;
    let current = home.home.join("current");
    if !exists(&current) {
        return Ok(None);
    }
    check(
        is_symlink(&current)
            && fs::symlink_metadata(&current)
                .is_ok_and(|metadata| metadata.uid() == uid && metadata.gid() == gid),
        "current is the user's link",
    )?;
    let name = home
        .selected()?
        .ok_or_else(|| invalid("current names a release"))?;
    let selected = home.release(&name);
    check(selected.is_dir(), "the selected release is a directory")?;
    let link = home.application(&selected).join(".env");
    check(
        fs::read_link(&link).is_ok_and(|target| target == Path::new(&home.environment_target()))
            && realpath(&link).as_deref() == Some(environment.as_path()),
        "the selected release links the stable environment",
    )?;
    let root = realpath_missing(&current.join(&home.relative_root));
    check(
        root.starts_with(&selected),
        "the web root is in the selected release",
    )?;
    Ok(Some(name))
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Selection {
    pub current: Option<String>,
}

pub fn validate_layout(input: &LayoutInput, layout: &Layout) -> Result<Selection, ReleaseError> {
    Ok(Selection {
        current: check_layout(&input.home, layout)?,
    })
}

/// Removes the `current` link after the same checks, so the home serves no release.
pub fn clear_current(input: &LayoutInput, layout: &Layout) -> Result<Selection, ReleaseError> {
    if check_layout(&input.home, layout)?.is_some() {
        let current = input.home.home.join("current");
        as_user(
            &input.home.user,
            "rm",
            &["--".as_ref(), current.as_os_str()],
            &none(),
        )
        .ok_or_else(|| failed("remove current"))?;
    }
    Ok(Selection { current: None })
}

pub struct ValidateLayout;

impl Command for ValidateLayout {
    const NAME: &'static str = "instances.validate-release-layout";
    const SUMMARY: &'static str =
        "Check a production home, its releases, and the release that current selects.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = LayoutInput;
    type Output = Selection;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: LayoutInput, _: &Context) -> Result<Selection, ReleaseError> {
        validate_layout(&input, &Layout::system())
    }
}

pub struct ClearCurrent;

impl Command for ClearCurrent {
    const NAME: &'static str = "instances.clear-current-release";
    const SUMMARY: &'static str = "Check a production home and remove its current link.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = LayoutInput;
    type Output = Selection;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &LayoutInput) -> Option<String> {
        Some(format!("release-home:{}", input.home.instance))
    }

    fn run(input: LayoutInput, _: &Context) -> Result<Selection, ReleaseError> {
        clear_current(&input, &Layout::system())
    }
}

// ---- the APP_URL of a served directory ---------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct DirectoryUrlInput {
    pub user: String,
    pub home: PathBuf,
    /// The application directory relative to a release, or empty for the release itself.
    pub directory: String,
    pub url: String,
    /// The `APP_KEY=` line a new file without a key takes.
    pub app_key: String,
    /// The `APP_NAME=` line a new file takes.
    pub app_name: String,
    /// Whether the name is the Instance's stored one, which replaces any template name.
    pub app_name_stored: bool,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Written {
    pub written: bool,
}

/// Writes `APP_URL` into the stable `.env` of another application directory, `<home>/env/<directory>/.env`, which
/// each release links to. It reads `artisan` and `.env.example` from the selected release, and does nothing before
/// the first release or for a directory without `artisan`. A new file gets its own key and name.
pub fn write_directory_url(
    input: &DirectoryUrlInput,
    layout: &Layout,
) -> Result<Written, ReleaseError> {
    let (user, home) = (input.user.as_str(), input.home.as_path());
    let (uid, _) = account(user)?;
    let unchanged = Ok(Written { written: false });
    check(
        home == layout.home_root.join(user),
        "the home is the user's",
    )?;
    check(
        realpath(home).as_deref() == Some(home),
        "the home is canonical",
    )?;
    check(
        input.directory.is_empty() || relative_path(&input.directory),
        "the directory is relative",
    )?;
    let current = home.join("current");
    if !is_symlink(&current) {
        return unchanged;
    }
    let release = realpath(&current).ok_or_else(|| invalid("current names a release"))?;
    check(
        release.parent() == Some(home.join("releases").as_path()),
        "current names a release",
    )?;
    let application = if input.directory.is_empty() {
        release.clone()
    } else {
        release.join(&input.directory)
    };
    check(
        realpath(&application).as_deref() == Some(application.as_path()),
        "the application directory is canonical",
    )?;
    let artisan = application.join("artisan");
    if is_symlink(&artisan) || !artisan.is_file() {
        return unchanged;
    }

    let mut stable = home.join("env");
    let parts: Vec<&str> = if input.directory.is_empty() {
        Vec::new()
    } else {
        input.directory.split('/').collect()
    };
    for (index, part) in std::iter::once("").chain(parts).enumerate() {
        if index > 0 {
            stable.push(part);
        }
        check(
            !is_symlink(&stable) && (!exists(&stable) || stable.is_dir()),
            "the stable environment directory is a real directory",
        )?;
        if !exists(&stable) {
            as_user(
                user,
                "mkdir",
                &[
                    "-m".as_ref(),
                    "0700".as_ref(),
                    "--".as_ref(),
                    stable.as_os_str(),
                ],
                &none(),
            )
            .ok_or_else(|| failed("create the stable environment directory"))?;
        }
        check(
            fs::symlink_metadata(&stable).is_ok_and(|metadata| metadata.uid() == uid),
            "the user owns the stable environment directory",
        )?;
    }
    let environment = stable.join(".env");
    check(
        !is_symlink(&environment) && (!exists(&environment) || environment.is_file()),
        "the stable environment is a regular file",
    )?;
    check(
        fs::symlink_metadata(&environment).map_or(true, |metadata| metadata.uid() == uid),
        "the user owns the stable environment",
    )?;
    let created = !exists(&environment);
    let (original, mode) = if created {
        let template = application.join(".env.example");
        check(
            !is_symlink(&template) && (!exists(&template) || template.is_file()),
            "the environment template is a regular file",
        )?;
        (fs::read(&template).unwrap_or_default(), 0o600)
    } else {
        let metadata = fs::metadata(&environment)
            .map_err(|_| invalid("the stable environment is readable"))?;
        (
            fs::read(&environment).map_err(|_| invalid("the stable environment is readable"))?,
            metadata.mode() & 0o770,
        )
    };
    let found = entries(&original, "APP_URL");
    check(found.len() <= 1, "the environment names APP_URL once")?;
    let mut updated = put(
        &original,
        &found,
        format!("APP_URL={}", input.url).as_bytes(),
    );
    // Laravel cannot boot with an empty key. A new file keeps the template's key or takes the given one; an existing
    // file only fills an empty key.
    let found = entries(&updated, "APP_KEY");
    if found.len() < 2
        && (created || !found.is_empty())
        && plain(&updated, &found, "APP_KEY").is_empty()
    {
        updated = put(&updated, &found, input.app_key.as_bytes());
    }
    let found = entries(&updated, "APP_NAME");
    let name = plain(&updated, &found, "APP_NAME");
    if created
        && found.len() < 2
        && (input.app_name_stored || name.is_empty() || name == b"Laravel")
    {
        updated = put(&updated, &found, input.app_name.as_bytes());
    }
    if updated != original || created {
        let candidate = stable.join(format!(".orbit-url-{:016x}", rand::random::<u64>()));
        let mode = format!("{mode:o}");
        let written = as_user_with_input(
            user,
            "sh",
            &[
                "-c".as_ref(),
                "umask 077 && cat > \"$1\" && chmod \"$2\" \"$1\" && mv -fT -- \"$1\" \"$3\""
                    .as_ref(),
                "sh".as_ref(),
                candidate.as_os_str(),
                mode.as_ref(),
                environment.as_os_str(),
            ],
            &updated,
        );
        if !written {
            let _ = fs::remove_file(&candidate);
            return Err(failed("write the stable environment"));
        }
        return Ok(Written { written: true });
    }
    if fs::metadata(&environment).is_ok_and(|metadata| metadata.mode() & 0o007 != 0) {
        as_user(
            user,
            "chmod",
            &[
                format!("{mode:o}").as_ref(),
                "--".as_ref(),
                environment.as_os_str(),
            ],
            &none(),
        )
        .ok_or_else(|| failed("restrict the stable environment"))?;
    }
    unchanged
}

pub struct WriteDirectoryUrl;

impl Command for WriteDirectoryUrl {
    const NAME: &'static str = "instances.write-directory-url";
    const SUMMARY: &'static str =
        "Write APP_URL into the stable .env of a production Instance's served application directory.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = DirectoryUrlInput;
    type Output = Written;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &DirectoryUrlInput) -> Option<String> {
        Some(format!("production-environment:{}", input.user))
    }

    fn run(input: DirectoryUrlInput, _: &Context) -> Result<Written, ReleaseError> {
        write_directory_url(&input, &Layout::system())
    }
}

// ---- the configuration cache --------------------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ConfigCacheInput {
    pub user: String,
    pub home: PathBuf,
    pub version: String,
    /// The effective application directory under `current`.
    pub application: PathBuf,
}

#[derive(Debug, Serialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum ConfigOutcome {
    ConfigCached,
    ConfigUncached,
    NoCurrentRelease,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct ConfigCache {
    pub outcome: ConfigOutcome,
}

/// Rebuilds the Laravel configuration cache of the current release as the production user, into a private temporary
/// file that replaces the cache only after Artisan boots against it. A release without a cache stays uncached. The
/// application's output never leaves the Node, because a failed build can print environment values.
pub fn refresh_config_cache(
    input: &ConfigCacheInput,
    php: &Path,
) -> Result<ConfigCache, ReleaseError> {
    let user = input.user.as_str();
    account(user)?;
    check(
        super::php_runtime::valid_version(&input.version),
        "the PHP version is valid",
    )?;
    let current = input.home.join("current");
    if !current.exists() {
        check(!is_symlink(&current), "current names a release")?;
        return Ok(ConfigCache {
            outcome: ConfigOutcome::NoCurrentRelease,
        });
    }
    check(current.is_dir(), "current names a release")?;
    if !input
        .application
        .join("bootstrap/cache/config.php")
        .is_file()
    {
        return Ok(ConfigCache {
            outcome: ConfigOutcome::ConfigUncached,
        });
    }
    const REBUILD: &str = r#"cd -- "$1"
cache="$PWD/bootstrap/cache/config.php"
temporary=$(mktemp -d "$PWD/bootstrap/cache/.orbit-config-XXXXXXXX")
trap 'rm -rf -- "$temporary"' EXIT
export APP_CONFIG_CACHE="$temporary/config.php"
"$2" artisan config:cache
[ -f "$APP_CONFIG_CACHE" ]
# Boot Artisan against the temporary cache using the application's own autoload context.
"$2" artisan --version --no-interaction
mv -f -- "$APP_CONFIG_CACHE" "$cache"
"#;
    let php = php.join(format!("php{}", input.version));
    as_user(
        user,
        "bash",
        &[
            "-eu".as_ref(),
            "-c".as_ref(),
            REBUILD.as_ref(),
            "--".as_ref(),
            input.application.as_os_str(),
            php.as_os_str(),
        ],
        &none(),
    )
    .ok_or_else(|| failed("rebuild the configuration cache"))?;
    Ok(ConfigCache {
        outcome: ConfigOutcome::ConfigCached,
    })
}

pub struct RefreshConfigCache;

impl Command for RefreshConfigCache {
    const NAME: &'static str = "instances.refresh-config-cache";
    const SUMMARY: &'static str =
        "Rebuild the Laravel configuration cache of a production Instance's current release, if it has one.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = ConfigCacheInput;
    type Output = ConfigCache;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &ConfigCacheInput) -> Option<String> {
        Some(format!("production-environment:{}", input.user))
    }

    fn run(input: ConfigCacheInput, _: &Context) -> Result<ConfigCache, ReleaseError> {
        refresh_config_cache(&input, Path::new("/usr/bin"))
    }
}

// ---- removal -------------------------------------------------------------------------------------------------------

/// Whether any process runs as the uid.
fn runs_processes(uid: u32, proc_root: &Path) -> bool {
    fs::read_dir(proc_root).is_ok_and(|entries| {
        entries.flatten().any(|entry| {
            entry
                .file_name()
                .to_string_lossy()
                .bytes()
                .all(|b| b.is_ascii_digit())
                && fs::metadata(entry.path()).is_ok_and(|metadata| metadata.uid() == uid)
        })
    })
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Removed {
    pub removed: bool,
}

/// Removes the home, the account, and the source state, once no process of the account runs.
pub fn remove(input: &Home, layout: &Layout) -> Result<Removed, ReleaseError> {
    let (user, home) = (input.user.as_str(), input.home.as_path());
    check(
        home == layout.home_root.join(user),
        "the home is the user's",
    )?;
    let state = input.state(layout);
    let remove_state = || {
        fs::remove_dir_all(&state).map_err(|_| failed("remove the source state"))?;
        check(!exists(&state), "the source state is gone")
    };
    if !exists(&state) {
        check(
            entry(user).is_none(),
            "no account exists without source state",
        )?;
        check(!exists(home), "no home exists without source state")?;
        return Ok(Removed { removed: false });
    }
    check(
        root_owned(&state, layout, 0o700, true),
        "the source state directory is root's",
    )?;
    if entry(user).is_none() && !exists(home) {
        remove_state()?;
        return Ok(Removed { removed: true });
    }
    check(
        input.marker_matches(
            &state.join("release-layout"),
            &input.layout_marker(),
            layout,
        ),
        "the layout marker names this home",
    )?;
    let found = entry(user);
    if let Some(found) = &found {
        check(entry_matches(found, user, home), "the account is Orbit's")?;
        check(
            !runs_processes(found.uid, Path::new("/proc")),
            "no process of the account runs",
        )?;
    }
    if exists(home) {
        check(
            is_real_directory(home) && realpath(home).as_deref() == Some(home),
            "the home is canonical",
        )?;
        if let Some(found) = &found {
            let (_, gid) = account(user)?;
            check(
                fs::metadata(home)
                    .is_ok_and(|metadata| metadata.uid() == found.uid && metadata.gid() == gid),
                "the user owns the home",
            )?;
        }
        fs::remove_dir_all(home).map_err(|_| failed("remove the home"))?;
    }
    if found.is_some() {
        check(
            run("userdel", &["--".as_ref(), OsStr::new(user)]),
            "the account is removed",
        )?;
    }
    check(entry(user).is_none(), "the account is gone")?;
    check(!exists(home), "the home is gone")?;
    remove_state()?;
    Ok(Removed { removed: true })
}

pub struct RemoveHome;

impl Command for RemoveHome {
    const NAME: &'static str = "instances.remove-production-home";
    const SUMMARY: &'static str =
        "Remove a production Instance's home, system account, and source state.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(600);
    type Input = Home;
    type Output = Removed;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(input: &Home) -> Option<String> {
        Some(format!("release-home:{}", input.instance))
    }

    fn run(input: Home, _: &Context) -> Result<Removed, ReleaseError> {
        remove(&input, &Layout::system())
    }
}

#[cfg(test)]
#[path = "production_home_tests.rs"]
mod tests;

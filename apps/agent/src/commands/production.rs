//! `instances.*-release`: the release layout of a production Instance. A home `/home/<user>` holds `releases/<name>`
//! checkouts, a `current` link to the selected one, and the stable `.env`. Each command checks the root-owned layout
//! marker first. It runs as root, and Git and every file it writes in the home run as the production user, so the
//! release stays the user's alone. These replace the Bash that `RemoteProductionDeployment` sent, check for check.

use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    collections::{BTreeMap, BTreeSet},
    ffi::OsStr,
    fs,
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

/// Where each production Instance's root-owned source state lives.
pub const STATE_ROOT: &str = "/var/lib/orbit/app-instance-sources";

command_errors! {
    pub enum ReleaseError {
        Invalid { check: String } => "instances.release_invalid", "The release home or the release does not pass its checks.";
        CommitNotOnBranch {} => "instances.commit_not_on_branch", "The branch does not contain the commit.";
        StepFailed { step: String } => "instances.release_step_failed", "A release step failed.";
    }
}

fn invalid(check: &str) -> ReleaseError {
    ReleaseError::Invalid {
        check: check.into(),
    }
}

fn check(condition: bool, what: &str) -> Result<(), ReleaseError> {
    if condition {
        Ok(())
    } else {
        Err(invalid(what))
    }
}

fn failed(step: &str) -> ReleaseError {
    ReleaseError::StepFailed { step: step.into() }
}

fn owner_only(platform: Platform) -> Option<Privilege> {
    (platform == Platform::Linux).then_some(Privilege::Owner)
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

fn is_link(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| metadata.file_type().is_symlink())
}

fn is_real_directory(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| metadata.is_dir())
}

fn realpath(path: &Path) -> Option<PathBuf> {
    fs::canonicalize(path).ok()
}

/// A Route web root that the Instance serves besides its own root, and the application directory under it.
#[derive(Deserialize, Clone)]
#[serde(deny_unknown_fields)]
pub struct ServedRoot {
    pub web_root: String,
    pub directory: String,
}

/// The production home of an Instance and what each release in it must be.
#[derive(Deserialize, Clone)]
#[serde(deny_unknown_fields)]
pub struct ReleaseHome {
    pub repository: String,
    pub user: String,
    pub home: PathBuf,
    pub instance: u64,
    /// The Instance web root, relative to a release.
    pub relative_root: String,
    /// The application directory under a release, such as `/server/web`, or empty for the release itself.
    #[serde(default)]
    pub application_suffix: String,
    #[serde(default)]
    pub served: Vec<ServedRoot>,
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
fn account(user: &str) -> Result<(u32, u32), ReleaseError> {
    identity::account(user)
        .map(|(uid, gid, _)| (uid, gid))
        .map_err(|_| invalid("the production user exists"))
}

/// Runs a program as the production user, with standard input closed. Its standard output, or `None` when it fails.
fn as_user(
    user: &str,
    program: &str,
    arguments: &[&OsStr],
    environment: &BTreeMap<String, String>,
) -> Option<Vec<u8>> {
    let mut command = Process::new(program);
    command
        .args(arguments)
        .envs(environment)
        .stdin(Stdio::null())
        .stderr(Stdio::null());
    identity::drop_to(&mut command, user).ok()?;
    let output = command.output().ok()?;
    output.status.success().then_some(output.stdout)
}

/// Git as the production user, without hooks or fsmonitor.
fn git(
    user: &str,
    release: &Path,
    arguments: &[&str],
    environment: &BTreeMap<String, String>,
) -> Option<String> {
    let mut all: Vec<&OsStr> = [
        "-c",
        "core.hooksPath=/dev/null",
        "-c",
        "core.fsmonitor=false",
        "-C",
    ]
    .map(OsStr::new)
    .to_vec();
    all.push(release.as_os_str());
    all.extend(arguments.iter().map(OsStr::new));
    as_user(user, "git", &all, environment).map(|output| {
        String::from_utf8_lossy(&output)
            .trim_end_matches('\n')
            .to_string()
    })
}

fn none() -> BTreeMap<String, String> {
    BTreeMap::new()
}

/// A path relative to a release: no leading slash, no `..`, not empty.
fn relative_path(path: &str) -> bool {
    !path.is_empty() && !path.starts_with('/') && path.split('/').all(|segment| segment != "..")
}

impl ReleaseHome {
    fn releases(&self) -> PathBuf {
        self.home.join("releases")
    }

    fn release(&self, name: &str) -> PathBuf {
        self.releases().join(name)
    }

    fn environment(&self) -> PathBuf {
        self.home.join(".env")
    }

    fn application(&self, release: &Path) -> PathBuf {
        PathBuf::from(format!("{}{}", release.display(), self.application_suffix))
    }

    /// The link target of a release's `.env`: back up to the home from the application directory.
    fn environment_target(&self) -> String {
        format!(
            "{}.env",
            "../".repeat(2 + self.application_suffix.matches('/').count())
        )
    }

    /// The home is `/home/<user>`, the web root is relative, and the root-owned marker names this repository, user,
    /// and home. With `releases`, the releases directory exists as a real directory.
    fn check(&self, layout: &Layout, releases: bool) -> Result<(), ReleaseError> {
        check(
            self.home == layout.home_root.join(&self.user),
            "the home is the user's",
        )?;
        check(
            relative_path(&self.relative_root),
            "the web root is relative",
        )?;
        check(
            self.application_suffix.is_empty()
                || (self.application_suffix.starts_with('/')
                    && relative_path(&self.application_suffix[1..])),
            "the application directory is relative",
        )?;
        let state = layout.state_root.join(self.instance.to_string());
        let marker = state.join("release-layout");
        let owned = |path: &Path, mode: u32, directory: bool| {
            fs::symlink_metadata(path).is_ok_and(|metadata| {
                metadata.is_dir() == directory
                    && (directory || metadata.is_file())
                    && metadata.uid() == layout.root_uid
                    && metadata.gid() == layout.root_uid
                    && metadata.mode() & 0o7777 == mode
            })
        };
        check(
            owned(&state, 0o700, true),
            "the source state directory is root's",
        )?;
        check(owned(&marker, 0o600, false), "the layout marker is root's")?;
        let expected = format!(
            "{}\0{}\0{}\0initial\0",
            self.repository,
            self.user,
            self.home.display()
        );
        check(
            fs::read(&marker).is_ok_and(|content| content == expected.as_bytes()),
            "the layout marker names this home",
        )?;
        if releases {
            check(
                is_real_directory(&self.releases()),
                "the releases directory is real",
            )?;
        }
        Ok(())
    }

    /// The release that `current` names, or `None` when there is no `current`.
    fn selected(&self) -> Result<Option<String>, ReleaseError> {
        let current = self.home.join("current");
        if fs::symlink_metadata(&current).is_err() {
            return Ok(None);
        }
        check(is_link(&current), "current is a link")?;
        let target = realpath(&current).ok_or_else(|| invalid("current names a release"))?;
        let name = target
            .strip_prefix(self.releases())
            .ok()
            .and_then(|name| name.to_str())
            .filter(|name| !name.contains('/') && valid_name(name))
            .ok_or_else(|| invalid("current names a release"))?;
        Ok(Some(name.to_string()))
    }

    /// The web root of a release: inside it, and a directory.
    fn web_root(&self, release: &Path) -> Result<PathBuf, ReleaseError> {
        let root = realpath(&release.join(&self.relative_root))
            .ok_or_else(|| invalid("the web root exists"))?;
        check(
            root.starts_with(release) && root.is_dir(),
            "the web root is a directory in the release",
        )?;
        Ok(root)
    }

    /// The commit of a release that passes every check: its Git directory, origin, `.env` link, web root, links,
    /// and that only the production user owns its files.
    fn inspect(&self, name: &str) -> Result<String, ReleaseError> {
        check(valid_name(name), "the release name is valid")?;
        let release = self.release(name);
        check(
            is_real_directory(&release.join(".git")),
            "the release has its Git directory",
        )?;
        check(
            !is_link(&release) && realpath(&release).as_deref() == Some(release.as_path()),
            "the release is canonical",
        )?;
        let origin = git(
            &self.user,
            &release,
            &["config", "--null", "--get", "remote.origin.url"],
            &none(),
        );
        check(
            origin.as_deref() == Some(format!("{}\0", self.repository).as_str()),
            "the release names the repository",
        )?;
        let environment = self.application(&release).join(".env");
        check(
            is_link(&environment)
                && realpath(&environment).as_deref() == Some(self.environment().as_path()),
            "the release links the stable environment",
        )?;
        let root = self.web_root(&release)?;
        self.check_web_root_links(&release, &root)?;
        self.only_user_files(&release)?;
        git(
            &self.user,
            &release,
            &["rev-parse", "--verify", "HEAD"],
            &none(),
        )
        .ok_or_else(|| invalid("the release has a commit"))
    }

    /// Every file in the release, on its filesystem, belongs to the production user and group.
    fn only_user_files(&self, release: &Path) -> Result<(), ReleaseError> {
        let (uid, gid) = account(&self.user)?;
        let device = fs::symlink_metadata(release)
            .map_err(|_| invalid("the release exists"))?
            .dev();
        let mut pending = vec![release.to_path_buf()];
        while let Some(path) = pending.pop() {
            let metadata =
                fs::symlink_metadata(&path).map_err(|_| invalid("the release can be read"))?;
            check(
                metadata.uid() == uid && metadata.gid() == gid,
                "only the production user owns the release",
            )?;
            if metadata.is_dir() && metadata.dev() == device {
                for entry in fs::read_dir(&path).map_err(|_| invalid("the release can be read"))? {
                    pending.push(
                        entry
                            .map_err(|_| invalid("the release can be read"))?
                            .path(),
                    );
                }
            }
        }
        Ok(())
    }

    /// Only the Laravel public storage link may leave a web root. Its target and every ancestor must resolve to the
    /// exact application path, locally or in the Instance home.
    fn public_storage_target(
        &self,
        release: &Path,
        web_root: &Path,
    ) -> Result<PathBuf, ReleaseError> {
        check(
            web_root.file_name() == Some(OsStr::new("public")),
            "a storage link sits in a public web root",
        )?;
        let application = web_root
            .parent()
            .ok_or_else(|| invalid("the web root has a parent"))?;
        let relative = application
            .strip_prefix(release)
            .map_err(|_| invalid("the web root is in the release"))?;
        let expected = self.home.join(relative).join("storage/app/public");
        let target = realpath(&web_root.join("storage"))
            .ok_or_else(|| invalid("the storage link resolves"))?;
        check(
            target == application.join("storage/app/public") || target == expected,
            "the storage link names the application's public storage",
        )?;
        check(target.is_dir(), "the public storage is a directory")?;
        Ok(target)
    }

    fn check_web_root_links(&self, release: &Path, web_root: &Path) -> Result<(), ReleaseError> {
        for link in links_below(web_root)? {
            check(
                link == web_root.join("storage"),
                "the only link in a web root is its public storage",
            )?;
            let target = self.public_storage_target(release, web_root)?;
            check(
                links_below(&target)?.is_empty(),
                "the public storage holds no link",
            )?;
        }
        Ok(())
    }

    /// Lets Caddy traverse to the web root and read it, as activation needs.
    fn grant_web_root(&self, release: &Path, web_root: &Path) -> Result<(), ReleaseError> {
        let mut ancestor = web_root.parent().map(Path::to_path_buf);
        while let Some(path) = ancestor.filter(|path| path != release) {
            check(
                path.starts_with(release) && is_real_directory(&path),
                "each web root ancestor is a real directory",
            )?;
            setfacl(&["-m", "u:caddy:--x"], &path)?;
            ancestor = path.parent().map(Path::to_path_buf);
        }
        setfacl(&["-m", "u:caddy:--x"], release)?;
        setfacl(&["-P", "-R", "-m", "u:caddy:r-X"], web_root)?;
        for directory in directories_below(web_root)? {
            setfacl(&["-m", "d:u:caddy:r-x"], &directory)?;
        }
        self.grant_public_storage(release, web_root)
    }

    fn grant_public_storage(&self, release: &Path, web_root: &Path) -> Result<(), ReleaseError> {
        if !is_link(&web_root.join("storage")) {
            return Ok(());
        }
        self.check_web_root_links(release, web_root)?;
        let target = self.public_storage_target(release, web_root)?;
        let mut ancestor = target.parent().map(Path::to_path_buf);
        while let Some(path) = ancestor.filter(|path| path != &self.home) {
            check(
                path.starts_with(&self.home),
                "the public storage is in the home",
            )?;
            setfacl(&["-m", "u:caddy:--x"], &path)?;
            ancestor = path.parent().map(Path::to_path_buf);
        }
        setfacl(&["-m", "u:caddy:--x"], &self.home)?;
        setfacl(&["-P", "-R", "-m", "u:caddy:r-X"], &target)?;
        for directory in directories_below(&target)? {
            setfacl(&["-m", "d:u:caddy:r-x"], &directory)?;
        }
        Ok(())
    }

    /// Links each served application's `.env` to `<home>/env/<directory>/.env`. The stable file may still be
    /// missing; the APP_URL writer creates it.
    fn link_served_environments(&self, release: &Path) -> Result<(), ReleaseError> {
        for served in &self.served {
            check(
                served_path(&served.web_root),
                "a served web root is a plain relative path",
            )?;
            let (application, stable, depth) = if served.directory.is_empty() {
                (release.to_path_buf(), "env/.env".to_string(), 2)
            } else {
                check(
                    served_path(&served.directory),
                    "a served directory is a plain relative path",
                )?;
                (
                    release.join(&served.directory),
                    format!("env/{}/.env", served.directory),
                    3 + served.directory.matches('/').count(),
                )
            };
            check(
                is_real_directory(&application)
                    && realpath(&application).as_deref() == Some(application.as_path()),
                "a served application directory is canonical",
            )?;
            let target = format!("{}{stable}", "../".repeat(depth));
            let environment = application.join(".env");
            if is_link(&environment) {
                check(
                    fs::read_link(&environment).is_ok_and(|actual| actual == Path::new(&target)),
                    "a served environment links the stable file",
                )?;
            } else {
                check(
                    fs::symlink_metadata(&environment).is_err(),
                    "a served environment is free for its link",
                )?;
                as_user(
                    &self.user,
                    "ln",
                    &[
                        OsStr::new("-s"),
                        OsStr::new("--"),
                        OsStr::new(&target),
                        environment.as_os_str(),
                    ],
                    &none(),
                )
                .ok_or_else(|| failed("link a served environment"))?;
            }
        }
        Ok(())
    }

    fn grant_served_web_roots(&self, release: &Path) -> Result<(), ReleaseError> {
        for served in &self.served {
            check(
                served_path(&served.web_root),
                "a served web root is a plain relative path",
            )?;
            let root = realpath(&release.join(&served.web_root))
                .ok_or_else(|| invalid("a served web root exists"))?;
            check(
                root.starts_with(release) && root != release && root.is_dir(),
                "a served web root is a directory in the release",
            )?;
            self.check_web_root_links(release, &root)?;
            self.grant_web_root(release, &root)?;
        }
        Ok(())
    }
}

/// A served path: plain segments of letters, digits, `.`, `_`, and `-`, and no `.` or `..` segment.
fn served_path(path: &str) -> bool {
    !path.is_empty()
        && path.split('/').all(|segment| {
            !segment.is_empty()
                && segment != "."
                && segment != ".."
                && segment
                    .bytes()
                    .all(|byte| byte.is_ascii_alphanumeric() || b"._-".contains(&byte))
        })
}

fn walk(root: &Path, keep: &mut dyn FnMut(&Path, &fs::Metadata)) -> Result<(), ReleaseError> {
    let mut pending = vec![root.to_path_buf()];
    while let Some(path) = pending.pop() {
        let metadata = fs::symlink_metadata(&path).map_err(|_| invalid("the tree can be read"))?;
        keep(&path, &metadata);
        if metadata.is_dir() {
            for entry in fs::read_dir(&path).map_err(|_| invalid("the tree can be read"))? {
                pending.push(entry.map_err(|_| invalid("the tree can be read"))?.path());
            }
        }
    }
    Ok(())
}

fn links_below(root: &Path) -> Result<Vec<PathBuf>, ReleaseError> {
    let mut links = Vec::new();
    walk(root, &mut |path, metadata| {
        if metadata.file_type().is_symlink() {
            links.push(path.to_path_buf());
        }
    })?;
    Ok(links)
}

fn directories_below(root: &Path) -> Result<Vec<PathBuf>, ReleaseError> {
    let mut directories = Vec::new();
    walk(root, &mut |path, metadata| {
        if metadata.is_dir() {
            directories.push(path.to_path_buf());
        }
    })?;
    Ok(directories)
}

fn setfacl(arguments: &[&str], path: &Path) -> Result<(), ReleaseError> {
    let status = Process::new("setfacl")
        .args(arguments)
        .arg("--")
        .arg(path)
        .stdin(Stdio::null())
        .stderr(Stdio::null())
        .status();
    check(
        status.is_ok_and(|status| status.success()),
        "Caddy's access can be granted",
    )
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Release {
    pub name: String,
    pub commit: String,
}

// ---- prepare ----------------------------------------------------------------------------------------------------

/// Clones a new release of the branch, or of a commit the branch contains, links its environment and shared
/// paths, and leaves `current` alone.
pub struct PrepareRelease;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PrepareInput {
    #[serde(flatten)]
    pub home: ReleaseHome,
    pub name: String,
    pub branch: String,
    #[serde(default)]
    pub commit: Option<String>,
    #[serde(default)]
    pub shared_paths: Vec<String>,
    #[serde(default)]
    pub git_read: BTreeMap<String, String>,
}

pub fn prepare(input: &PrepareInput, layout: &Layout) -> Result<Release, ReleaseError> {
    let home = &input.home;
    let user = home.user.as_str();
    check(valid_name(&input.name), "the release name is valid")?;
    home.check(layout, false)?;
    check(
        is_real_directory(&home.home)
            && realpath(&home.home).as_deref() == Some(home.home.as_path()),
        "the home is canonical",
    )?;
    check(
        is_real_directory(&home.releases()),
        "the releases directory is real",
    )?;
    check(
        fs::symlink_metadata(home.environment()).is_ok_and(|metadata| metadata.is_file()),
        "the stable environment is a file",
    )?;
    let release = home.release(&input.name);
    check(
        fs::symlink_metadata(&release).is_err(),
        "the release name is free",
    )?;
    if let Some(commit) = &input.commit {
        check(
            commit.len() >= 40 && commit.bytes().all(|byte| byte.is_ascii_hexdigit()),
            "the commit is a full hash",
        )?;
    }

    let mut clone: Vec<&OsStr> = [
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
    .to_vec();
    clone.extend([OsStr::new(&home.repository), release.as_os_str()]);
    as_user(user, "git", &clone, &input.git_read).ok_or_else(|| failed("clone the repository"))?;
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
    match &input.commit {
        None => git(user, &release, &["checkout", "--detach", &source], &none())
            .ok_or_else(|| failed("check out the branch"))?,
        Some(commit) => {
            git(
                user,
                &release,
                &["merge-base", "--is-ancestor", commit, &source],
                &none(),
            )
            .ok_or(ReleaseError::CommitNotOnBranch {})?;
            git(user, &release, &["checkout", "--detach", commit], &none())
                .ok_or_else(|| failed("check out the commit"))?
        }
    };
    let application = home.application(&release);
    check(
        realpath(&application).as_deref() == Some(application.as_path()),
        "the application directory is canonical",
    )?;
    let environment = application.join(".env");
    as_user(
        user,
        "ln",
        &[
            OsStr::new("-s"),
            OsStr::new(&home.environment_target()),
            environment.as_os_str(),
        ],
        &none(),
    )
    .ok_or_else(|| failed("link the environment"))?;
    check(
        realpath(&environment).as_deref() == Some(home.environment().as_path()),
        "the release links the stable environment",
    )?;
    link_shared_paths(home, &release, &input.shared_paths)?;
    home.link_served_environments(&release)?;
    let root = home.web_root(&release)?;
    home.check_web_root_links(&release, &root)?;
    home.only_user_files(&release)?;
    let commit = git(user, &release, &["rev-parse", "--verify", "HEAD"], &none())
        .ok_or_else(|| failed("read the commit"))?;
    Ok(Release {
        name: input.name.clone(),
        commit,
    })
}

/// Moves each shared path of the release to the home and links it back. A first release seeds an empty target with
/// its own copy, published at once. A Laravel application also gets its public storage link.
fn link_shared_paths(
    home: &ReleaseHome,
    release: &Path,
    paths: &[String],
) -> Result<(), ReleaseError> {
    let user = home.user.as_str();
    let run = |program: &str, arguments: &[&OsStr], step: &str| {
        as_user(user, program, arguments, &none()).ok_or_else(|| failed(step))
    };
    for path in paths.iter().filter(|path| !path.is_empty()) {
        check(served_path(path), "a shared path is a plain relative path")?;
        check(
            home.relative_root != *path && !home.relative_root.starts_with(&format!("{path}/")),
            "a shared path does not hold the web root",
        )?;
        let source = release.join(path);
        let target = home.home.join(path);
        check(
            no_link_on_the_way(release, &source) && no_link_on_the_way(&home.home, &target),
            "a shared path crosses no link",
        )?;
        if fs::symlink_metadata(&source).is_ok() {
            check(
                is_real_directory(&source),
                "a shared path in the release is a directory",
            )?;
        }
        run(
            "mkdir",
            &[OsStr::new("-p"), OsStr::new("--"), target.as_os_str()],
            "create a shared directory",
        )?;
        check(
            realpath(&target).as_deref() == Some(target.as_path()),
            "a shared directory is canonical",
        )?;
        let empty = fs::read_dir(&target)
            .map_err(|_| failed("read a shared directory"))?
            .next()
            .is_none();
        if empty && is_real_directory(&source) {
            check(
                links_below(&source)?.is_empty(),
                "a seeded shared path holds no link",
            )?;
            let seed = as_user(
                user,
                "mktemp",
                &[
                    OsStr::new("-d"),
                    OsStr::new(&format!("{}.seed.XXXXXXXX", target.display())),
                ],
                &none(),
            )
            .map(|output| PathBuf::from(String::from_utf8_lossy(&output).trim()))
            .ok_or_else(|| failed("create a seed"))?;
            let copied = run(
                "cp",
                &[
                    OsStr::new("-a"),
                    OsStr::new("--"),
                    source.join(".").as_os_str(),
                    seed.join("").as_os_str(),
                ],
                "seed a shared directory",
            )
            .and_then(|_| {
                run(
                    "mv",
                    &[
                        OsStr::new("-T"),
                        OsStr::new("--"),
                        seed.as_os_str(),
                        target.as_os_str(),
                    ],
                    "publish a seed",
                )
            });
            if copied.is_err() {
                let _ = run(
                    "rm",
                    &[OsStr::new("-rf"), OsStr::new("--"), seed.as_os_str()],
                    "remove a seed",
                );
                copied?;
            }
        }
        if let Some(parent) = source.parent() {
            run(
                "mkdir",
                &[OsStr::new("-p"), OsStr::new("--"), parent.as_os_str()],
                "create a shared path's parent",
            )?;
        }
        run(
            "rm",
            &[OsStr::new("-rf"), OsStr::new("--"), source.as_os_str()],
            "replace a shared path",
        )?;
        run(
            "ln",
            &[
                OsStr::new("-s"),
                OsStr::new("--"),
                target.as_os_str(),
                source.as_os_str(),
            ],
            "link a shared path",
        )?;
    }
    let application = home.application(release);
    let storage = application.join("storage");
    if is_link(&storage) && application.join("public").is_dir() {
        let relative = application.strip_prefix(release).unwrap_or(Path::new(""));
        let target = home.home.join(relative).join("storage/app/public");
        check(
            realpath(&storage.join("app/public")).is_none_or(|actual| actual == target),
            "public storage stays in the home",
        )?;
        run(
            "mkdir",
            &[OsStr::new("-p"), OsStr::new("--"), target.as_os_str()],
            "create the public storage",
        )?;
        let link = application.join("public/storage");
        if fs::symlink_metadata(&link).is_err() {
            run(
                "ln",
                &[
                    OsStr::new("-s"),
                    OsStr::new("--"),
                    OsStr::new("../storage/app/public"),
                    link.as_os_str(),
                ],
                "link the public storage",
            )?;
        }
    }
    Ok(())
}

/// No component below `base` up to `path` is a link.
fn no_link_on_the_way(base: &Path, path: &Path) -> bool {
    let Ok(relative) = path.strip_prefix(base) else {
        return false;
    };
    let mut current = base.to_path_buf();
    relative.components().all(|component| {
        current.push(component);
        !is_link(&current)
    })
}

impl Command for PrepareRelease {
    const NAME: &'static str = "instances.prepare-release";
    const SUMMARY: &'static str =
        "Clone a new production release of a branch and link its environment and shared paths.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = PrepareInput;
    type Output = Release;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        owner_only(platform)
    }

    fn target(input: &PrepareInput) -> Option<String> {
        Some(format!("release-home:{}", input.home.instance))
    }

    fn run(input: PrepareInput, _: &Context) -> Result<Release, ReleaseError> {
        prepare(&input, &Layout::system())
    }
}

// ---- activate ---------------------------------------------------------------------------------------------------

/// Checks a prepared release, grants Caddy its web roots, and points `current` at it in one rename.
pub struct ActivateRelease;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ActivateInput {
    #[serde(flatten)]
    pub home: ReleaseHome,
    pub name: String,
    pub commit: String,
}

pub fn activate(input: &ActivateInput, layout: &Layout) -> Result<Release, ReleaseError> {
    let home = &input.home;
    home.check(layout, false)?;
    let commit = home.inspect(&input.name)?;
    check(
        commit == input.commit,
        "the release is at the recorded commit",
    )?;
    let release = home.release(&input.name);
    let root = home.web_root(&release)?;
    home.grant_web_root(&release, &root)?;
    // Each Route with a web root needs its directory, .env link, and Caddy access in the selected release, also in
    // an older release that a rollback selects.
    home.link_served_environments(&release)?;
    home.grant_served_web_roots(&release)?;
    let temporary = home
        .home
        .join(format!(".current.{}.tmp", std::process::id()));
    let user = home.user.as_str();
    let link = as_user(
        user,
        "ln",
        &[
            OsStr::new("-s"),
            OsStr::new(&format!("releases/{}", input.name)),
            temporary.as_os_str(),
        ],
        &none(),
    )
    .and_then(|_| {
        as_user(
            user,
            "mv",
            &[
                OsStr::new("-Tf"),
                OsStr::new("--"),
                temporary.as_os_str(),
                home.home.join("current").as_os_str(),
            ],
            &none(),
        )
    });
    if link.is_none() {
        let _ = fs::remove_file(&temporary);
        return Err(failed("select the release"));
    }
    Ok(Release {
        name: input.name.clone(),
        commit,
    })
}

impl Command for ActivateRelease {
    const NAME: &'static str = "instances.activate-release";
    const SUMMARY: &'static str =
        "Check a production release, grant Caddy its web roots, and select it as current.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = ActivateInput;
    type Output = Release;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        owner_only(platform)
    }

    fn target(input: &ActivateInput) -> Option<String> {
        Some(format!("release-home:{}", input.home.instance))
    }

    fn run(input: ActivateInput, _: &Context) -> Result<Release, ReleaseError> {
        activate(&input, &Layout::system())
    }
}

// ---- inspect and list -------------------------------------------------------------------------------------------

/// Reads the selected release, or a named retained one, after every check.
pub struct InspectRelease;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectInput {
    #[serde(flatten)]
    pub home: ReleaseHome,
    /// The retained release to read, or `None` for the one `current` selects.
    #[serde(default)]
    pub name: Option<String>,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct InspectOutput {
    /// `None` when no release is selected.
    pub release: Option<Release>,
}

pub fn inspect(input: &InspectInput, layout: &Layout) -> Result<InspectOutput, ReleaseError> {
    let home = &input.home;
    home.check(layout, true)?;
    let name = match &input.name {
        Some(name) => name.clone(),
        None => match home.selected()? {
            Some(name) => name,
            None => return Ok(InspectOutput { release: None }),
        },
    };
    let commit = home.inspect(&name)?;
    Ok(InspectOutput {
        release: Some(Release { name, commit }),
    })
}

impl Command for InspectRelease {
    const NAME: &'static str = "instances.inspect-release";
    const SUMMARY: &'static str =
        "Check and read the selected production release, or a named retained one.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = InspectInput;
    type Output = InspectOutput;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        owner_only(platform)
    }

    fn run(input: InspectInput, _: &Context) -> Result<InspectOutput, ReleaseError> {
        inspect(&input, &Layout::system())
    }
}

/// Lists the releases that pass every check, and the selected one.
pub struct ListReleases;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ListInput {
    #[serde(flatten)]
    pub home: ReleaseHome,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct ListOutput {
    pub selected: Option<String>,
    pub releases: Vec<Release>,
}

/// The names of the release directories, sorted. A release that fails inspection is left out, never removed.
fn release_names(home: &ReleaseHome) -> Result<Vec<String>, ReleaseError> {
    let mut names: Vec<String> = fs::read_dir(home.releases())
        .map_err(|_| invalid("the releases directory can be read"))?
        .filter_map(|entry| entry.ok())
        .filter(|entry| entry.file_type().is_ok_and(|kind| kind.is_dir()))
        .filter_map(|entry| entry.file_name().into_string().ok())
        .collect();
    names.sort();
    Ok(names)
}

pub fn list(home: &ReleaseHome, layout: &Layout) -> Result<ListOutput, ReleaseError> {
    home.check(layout, true)?;
    let selected = home.selected()?;
    let releases = release_names(home)?
        .into_iter()
        .filter_map(|name| {
            home.inspect(&name)
                .ok()
                .map(|commit| Release { name, commit })
        })
        .collect();
    Ok(ListOutput { selected, releases })
}

impl Command for ListReleases {
    const NAME: &'static str = "instances.list-releases";
    const SUMMARY: &'static str =
        "List the production releases that pass their checks, and the selected one.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = ListInput;
    type Output = ListOutput;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        owner_only(platform)
    }

    fn run(input: ListInput, _: &Context) -> Result<ListOutput, ReleaseError> {
        list(&input.home, &Layout::system())
    }
}

// ---- prune ------------------------------------------------------------------------------------------------------

/// Removes releases beyond the retained count, never the selected one, the previous one, or one a process uses.
pub struct PruneReleases;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PruneInput {
    #[serde(flatten)]
    pub home: ReleaseHome,
    pub limit: usize,
    pub selected: String,
    #[serde(default)]
    pub previous: Option<String>,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct PruneOutput {
    pub removed: Vec<String>,
    pub in_use: Vec<String>,
}

/// The releases that a process of the user has as its working directory, root, or executable. `Err` when a live
/// process cannot be read, so nothing is removed.
fn busy_releases(home: &ReleaseHome, proc: &Path) -> Result<BTreeSet<String>, ReleaseError> {
    let (uid, _) = account(&home.user)?;
    let releases = home.releases();
    let mut busy = BTreeSet::new();
    for entry in fs::read_dir(proc).map_err(|_| invalid("the processes can be read"))? {
        let Ok(entry) = entry else { continue };
        let pid = entry.path();
        if !entry
            .file_name()
            .to_string_lossy()
            .bytes()
            .all(|byte| byte.is_ascii_digit())
        {
            continue;
        }
        if fs::metadata(&pid).is_ok_and(|metadata| metadata.uid() != uid) {
            continue;
        }
        for link in ["cwd", "root", "exe"] {
            match fs::read_link(pid.join(link)) {
                Ok(target) => {
                    if let Ok(used) = target.strip_prefix(&releases) {
                        if let Some(first) = used.components().next() {
                            busy.insert(first.as_os_str().to_string_lossy().into_owned());
                        }
                    }
                }
                Err(_) => {
                    // A process that ended in the meantime, or a zombie, has no paths to keep.
                    let status = fs::read_to_string(pid.join("status")).unwrap_or_default();
                    let zombie = status
                        .lines()
                        .any(|line| line.starts_with("State:") && line.contains('Z'));
                    if pid.exists() && !zombie {
                        return Err(invalid("every process of the user can be read"));
                    }
                }
            }
        }
    }
    Ok(busy)
}

pub fn prune(
    input: &PruneInput,
    layout: &Layout,
    proc: &Path,
) -> Result<PruneOutput, ReleaseError> {
    let home = &input.home;
    let user = home.user.as_str();
    home.check(layout, true)?;
    check(
        home.selected()?.as_deref() == Some(input.selected.as_str()),
        "current is the selected release",
    )?;
    let names: Vec<String> = release_names(home)?
        .into_iter()
        .filter(|name| home.inspect(name).is_ok())
        .collect();
    check(
        names.contains(&input.selected),
        "the selected release passes its checks",
    )?;
    let busy = busy_releases(home, proc)?;
    // The first release is `initial`. Every later name starts with its UTC creation time, so it sorts by age.
    let mut newest_first: Vec<&String> = names
        .iter()
        .rev()
        .filter(|name| *name != "initial")
        .collect();
    if names.iter().any(|name| name == "initial") {
        newest_first.push(
            names
                .iter()
                .find(|name| *name == "initial")
                .expect("initial is listed"),
        );
    }
    let mut keep: Vec<&str> = Vec::new();
    for name in [Some(&input.selected), input.previous.as_ref()]
        .into_iter()
        .flatten()
        .chain(newest_first)
    {
        if keep.len() >= input.limit {
            break;
        }
        if names.contains(name) && !keep.contains(&name.as_str()) {
            keep.push(name);
        }
    }
    let mut output = PruneOutput {
        removed: Vec::new(),
        in_use: Vec::new(),
    };
    let releases = home.releases();
    // A rename takes a release out of the set at once. A removal that stops leaves only a `.pruned-` folder, which no
    // listing or rollback accepts and the next prune removes.
    for name in names.iter().filter(|name| !keep.contains(&name.as_str())) {
        if busy.contains(name) {
            output.in_use.push(name.clone());
            continue;
        }
        let pruned = releases.join(format!(".pruned-{name}"));
        as_user(
            user,
            "mv",
            &[
                OsStr::new("-T"),
                OsStr::new("--"),
                releases.join(name).as_os_str(),
                pruned.as_os_str(),
            ],
            &none(),
        )
        .ok_or_else(|| failed("take a release out"))?;
        output.removed.push(name.clone());
    }
    // A folder that cannot be deleted now is deleted by a later prune, so its failure does not hide which releases
    // were removed.
    for entry in fs::read_dir(&releases)
        .map_err(|_| invalid("the releases directory can be read"))?
        .flatten()
    {
        let name = entry.file_name().to_string_lossy().into_owned();
        let Some(original) = name.strip_prefix(".pruned-") else {
            continue;
        };
        if busy.contains(original) || !entry.file_type().is_ok_and(|kind| kind.is_dir()) {
            continue;
        }
        let path = entry.path();
        // A folder without write permission, such as a read-only cache, would stop the removal halfway.
        if let Ok(directories) = directories_below(&path) {
            for directory in directories {
                if let Ok(metadata) = fs::symlink_metadata(&directory) {
                    let _ = fs::set_permissions(
                        &directory,
                        fs::Permissions::from_mode((metadata.mode() & 0o7777) | 0o200),
                    );
                }
            }
        }
        let _ = as_user(
            user,
            "rm",
            &[
                OsStr::new("-rf"),
                OsStr::new("--one-file-system"),
                OsStr::new("--"),
                path.as_os_str(),
            ],
            &none(),
        );
    }
    Ok(output)
}

impl Command for PruneReleases {
    const NAME: &'static str = "instances.prune-releases";
    const SUMMARY: &'static str =
        "Remove production releases beyond the retained count that no process uses.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = PruneInput;
    type Output = PruneOutput;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        owner_only(platform)
    }

    fn target(input: &PruneInput) -> Option<String> {
        Some(format!("release-home:{}", input.home.instance))
    }

    fn run(input: PruneInput, _: &Context) -> Result<PruneOutput, ReleaseError> {
        prune(&input, &Layout::system(), Path::new("/proc"))
    }
}

// ---- repository binding -----------------------------------------------------------------------------------------

/// Reads which repository URL each root-owned marker and each release records, for a Project URL change.
pub struct InspectRepositoryBinding;

#[derive(Deserialize, Clone)]
#[serde(deny_unknown_fields)]
pub struct BindingHome {
    pub user: String,
    pub home: PathBuf,
    pub instance: u64,
}

#[derive(Debug, Serialize, PartialEq)]
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

fn root_owned(path: &Path, layout: &Layout, mode: u32, directory: bool) -> bool {
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
            if is_link(&release) || !is_real_directory(&release.join(".git")) {
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
    const NAME: &'static str = "instances.inspect-repository-binding";
    const SUMMARY: &'static str =
        "Read the repository URL that each production marker and release records.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = BindingHome;
    type Output = BindingOutput;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        owner_only(platform)
    }

    fn run(input: BindingHome, _: &Context) -> Result<BindingOutput, ReleaseError> {
        inspect_binding(&input, &Layout::system())
    }
}

/// Moves each recorded URL from the expected one to the target one. A value already at the target is left alone, and
/// any other value is refused, so a repeated rebind converges and a concurrent change is never overwritten.
pub struct RebindRepository;

#[derive(Deserialize, Clone)]
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

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RebindInput {
    #[serde(flatten)]
    pub binding: BindingHome,
    /// Applied in order; the Gateway puts the release layout marker last, because deployment checks it first.
    pub changes: Vec<BindingChange>,
}

#[derive(Debug, Serialize, PartialEq)]
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
        !is_link(&release) && is_real_directory(&release.join(".git")),
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
    const NAME: &'static str = "instances.rebind-repository";
    const SUMMARY: &'static str = "Move the repository URL that production markers and releases record to the Project's new URL.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = RebindInput;
    type Output = RebindOutput;
    type Error = ReleaseError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        owner_only(platform)
    }

    fn target(input: &RebindInput) -> Option<String> {
        Some(format!("release-home:{}", input.binding.instance))
    }

    fn run(input: RebindInput, _: &Context) -> Result<RebindOutput, ReleaseError> {
        rebind(&input, &Layout::system())?;
        Ok(RebindOutput {})
    }
}

#[cfg(test)]
#[path = "production_tests.rs"]
mod tests;

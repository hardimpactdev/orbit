//! `roles.install-prerequisites`: a role's Ubuntu packages, and for application roles the managed user's `apps`
//! directory and the shared Composer home. The machine recipe installs their JavaScript runtimes. A healthy Docker CE
//! installation replaces `docker.io`. Every step is idempotent, and a path conflict refuses before a change.

use super::caddy::{chown, group_id};
use super::os_release;
use super::recipe::Files;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{DirBuilderExt, MetadataExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

const ORBIT_OPT: &str = "/opt/orbit";
const COMPOSER_HOME: &str = "/opt/orbit/composer";
/// apt waits this long for the dpkg locks, as every other agent package command does.
const DPKG_LOCK: &str = "DPkg::Lock::Timeout=300";

command_errors! {
    pub enum PrerequisiteError {
        Unsupported { id: String, codename: String } => "roles.os_unsupported", "The Node operating system is not supported.";
        Conflict { path: String } => "roles.prerequisite_conflict", "A path Orbit manages is not what Orbit made.";
        Failed { step: String, stderr: String } => "roles.prerequisite_failed", "A prerequisite step failed.";
    }
}

fn failed(step: &str, stderr: impl Into<String>) -> PrerequisiteError {
    let stderr: String = stderr.into();
    let start = stderr
        .char_indices()
        .rev()
        .nth(999)
        .map_or(0, |(index, _)| index);
    PrerequisiteError::Failed {
        step: step.into(),
        stderr: stderr[start..].into(),
    }
}

/// Names the host path, never the sandbox root a test uses.
fn conflict(files: &Files, path: &Path) -> PrerequisiteError {
    let logical = path.strip_prefix(&files.root).map_or_else(
        |_| path.to_path_buf(),
        |relative| Path::new("/").join(relative),
    );
    PrerequisiteError::Conflict {
        path: logical.display().to_string(),
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PrerequisiteInput {
    pub user: String,
    pub group: String,
    pub home: PathBuf,
    /// The Ubuntu codenames the role supports.
    pub releases: Vec<String>,
    pub packages: Vec<String>,
    /// Create `<home>/apps` for the managed user.
    pub apps_directory: bool,
    /// The shared Composer home of an application role. Its JavaScript runtimes come from the machine recipe.
    pub composer_home: bool,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Prerequisites {}

/// A finished program: whether it succeeded, and its output.
struct Ran {
    success: bool,
    stdout: String,
    stderr: String,
}

/// The host boundary: account lookup and fixed programs. Paths stay in [`Files`].
trait Host {
    fn account(&self, user: &str, group: &str) -> Option<(u32, u32)>;
    fn run(
        &mut self,
        program: &str,
        arguments: &[&str],
        environment: &[(&str, &str)],
    ) -> io::Result<Ran>;
}

struct Native;

impl Host for Native {
    fn account(&self, user: &str, group: &str) -> Option<(u32, u32)> {
        let uid = crate::command::identity::passwd(user)?.uid;
        Some((uid, group_id(group)?))
    }

    fn run(
        &mut self,
        program: &str,
        arguments: &[&str],
        environment: &[(&str, &str)],
    ) -> io::Result<Ran> {
        let output = Process::new(program)
            .args(arguments)
            .envs(environment.iter().copied())
            .stdin(Stdio::null())
            .output()?;
        Ok(Ran {
            success: output.status.success(),
            stdout: String::from_utf8_lossy(&output.stdout).into_owned(),
            stderr: String::from_utf8_lossy(&output.stderr).into_owned(),
        })
    }
}

struct Account {
    user: String,
    uid: u32,
    gid: u32,
    /// The host path of the managed user's home.
    home: PathBuf,
}

/// Runs a program and fails the step with its standard error.
fn run(
    host: &mut impl Host,
    step: &str,
    program: &str,
    arguments: &[&str],
    environment: &[(&str, &str)],
) -> Result<(), PrerequisiteError> {
    let ran = host
        .run(program, arguments, environment)
        .map_err(|error| failed(step, error.to_string()))?;
    if ran.success {
        Ok(())
    } else {
        Err(failed(step, ran.stderr))
    }
}

/// Runs a command as the managed user with its home, as `sudo -u <user> -H` does.
fn as_user(
    host: &mut impl Host,
    step: &str,
    account: &Account,
    arguments: &[&str],
) -> Result<(), PrerequisiteError> {
    let mut all = vec!["-u", account.user.as_str(), "-H"];
    all.extend_from_slice(arguments);
    run(host, step, "sudo", &all, &[])
}

/// One Docker CE prerequisite rule for convergence and Doctor.
pub(super) fn docker_ce_matches(
    docker: &Path,
    run: &mut impl FnMut(&str, &[&str]) -> io::Result<(bool, String)>,
) -> bool {
    ["docker-ce", "docker-ce-cli", "containerd.io"]
        .iter()
        .all(|package| {
            run("dpkg-query", &["-W", "-f=${Status}", "--", package])
                .is_ok_and(|(ok, status)| ok && status.trim() == "install ok installed")
        })
        && executable(docker)
        && run("systemctl", &["is-active", "--quiet", "docker"]).is_ok_and(|(ok, _)| ok)
}

fn docker_ce_healthy(files: &Files, host: &mut impl Host) -> bool {
    docker_ce_matches(&files.path("/usr/bin/docker"), &mut |program, args| {
        host.run(program, args, &[])
            .map(|ran| (ran.success, ran.stdout))
    })
}

fn executable(path: &Path) -> bool {
    fs::metadata(path).is_ok_and(|metadata| metadata.is_file() && metadata.mode() & 0o111 != 0)
}

fn present(path: &Path) -> Option<fs::Metadata> {
    fs::symlink_metadata(path).ok()
}

fn owned_by(metadata: &fs::Metadata, uid: u32, gid: u32) -> bool {
    metadata.uid() == uid && metadata.gid() == gid
}

/// A directory with this owner and mode, created when missing.
fn directory(path: &Path, uid: u32, gid: u32, step: &str) -> Result<(), PrerequisiteError> {
    match fs::DirBuilder::new().mode(0o755).create(path) {
        Ok(()) => {}
        Err(error) if error.kind() == io::ErrorKind::AlreadyExists => {}
        Err(error) => return Err(failed(step, error.to_string())),
    }
    chown(path, uid, gid)
        .and_then(|()| fs::set_permissions(path, fs::Permissions::from_mode(0o755)))
        .map_err(|error| failed(step, error.to_string()))
}

/// Refuses an existing path that is a symlink, no directory, or another owner's.
fn composer_home(
    files: &Files,
    host: &mut impl Host,
    account: &Account,
) -> Result<(), PrerequisiteError> {
    let opt = files.path(ORBIT_OPT);
    let home = files.path(COMPOSER_HOME);
    directory(&opt, files.uid, files.gid, "composer-home")?;
    directory(&home, account.uid, account.gid, "composer-home")?;
    let manifest = home.join("composer.json");
    let usable = |metadata: &fs::Metadata| {
        metadata.is_file() && owned_by(metadata, account.uid, account.gid)
    };
    match present(&manifest) {
        Some(metadata) if !usable(&metadata) => return Err(conflict(files, &manifest)),
        Some(_) => {}
        None => {
            let candidate = opt.join(format!(".composer.json.{:016x}", rand::random::<u64>()));
            let written = fs::write(&candidate, "{\"require\":{}}\n")
                .and_then(|()| fs::set_permissions(&candidate, fs::Permissions::from_mode(0o644)))
                .and_then(|()| chown(&candidate, account.uid, account.gid));
            let linked = written.and_then(|()| fs::hard_link(&candidate, &manifest));
            let _ = fs::remove_file(&candidate);
            if linked.is_err() && !present(&manifest).is_some_and(|metadata| usable(&metadata)) {
                return Err(conflict(files, &manifest));
            }
        }
    }
    let vendor = home.join("vendor");
    directory(&vendor, account.uid, account.gid, "composer-home")?;
    directory(
        &vendor.join("bin"),
        account.uid,
        account.gid,
        "composer-home",
    )?;
    as_user(
        host,
        "composer-home",
        account,
        &[
            "env",
            "COMPOSER_HOME=/opt/orbit/composer",
            "/usr/bin/composer",
            "--version",
            "--no-ansi",
        ],
    )
}

/// The Vite+ home: the first candidate that holds a `vp`, or that is anything but the managed user's empty directory.
/// Candidates and the result are host paths.
fn install(
    files: &Files,
    host: &mut impl Host,
    input: &PrerequisiteInput,
) -> Result<Prerequisites, PrerequisiteError> {
    let release = fs::read_to_string(files.path("/etc/os-release"))
        .ok()
        .and_then(|text| os_release::parse(&text).ok());
    match release {
        Some(release) if release.id == "ubuntu" && input.releases.contains(&release.codename) => {}
        Some(release) => {
            return Err(PrerequisiteError::Unsupported {
                id: release.id,
                codename: release.codename,
            })
        }
        None => {
            return Err(PrerequisiteError::Unsupported {
                id: String::new(),
                codename: String::new(),
            })
        }
    }
    let Some((uid, gid)) = host.account(&input.user, &input.group) else {
        return Err(failed("account", "the managed account does not exist"));
    };
    let account = Account {
        user: input.user.clone(),
        uid,
        gid,
        home: input.home.clone(),
    };

    let docker = docker_ce_healthy(files, host);
    let packages: Vec<&str> = input
        .packages
        .iter()
        .map(String::as_str)
        .filter(|package| !(docker && *package == "docker.io"))
        .collect();
    let noninteractive = [("DEBIAN_FRONTEND", "noninteractive")];
    run(
        host,
        "packages",
        "apt-get",
        &["-o", DPKG_LOCK, "update"],
        &noninteractive,
    )?;
    let mut install = vec![
        "-o",
        DPKG_LOCK,
        "install",
        "--yes",
        "--no-install-recommends",
        "--no-remove",
        "--",
    ];
    install.extend(packages);
    run(host, "packages", "apt-get", &install, &noninteractive)?;

    if input.apps_directory {
        directory(
            &files.at(&account.home.join("apps")),
            uid,
            gid,
            "apps-directory",
        )?;
    }
    if input.composer_home {
        composer_home(files, host, &account)?;
    }
    Ok(Prerequisites {})
}

pub struct InstallPrerequisites;

impl Command for InstallPrerequisites {
    const NAME: &'static str = "roles.install-prerequisites";
    const SUMMARY: &'static str =
        "Install a role's packages, and the Composer home and JavaScript runtime of an application role.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["packages"];
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = PrerequisiteInput;
    type Output = Prerequisites;
    type Error = PrerequisiteError;

    /// Packages and `/usr/local/bin` belong to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(_: &PrerequisiteInput) -> Option<String> {
        Some("roles:prerequisites".into())
    }

    fn run(input: PrerequisiteInput, _: &Context) -> Result<Prerequisites, PrerequisiteError> {
        install(&Files::system(), &mut Native, &input)
    }
}

#[cfg(test)]
#[path = "role_prerequisites_tests.rs"]
mod tests;

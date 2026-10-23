//! `roles.install-prerequisites`: a role's Ubuntu packages, and for application roles the managed user's `apps`
//! directory, the shared Composer home, and the JavaScript runtime launchers in `/usr/local/bin`. A healthy Docker CE
//! installation replaces `docker.io`. Every step is idempotent, and a launcher conflict refuses before a change.

use super::caddy::{chown, group_id};
use super::os_release;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{symlink, DirBuilderExt, MetadataExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

const ORBIT_OPT: &str = "/opt/orbit";
const BUN_HOME: &str = "/opt/orbit/bun";
const COMPOSER_HOME: &str = "/opt/orbit/composer";
const BIN: &str = "/usr/local/bin";
const LAUNCHERS: [&str; 5] = ["vp", "node", "pnpm", "npm", "npx"];

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

fn conflict(path: &Path) -> PrerequisiteError {
    PrerequisiteError::Conflict {
        path: path.display().to_string(),
    }
}

#[derive(Deserialize)]
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
    /// The shared Composer home and the Vite+, Node, and Bun launchers of an application role.
    pub application_runtime: bool,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Prerequisites {}

struct Account {
    user: String,
    uid: u32,
    gid: u32,
    home: PathBuf,
}

fn user_id(name: &str) -> Option<u32> {
    crate::command::identity::passwd(name).map(|entry| entry.uid)
}

/// Runs a program and fails the step with its standard error.
fn run(
    step: &str,
    program: &str,
    arguments: &[&str],
    environment: &[(&str, &str)],
) -> Result<(), PrerequisiteError> {
    let output = Process::new(program)
        .args(arguments)
        .envs(environment.iter().copied())
        .stdin(Stdio::null())
        .output()
        .map_err(|error| failed(step, error.to_string()))?;
    if output.status.success() {
        Ok(())
    } else {
        Err(failed(step, String::from_utf8_lossy(&output.stderr)))
    }
}

/// Runs a command as the managed user with its home, as `sudo -u <user> -H` does.
fn as_user(step: &str, account: &Account, arguments: &[&str]) -> Result<(), PrerequisiteError> {
    let mut all = vec!["-u", account.user.as_str(), "-H"];
    all.extend_from_slice(arguments);
    run(step, "sudo", &all, &[])
}

fn installed(package: &str) -> bool {
    Process::new("dpkg-query")
        .args(["-W", "-f=${Status}", package])
        .stderr(Stdio::null())
        .output()
        .is_ok_and(|output| output.stdout == b"install ok installed")
}

fn docker_ce_healthy() -> bool {
    ["docker-ce", "docker-ce-cli", "containerd.io"]
        .iter()
        .all(|package| installed(package))
        && executable(Path::new("/usr/bin/docker"))
        && Process::new("systemctl")
            .args(["is-active", "--quiet", "docker"])
            .status()
            .is_ok_and(|status| status.success())
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
fn owned_directory_or_absent(path: &Path, uid: u32, gid: u32) -> Result<(), PrerequisiteError> {
    match present(path) {
        Some(metadata) if !metadata.is_dir() || !owned_by(&metadata, uid, gid) => {
            Err(conflict(path))
        }
        _ => Ok(()),
    }
}

fn composer_home(account: &Account) -> Result<(), PrerequisiteError> {
    directory(Path::new(ORBIT_OPT), 0, 0, "composer-home")?;
    directory(
        Path::new(COMPOSER_HOME),
        account.uid,
        account.gid,
        "composer-home",
    )?;
    let manifest = Path::new(COMPOSER_HOME).join("composer.json");
    let usable = |metadata: &fs::Metadata| {
        metadata.is_file() && owned_by(metadata, account.uid, account.gid)
    };
    match present(&manifest) {
        Some(metadata) if !usable(&metadata) => return Err(conflict(&manifest)),
        Some(_) => {}
        None => {
            let candidate =
                Path::new(ORBIT_OPT).join(format!(".composer.json.{:016x}", rand::random::<u64>()));
            let written = fs::write(&candidate, "{\"require\":{}}\n")
                .and_then(|()| fs::set_permissions(&candidate, fs::Permissions::from_mode(0o644)))
                .and_then(|()| chown(&candidate, account.uid, account.gid));
            let linked = written.and_then(|()| fs::hard_link(&candidate, &manifest));
            let _ = fs::remove_file(&candidate);
            if linked.is_err() && !present(&manifest).is_some_and(|metadata| usable(&metadata)) {
                return Err(conflict(&manifest));
            }
        }
    }
    let vendor = Path::new(COMPOSER_HOME).join("vendor");
    directory(&vendor, account.uid, account.gid, "composer-home")?;
    directory(
        &vendor.join("bin"),
        account.uid,
        account.gid,
        "composer-home",
    )?;
    as_user(
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
fn vite_plus_home(
    candidates: &[PathBuf],
    uid: u32,
    gid: u32,
) -> Result<PathBuf, PrerequisiteError> {
    for candidate in candidates {
        let Some(metadata) = present(candidate) else {
            continue;
        };
        let holds_vp = present(&candidate.join("bin/vp")).is_some();
        if metadata.is_dir() && !holds_vp {
            if !owned_by(&metadata, uid, gid) {
                return Err(conflict(candidate));
            }
            continue;
        }
        return Ok(candidate.clone());
    }
    Ok(candidates.last().cloned().unwrap_or_default())
}

/// The launcher Orbit writes for a Vite+ binary.
fn launcher(vp_home: &Path, binary: &str) -> String {
    format!(
        "#!/bin/sh\nexport VP_HOME=\"{home}\"\nexec \"{home}/bin/{binary}\" \"$@\"\n",
        home = vp_home.display()
    )
}

/// The launcher earlier Orbit releases wrote, which an upgrade replaces.
fn legacy_launcher(vp_home: &Path, binary: &str) -> String {
    let environment = if vp_home == Path::new("/opt/orbit/vite-plus") {
        "export VP_HOME=/opt/orbit/vite-plus\n"
    } else {
        ""
    };
    format!(
        "#!/bin/sh\n{environment}exec \"{}/bin/{binary}\" \"$@\"\n",
        vp_home.display()
    )
}

/// Whether an existing launcher is one Orbit wrote: root's regular `0755` file with a known content.
fn launcher_ours(path: &Path, vp_home: &Path, binary: &str) -> bool {
    let Some(metadata) = present(path) else {
        return true;
    };
    let content = fs::read_to_string(path).unwrap_or_default();
    metadata.is_file()
        && owned_by(&metadata, 0, 0)
        && metadata.mode() & 0o7777 == 0o755
        && (content == launcher(vp_home, binary) || content == legacy_launcher(vp_home, binary))
}

/// A published change the runtime step undoes when a later check fails.
enum Published {
    Created(PathBuf),
    Replaced(PathBuf, Vec<u8>),
}

fn undo(published: &[Published]) {
    for change in published.iter().rev() {
        match change {
            Published::Created(path) => {
                let _ = fs::remove_file(path);
            }
            Published::Replaced(path, previous) => {
                let _ = fs::write(path, previous);
            }
        }
    }
}

fn write_launcher(path: &Path, content: &str) -> io::Result<()> {
    let candidate =
        path.with_file_name(format!(".orbit-js-runtime.{:016x}", rand::random::<u64>()));
    let result = fs::write(&candidate, content)
        .and_then(|()| fs::set_permissions(&candidate, fs::Permissions::from_mode(0o755)))
        .and_then(|()| chown(&candidate, 0, 0))
        .and_then(|()| fs::rename(&candidate, path));
    if result.is_err() {
        let _ = fs::remove_file(&candidate);
    }
    result
}

fn javascript_runtime(account: &Account) -> Result<(), PrerequisiteError> {
    let opt = Path::new(ORBIT_OPT);
    if present(opt).is_some_and(|metadata| !metadata.is_dir() || !owned_by(&metadata, 0, 0)) {
        return Err(conflict(opt));
    }
    owned_directory_or_absent(Path::new(BUN_HOME), account.uid, account.gid)?;
    directory(opt, 0, 0, "javascript-runtime")?;
    directory(
        Path::new(BUN_HOME),
        account.uid,
        account.gid,
        "javascript-runtime",
    )?;
    let ownership = format!("{}:{}", account.uid, account.gid);
    run(
        "javascript-runtime",
        "chown",
        &["-R", "--no-dereference", &ownership, BUN_HOME],
        &[],
    )?;

    let vp_home = vite_plus_home(
        &[
            PathBuf::from("/opt/orbit/vite-plus"),
            account.home.join(".vite-plus"),
            account.home.join(".local/share/vite-plus"),
        ],
        account.uid,
        account.gid,
    )?;
    if present(&vp_home).is_some_and(|metadata| !metadata.is_dir()) {
        return Err(conflict(&vp_home));
    }
    let vp_environment = format!("VP_HOME={}", vp_home.display());
    let vp = vp_home.join("bin/vp");
    let vp_binary = vp.display().to_string();
    if !executable(&vp) {
        as_user(
            "vite-plus",
            account,
            &[
                "env",
                "-u",
                "VP_HOME",
                "bash",
                "-o",
                "pipefail",
                "-c",
                "curl -fsSL https://vite.plus | bash",
            ],
        )?;
        for arguments in [
            &["env", "setup"][..],
            &["env", "on"],
            &["env", "install", "lts"],
            &["env", "default", "lts"],
            &["install", "-g", "--node", "lts", "pnpm"],
        ] {
            let mut all = vec!["env", vp_environment.as_str(), vp_binary.as_str()];
            all.extend_from_slice(arguments);
            as_user("vite-plus", account, &all)?;
        }
    }
    if !executable(&vp) || !executable(&vp_home.join("bin/pnpm")) {
        return Err(failed("vite-plus", "Vite+ did not install vp and pnpm"));
    }

    as_user(
        "bun",
        account,
        &[
            "env",
            "BUN_INSTALL=/opt/orbit/bun",
            "bash",
            "-o",
            "pipefail",
            "-c",
            "curl -fsSL https://bun.com/install | bash",
        ],
    )?;
    let bun = Path::new(BUN_HOME).join("bin/bun");
    if !executable(&bun) {
        return Err(failed("bun", "Bun did not install"));
    }
    run("bun", "chmod", &["-R", "a+rX", BUN_HOME], &[])?;

    for binary in LAUNCHERS {
        if !executable(&vp_home.join("bin").join(binary)) {
            return Err(failed(
                "javascript-runtime",
                format!("Vite+ has no {binary}"),
            ));
        }
        let path = Path::new(BIN).join(binary);
        if !launcher_ours(&path, &vp_home, binary) {
            return Err(conflict(&path));
        }
    }
    let bun_link = Path::new(BIN).join("bun");
    if let Some(metadata) = present(&bun_link) {
        if !metadata.file_type().is_symlink()
            || !owned_by(&metadata, 0, 0)
            || fs::read_link(&bun_link).ok().as_deref() != Some(bun.as_path())
        {
            return Err(conflict(&bun_link));
        }
    }

    let mut published = Vec::new();
    let result = publish_launchers(&vp_home, &bun, &bun_link, &mut published)
        .and_then(|()| verify_runtime(account));
    if result.is_err() {
        undo(&published);
    }
    result
}

fn publish_launchers(
    vp_home: &Path,
    bun: &Path,
    bun_link: &Path,
    published: &mut Vec<Published>,
) -> Result<(), PrerequisiteError> {
    for binary in LAUNCHERS {
        let path = Path::new(BIN).join(binary);
        let content = launcher(vp_home, binary);
        let previous = fs::read(&path).ok();
        if previous.as_deref() == Some(content.as_bytes()) {
            continue;
        }
        write_launcher(&path, &content)
            .map_err(|error| failed("javascript-runtime", error.to_string()))?;
        published.push(match previous {
            Some(previous) => Published::Replaced(path, previous),
            None => Published::Created(path),
        });
    }
    if present(bun_link).is_none() {
        symlink(bun, bun_link).map_err(|error| failed("javascript-runtime", error.to_string()))?;
        published.push(Published::Created(bun_link.to_path_buf()));
    }
    Ok(())
}

fn verify_runtime(account: &Account) -> Result<(), PrerequisiteError> {
    for binary in LAUNCHERS {
        as_user(
            "javascript-runtime",
            account,
            &[&format!("{BIN}/{binary}"), "--version"],
        )?;
    }
    as_user(
        "javascript-runtime",
        account,
        &[
            "env",
            "BUN_INSTALL=/opt/orbit/bun",
            "/usr/local/bin/bun",
            "--version",
        ],
    )
}

pub fn install(input: &PrerequisiteInput) -> Result<Prerequisites, PrerequisiteError> {
    let release = fs::read_to_string("/etc/os-release")
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
    let (Some(uid), Some(gid)) = (user_id(&input.user), group_id(&input.group)) else {
        return Err(failed("account", "the managed account does not exist"));
    };
    let account = Account {
        user: input.user.clone(),
        uid,
        gid,
        home: input.home.clone(),
    };

    let docker = docker_ce_healthy();
    let packages: Vec<&str> = input
        .packages
        .iter()
        .map(String::as_str)
        .filter(|package| !(docker && *package == "docker.io"))
        .collect();
    let noninteractive = [("DEBIAN_FRONTEND", "noninteractive")];
    run("packages", "apt-get", &["update"], &noninteractive)?;
    let mut install = vec![
        "install",
        "--yes",
        "--no-install-recommends",
        "--no-remove",
        "--",
    ];
    install.extend(packages);
    run("packages", "apt-get", &install, &noninteractive)?;

    if input.apps_directory {
        directory(&account.home.join("apps"), uid, gid, "apps-directory")?;
    }
    if input.application_runtime {
        composer_home(&account)?;
        javascript_runtime(&account)?;
    }
    Ok(Prerequisites {})
}

pub struct InstallPrerequisites;

impl Command for InstallPrerequisites {
    const NAME: &'static str = "roles.install-prerequisites";
    const SUMMARY: &'static str =
        "Install a role's packages, and the Composer home and JavaScript runtime of an application role.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = PrerequisiteInput;
    type Output = Prerequisites;
    type Error = PrerequisiteError;

    /// Packages and `/usr/local/bin` belong to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: PrerequisiteInput, _: &Context) -> Result<Prerequisites, PrerequisiteError> {
        install(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn scratch() -> PathBuf {
        let path = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!(
                "orbit-prerequisites-{:016x}",
                rand::random::<u64>()
            ));
        fs::create_dir_all(&path).unwrap();
        path
    }

    #[test]
    fn launchers_name_their_vite_plus_home() {
        let home = Path::new("/home/orbit/.local/share/vite-plus");
        assert_eq!(
            launcher(home, "node"),
            "#!/bin/sh\nexport VP_HOME=\"/home/orbit/.local/share/vite-plus\"\nexec \"/home/orbit/.local/share/vite-plus/bin/node\" \"$@\"\n"
        );
        assert_eq!(
            legacy_launcher(home, "node"),
            "#!/bin/sh\nexec \"/home/orbit/.local/share/vite-plus/bin/node\" \"$@\"\n"
        );
        assert!(legacy_launcher(Path::new("/opt/orbit/vite-plus"), "vp")
            .contains("export VP_HOME=/opt/orbit/vite-plus\n"));
    }

    #[test]
    fn the_vite_plus_home_is_the_first_candidate_that_holds_vp() {
        let base = scratch();
        let (uid, gid) = (
            rustix::process::getuid().as_raw(),
            rustix::process::getgid().as_raw(),
        );
        let candidates = [base.join("a"), base.join("b"), base.join("c")];

        assert_eq!(vite_plus_home(&candidates, uid, gid), Ok(base.join("c")));
        fs::create_dir_all(base.join("a")).unwrap();
        fs::create_dir_all(base.join("b/bin")).unwrap();
        fs::write(base.join("b/bin/vp"), "").unwrap();
        assert_eq!(vite_plus_home(&candidates, uid, gid), Ok(base.join("b")));
        assert!(vite_plus_home(&candidates, uid + 1, gid).is_err());
        let _ = fs::remove_dir_all(&base);
    }

    #[test]
    fn only_a_launcher_orbit_wrote_counts_as_ours() {
        let base = scratch();
        let path = base.join("node");
        let home = Path::new("/opt/orbit/vite-plus");

        assert!(launcher_ours(&path, home, "node"));
        fs::write(&path, "#!/bin/sh\necho mine\n").unwrap();
        assert!(!launcher_ours(&path, home, "node"));
        let _ = fs::remove_dir_all(&base);
    }

    #[test]
    fn undo_removes_created_files_and_restores_replaced_ones() {
        let base = scratch();
        fs::write(base.join("kept"), "new").unwrap();
        fs::write(base.join("created"), "new").unwrap();

        undo(&[
            Published::Replaced(base.join("kept"), b"old".to_vec()),
            Published::Created(base.join("created")),
        ]);

        assert_eq!(fs::read_to_string(base.join("kept")).unwrap(), "old");
        assert!(!base.join("created").exists());
        let _ = fs::remove_dir_all(&base);
    }
}

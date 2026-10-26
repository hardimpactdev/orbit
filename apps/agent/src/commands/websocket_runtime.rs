//! `websocket.converge-runtime`, `websocket.remove-runtime`, and `websocket.runtime-active`: the Reverb runtime of the
//! websocket role. Converge clones the pinned ref into a new release as the managed user, installs its Composer
//! dependencies, writes its `.env`, and checks the release and the unit before it stops the running service. Only
//! then does it switch the `current` link, publish the unit, restart, and wait for the listener. It keeps the new and
//! the previous release, and removes the others. One install lock serializes converges.

use super::caddy::chown;
use super::recipe::Files;
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    ffi::OsStr,
    fs,
    io::Write,
    net::{SocketAddr, TcpStream},
    os::unix::fs::{symlink, DirBuilderExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::{Duration, Instant},
};

const SERVICE: &str = "orbit-websocket";
const UNIT: &str = "/etc/systemd/system/orbit-websocket.service";

command_errors! {
    pub enum WebSocketError {
        Invalid { check: String } => "websocket.runtime_invalid", "The runtime request does not pass its checks.";
        Failed { step: String, stderr: String } => "websocket.runtime_failed", "A runtime step failed.";
        NotListening { port: u16 } => "websocket.runtime_not_listening", "Reverb did not open its listener within 30 seconds.";
    }
}

fn failed(step: &str, stderr: impl Into<String>) -> WebSocketError {
    let stderr: String = stderr.into();
    let start = stderr
        .char_indices()
        .rev()
        .nth(999)
        .map_or(0, |(index, _)| index);
    WebSocketError::Failed {
        step: step.into(),
        stderr: stderr[start..].into(),
    }
}

/// Why a program did not succeed.
enum Refused {
    /// The program could not run as the managed user.
    Account(String),
    /// The program failed, with its standard error.
    Failed(String),
}

/// The host boundary: account lookup, fixed programs, systemd, and the listener probe. Paths stay in [`Files`].
trait Host {
    fn account(&self, user: &str, group: &str) -> Option<(u32, u32)>;
    /// Runs a fixed program, as `user` when one is named.
    fn run(
        &mut self,
        user: Option<&str>,
        program: &str,
        arguments: &[&OsStr],
        environment: &[(&str, &str)],
    ) -> Result<(), Refused>;
    fn systemctl(&mut self, arguments: &[&str]) -> bool;
    /// Waits up to 30 seconds for a TCP listener on the loopback port.
    fn listening(&mut self, port: u16) -> bool;
}

struct Native;

impl Host for Native {
    fn account(&self, user: &str, group: &str) -> Option<(u32, u32)> {
        Some((identity::passwd(user)?.uid, identity::group_id(group)?))
    }

    fn run(
        &mut self,
        user: Option<&str>,
        program: &str,
        arguments: &[&OsStr],
        environment: &[(&str, &str)],
    ) -> Result<(), Refused> {
        let mut command = Process::new(program);
        if let Some(user) = user {
            identity::drop_to(&mut command, user).map_err(Refused::Account)?;
        }
        let output = command
            .args(arguments)
            .envs(environment.iter().copied())
            .stdin(Stdio::null())
            .output()
            .map_err(|error| Refused::Failed(error.to_string()))?;
        if output.status.success() {
            Ok(())
        } else {
            Err(Refused::Failed(
                String::from_utf8_lossy(&output.stderr).into_owned(),
            ))
        }
    }

    fn systemctl(&mut self, arguments: &[&str]) -> bool {
        Process::new("systemctl")
            .args(arguments)
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .is_ok_and(|status| status.success())
    }

    fn listening(&mut self, port: u16) -> bool {
        listening(port, Duration::from_secs(30))
    }
}

fn run(
    host: &mut impl Host,
    step: &str,
    user: Option<&str>,
    program: &str,
    arguments: &[&OsStr],
    environment: &[(&str, &str)],
) -> Result<(), WebSocketError> {
    host.run(user, program, arguments, environment)
        .map_err(|refused| match refused {
            Refused::Account(reason) => failed("account", reason),
            Refused::Failed(stderr) => failed(step, stderr),
        })
}

fn random_name(prefix: &str) -> String {
    format!("{prefix}.{:016x}", rand::random::<u64>())
}

/// Writes a file with one rename, creating the candidate with its final mode.
fn write_file(path: &Path, content: &[u8], mode: u32) -> std::io::Result<()> {
    let candidate = path.with_file_name(random_name(".orbit-candidate"));
    let written = fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(mode)
        .open(&candidate)
        .and_then(|mut file| file.write_all(content))
        .and_then(|()| fs::rename(&candidate, path));
    if written.is_err() {
        let _ = fs::remove_file(&candidate);
    }
    written
}

/// Waits up to `wait` for a TCP listener on the loopback port.
fn listening(port: u16, wait: Duration) -> bool {
    let end = Instant::now() + wait;
    let address = SocketAddr::from(([127, 0, 0, 1], port));
    loop {
        if TcpStream::connect_timeout(&address, Duration::from_secs(1)).is_ok() {
            return true;
        }
        if Instant::now() >= end {
            return false;
        }
        std::thread::sleep(Duration::from_millis(500));
    }
}

/// Removes every release and kept previous install except the two named.
fn prune(releases: &Path, keep: &[&Path]) {
    for entry in fs::read_dir(releases).into_iter().flatten().flatten() {
        let path = entry.path();
        let name = entry.file_name().to_string_lossy().into_owned();
        if (name.starts_with("release.") || name.starts_with("previous."))
            && !keep.contains(&path.as_path())
        {
            let _ = fs::remove_dir_all(&path);
        }
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ConvergeInput {
    pub user: String,
    pub group: String,
    pub install_path: PathBuf,
    pub repository: String,
    #[serde(rename = "ref")]
    pub reference: String,
    pub port: u16,
    /// The release's `.env`, which holds the app secrets.
    pub environment: String,
    pub unit: String,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Converged {
    pub release: String,
}

/// Prepares and checks a release; the caller removes it when anything fails.
fn prepare(
    host: &mut impl Host,
    input: &ConvergeInput,
    candidate: &Path,
    verify: &Path,
    uid: u32,
    gid: u32,
) -> Result<(), WebSocketError> {
    let user = Some(input.user.as_str());
    let release = candidate.as_os_str();
    fs::DirBuilder::new()
        .mode(0o755)
        .create(candidate)
        .and_then(|()| chown(candidate, uid, gid))
        .map_err(|error| failed("release", error.to_string()))?;
    run(
        host,
        "clone",
        user,
        "git",
        &[
            "clone".as_ref(),
            "--quiet".as_ref(),
            "--no-checkout".as_ref(),
            "--".as_ref(),
            input.repository.as_ref(),
            release,
        ],
        &[],
    )?;
    run(
        host,
        "fetch",
        user,
        "git",
        &[
            "-C".as_ref(),
            release,
            "fetch".as_ref(),
            "--quiet".as_ref(),
            "origin".as_ref(),
            input.reference.as_ref(),
        ],
        &[],
    )?;
    run(
        host,
        "checkout",
        user,
        "git",
        &[
            "-C".as_ref(),
            release,
            "checkout".as_ref(),
            "--quiet".as_ref(),
            "--detach".as_ref(),
            "FETCH_HEAD".as_ref(),
        ],
        &[],
    )?;
    let working_directory = format!("--working-dir={}", candidate.display());
    run(
        host,
        "composer",
        user,
        "composer",
        &[
            "install".as_ref(),
            "--no-dev".as_ref(),
            "--optimize-autoloader".as_ref(),
            "--no-interaction".as_ref(),
            "--no-progress".as_ref(),
            working_directory.as_ref(),
        ],
        &[("COMPOSER_ALLOW_SUPERUSER", "1")],
    )?;
    let environment = candidate.join(".env");
    write_file(&environment, input.environment.as_bytes(), 0o600)
        .and_then(|()| chown(&environment, uid, gid))
        .map_err(|error| failed("environment", error.to_string()))?;
    let artisan = candidate.join("artisan");
    run(
        host,
        "artisan",
        user,
        "/usr/bin/php",
        &[
            artisan.as_os_str(),
            "orbit:reverb:start".as_ref(),
            "--help".as_ref(),
        ],
        &[],
    )?;
    let unit = verify.join(format!("{SERVICE}.service"));
    fs::write(&unit, &input.unit).map_err(|error| failed("unit", error.to_string()))?;
    run(
        host,
        "unit",
        None,
        "systemd-analyze",
        &["verify".as_ref(), unit.as_os_str()],
        &[],
    )
}

fn converge(
    files: &Files,
    host: &mut impl Host,
    input: &ConvergeInput,
) -> Result<Converged, WebSocketError> {
    if !input.install_path.is_absolute() || input.install_path.parent().is_none() {
        return Err(WebSocketError::Invalid {
            check: "the install path is absolute".into(),
        });
    }
    let Some((uid, gid)) = host.account(&input.user, &input.group) else {
        return Err(WebSocketError::Invalid {
            check: "the managed account exists".into(),
        });
    };
    let install = &files.at(&input.install_path);
    let parent = install.parent().expect("an absolute path below the root");
    fs::DirBuilder::new()
        .recursive(true)
        .mode(0o755)
        .create(parent)
        .map_err(|error| failed("parent", error.to_string()))?;
    let lock = fs::OpenOptions::new()
        .create(true)
        .truncate(false)
        .write(true)
        .mode(0o600)
        .open(format!("{}.install.lock", install.display()))
        .map_err(|error| failed("lock", error.to_string()))?;
    rustix::fs::flock(&lock, rustix::fs::FlockOperation::LockExclusive)
        .map_err(|error| failed("lock", error.to_string()))?;
    let releases = PathBuf::from(format!("{}.releases", install.display()));
    fs::DirBuilder::new()
        .mode(0o755)
        .create(&releases)
        .or_else(|error| {
            if error.kind() == std::io::ErrorKind::AlreadyExists {
                Ok(())
            } else {
                Err(error)
            }
        })
        .and_then(|()| chown(&releases, uid, gid))
        .map_err(|error| failed("releases", error.to_string()))?;
    let candidate = releases.join(random_name("release"));
    let verify = std::env::temp_dir().join(random_name("orbit-websocket-verify"));
    fs::DirBuilder::new()
        .mode(0o700)
        .create(&verify)
        .map_err(|error| failed("verify", error.to_string()))?;

    let prepared = prepare(host, input, &candidate, &verify, uid, gid);
    let _ = fs::remove_dir_all(&verify);
    if let Err(error) = prepared {
        let _ = fs::remove_dir_all(&candidate);
        return Err(error);
    }

    // Every check passed, so only now does the running service stop and its code change.
    if host.systemctl(&["cat", &format!("{SERVICE}.service")])
        && !host.systemctl(&["stop", SERVICE])
    {
        let _ = fs::remove_dir_all(&candidate);
        return Err(failed("stop", "the running service did not stop"));
    }
    let metadata = fs::symlink_metadata(install).ok();
    let previous = match &metadata {
        // The link names its release as `releases` does, so `prune` keeps it even below a symlinked parent.
        Some(metadata) if metadata.file_type().is_symlink() => fs::read_link(install)
            .ok()
            .map(|target| parent.join(target)),
        Some(metadata) if metadata.is_dir() => {
            let previous = releases.join(random_name("previous"));
            fs::rename(install, &previous)
                .map_err(|error| failed("previous", error.to_string()))?;
            Some(previous)
        }
        _ => None,
    };
    let link = install.with_file_name(random_name(".orbit-current"));
    symlink(&candidate, &link)
        .and_then(|()| fs::rename(&link, install))
        .map_err(|error| failed("activate", error.to_string()))?;
    let unit = files.path(UNIT);
    write_file(&unit, input.unit.as_bytes(), 0o644)
        .and_then(|()| fs::set_permissions(&unit, fs::Permissions::from_mode(0o644)))
        .map_err(|error| failed("unit", error.to_string()))?;
    if !host.systemctl(&["daemon-reload"])
        || !host.systemctl(&["enable", "--quiet", SERVICE])
        || !host.systemctl(&["restart", SERVICE])
    {
        return Err(failed("restart", "systemd did not start the service"));
    }
    if !host.listening(input.port) {
        return Err(WebSocketError::NotListening { port: input.port });
    }
    let mut keep = vec![candidate.as_path()];
    keep.extend(previous.as_deref());
    prune(&releases, &keep);
    Ok(Converged {
        release: candidate.display().to_string(),
    })
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RemoveInput {
    pub install_path: PathBuf,
    /// Also delete the install and its releases.
    pub purge: bool,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Removed {}

fn remove(
    files: &Files,
    host: &mut impl Host,
    input: &RemoveInput,
) -> Result<Removed, WebSocketError> {
    if !input.install_path.is_absolute() {
        return Err(WebSocketError::Invalid {
            check: "the install path is absolute".into(),
        });
    }
    if host.systemctl(&["list-unit-files", "--", &format!("{SERVICE}.service")])
        && !host.systemctl(&["disable", "--now", "--", SERVICE])
    {
        return Err(failed("disable", "the service did not stop"));
    }
    let unit = files.path(UNIT);
    if fs::remove_file(&unit).is_err() && unit.exists() {
        return Err(failed("unit", "the unit file stayed"));
    }
    host.systemctl(&["daemon-reload"]);
    host.systemctl(&["reset-failed", "--", &format!("{SERVICE}.service")]);
    if input.purge {
        let install = files.at(&input.install_path);
        for path in [
            install.clone(),
            PathBuf::from(format!("{}.releases", install.display())),
        ] {
            match fs::symlink_metadata(&path) {
                Ok(metadata) if metadata.is_dir() => fs::remove_dir_all(&path),
                Ok(_) => fs::remove_file(&path),
                Err(_) => Ok(()),
            }
            .map_err(|error| failed("purge", error.to_string()))?;
        }
    }
    Ok(Removed {})
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ActiveInput {}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Active {
    pub active: bool,
}

pub struct ConvergeRuntime;

impl Command for ConvergeRuntime {
    const NAME: &'static str = "websocket.converge-runtime";
    const SUMMARY: &'static str =
        "Install the pinned Reverb release, switch to it, and restart the websocket service.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = ConvergeInput;
    type Output = Converged;
    type Error = WebSocketError;

    /// The unit and the release switch need root; the release itself is built as the managed user.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(_: &ConvergeInput) -> Option<String> {
        Some("websocket-runtime".into())
    }

    fn run(input: ConvergeInput, _: &Context) -> Result<Converged, WebSocketError> {
        converge(&Files::system(), &mut Native, &input)
    }
}

pub struct RemoveRuntime;

impl Command for RemoveRuntime {
    const NAME: &'static str = "websocket.remove-runtime";
    const SUMMARY: &'static str =
        "Stop and remove the websocket service, and its releases when asked.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = RemoveInput;
    type Output = Removed;
    type Error = WebSocketError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(_: &RemoveInput) -> Option<String> {
        Some("websocket-runtime".into())
    }

    fn run(input: RemoveInput, _: &Context) -> Result<Removed, WebSocketError> {
        remove(&Files::system(), &mut Native, &input)
    }
}

pub struct RuntimeActive;

impl Command for RuntimeActive {
    const NAME: &'static str = "websocket.runtime-active";
    const SUMMARY: &'static str = "Say whether the websocket service is active.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = ActiveInput;
    type Output = Active;
    type Error = WebSocketError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(_: ActiveInput, _: &Context) -> Result<Active, WebSocketError> {
        Ok(Active {
            active: Native.systemctl(&["is-active", "--quiet", SERVICE]),
        })
    }
}

#[cfg(test)]
#[path = "websocket_runtime_tests.rs"]
mod tests;

//! `websocket.converge-runtime`, `websocket.remove-runtime`, and `websocket.runtime-active`: the Reverb runtime of the
//! websocket role. Converge clones the pinned ref into a new release as the managed user, installs its Composer
//! dependencies, writes its `.env`, and checks the release and the unit before it stops the running service. Only
//! then does it switch the `current` link, publish the unit, restart, and wait for the listener. It keeps the new and
//! the previous release, and removes the others. One install lock serializes converges.

use super::caddy::chown;
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
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

fn run(step: &str, command: &mut Process) -> Result<(), WebSocketError> {
    let output = command
        .stdin(Stdio::null())
        .output()
        .map_err(|error| failed(step, error.to_string()))?;
    if output.status.success() {
        Ok(())
    } else {
        Err(failed(step, String::from_utf8_lossy(&output.stderr)))
    }
}

fn as_user(user: &str, program: &str) -> Result<Process, WebSocketError> {
    let mut command = Process::new(program);
    identity::drop_to(&mut command, user).map_err(|reason| failed("account", reason))?;
    Ok(command)
}

fn systemctl(arguments: &[&str]) -> bool {
    Process::new("systemctl")
        .args(arguments)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok_and(|status| status.success())
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

/// Waits up to 30 seconds for a TCP listener on the loopback port.
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

#[derive(Deserialize)]
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

#[derive(Debug, Serialize, PartialEq)]
pub struct Converged {
    pub release: String,
}

/// Prepares and checks a release; the caller removes it when anything fails.
fn prepare(
    input: &ConvergeInput,
    candidate: &Path,
    verify: &Path,
    uid: u32,
    gid: u32,
) -> Result<(), WebSocketError> {
    let user = input.user.as_str();
    fs::DirBuilder::new()
        .mode(0o755)
        .create(candidate)
        .and_then(|()| chown(candidate, uid, gid))
        .map_err(|error| failed("release", error.to_string()))?;
    run(
        "clone",
        as_user(user, "git")?
            .args(["clone", "--quiet", "--no-checkout", "--", &input.repository])
            .arg(candidate),
    )?;
    run(
        "fetch",
        as_user(user, "git")?.arg("-C").arg(candidate).args([
            "fetch",
            "--quiet",
            "origin",
            &input.reference,
        ]),
    )?;
    run(
        "checkout",
        as_user(user, "git")?.arg("-C").arg(candidate).args([
            "checkout",
            "--quiet",
            "--detach",
            "FETCH_HEAD",
        ]),
    )?;
    run(
        "composer",
        as_user(user, "composer")?
            .env("COMPOSER_ALLOW_SUPERUSER", "1")
            .args([
                "install",
                "--no-dev",
                "--optimize-autoloader",
                "--no-interaction",
                "--no-progress",
            ])
            .arg(format!("--working-dir={}", candidate.display())),
    )?;
    let environment = candidate.join(".env");
    write_file(&environment, input.environment.as_bytes(), 0o600)
        .and_then(|()| chown(&environment, uid, gid))
        .map_err(|error| failed("environment", error.to_string()))?;
    run(
        "artisan",
        as_user(user, "/usr/bin/php")?
            .arg(candidate.join("artisan"))
            .args(["orbit:reverb:start", "--help"]),
    )?;
    let unit = verify.join(format!("{SERVICE}.service"));
    fs::write(&unit, &input.unit).map_err(|error| failed("unit", error.to_string()))?;
    run(
        "unit",
        Process::new("systemd-analyze").arg("verify").arg(&unit),
    )
}

pub fn converge(input: &ConvergeInput) -> Result<Converged, WebSocketError> {
    let install = &input.install_path;
    let (Some(parent), true) = (install.parent(), install.is_absolute()) else {
        return Err(WebSocketError::Invalid {
            check: "the install path is absolute".into(),
        });
    };
    let (Some(account), Some(gid)) = (
        identity::passwd(&input.user),
        identity::group_id(&input.group),
    ) else {
        return Err(WebSocketError::Invalid {
            check: "the managed account exists".into(),
        });
    };
    let uid = account.uid;
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

    let prepared = prepare(input, &candidate, &verify, uid, gid);
    let _ = fs::remove_dir_all(&verify);
    if let Err(error) = prepared {
        let _ = fs::remove_dir_all(&candidate);
        return Err(error);
    }

    // Every check passed, so only now does the running service stop and its code change.
    if systemctl(&["cat", &format!("{SERVICE}.service")]) && !systemctl(&["stop", SERVICE]) {
        let _ = fs::remove_dir_all(&candidate);
        return Err(failed("stop", "the running service did not stop"));
    }
    let metadata = fs::symlink_metadata(install).ok();
    let previous = match &metadata {
        Some(metadata) if metadata.file_type().is_symlink() => fs::canonicalize(install).ok(),
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
    write_file(Path::new(UNIT), input.unit.as_bytes(), 0o644)
        .and_then(|()| fs::set_permissions(UNIT, fs::Permissions::from_mode(0o644)))
        .map_err(|error| failed("unit", error.to_string()))?;
    if !systemctl(&["daemon-reload"])
        || !systemctl(&["enable", "--quiet", SERVICE])
        || !systemctl(&["restart", SERVICE])
    {
        return Err(failed("restart", "systemd did not start the service"));
    }
    if !listening(input.port, Duration::from_secs(30)) {
        return Err(WebSocketError::NotListening { port: input.port });
    }
    let mut keep = vec![candidate.as_path()];
    keep.extend(previous.as_deref());
    prune(&releases, &keep);
    Ok(Converged {
        release: candidate.display().to_string(),
    })
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RemoveInput {
    pub install_path: PathBuf,
    /// Also delete the install and its releases.
    pub purge: bool,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Removed {}

pub fn remove(input: &RemoveInput) -> Result<Removed, WebSocketError> {
    if !input.install_path.is_absolute() {
        return Err(WebSocketError::Invalid {
            check: "the install path is absolute".into(),
        });
    }
    if systemctl(&["list-unit-files", "--", &format!("{SERVICE}.service")])
        && !systemctl(&["disable", "--now", "--", SERVICE])
    {
        return Err(failed("disable", "the service did not stop"));
    }
    if fs::remove_file(UNIT).is_err() && Path::new(UNIT).exists() {
        return Err(failed("unit", "the unit file stayed"));
    }
    systemctl(&["daemon-reload"]);
    systemctl(&["reset-failed", "--", &format!("{SERVICE}.service")]);
    if input.purge {
        for path in [
            input.install_path.clone(),
            PathBuf::from(format!("{}.releases", input.install_path.display())),
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

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ActiveInput {}

#[derive(Debug, Serialize, PartialEq)]
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
        converge(&input)
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
        remove(&input)
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
            active: systemctl(&["is-active", "--quiet", SERVICE]),
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn scratch() -> PathBuf {
        let path = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(random_name("orbit-websocket-test"));
        fs::create_dir_all(&path).unwrap();
        path
    }

    #[test]
    fn prune_keeps_only_the_new_and_previous_releases() {
        let releases = scratch();
        for name in [
            "release.a",
            "release.b",
            "previous.c",
            "release.new",
            "other",
        ] {
            fs::create_dir_all(releases.join(name)).unwrap();
        }

        prune(
            &releases,
            &[&releases.join("release.new"), &releases.join("previous.c")],
        );

        let mut left: Vec<String> = fs::read_dir(&releases)
            .unwrap()
            .flatten()
            .map(|entry| entry.file_name().to_string_lossy().into_owned())
            .collect();
        left.sort();
        assert_eq!(left, ["other", "previous.c", "release.new"]);
        let _ = fs::remove_dir_all(&releases);
    }

    #[test]
    fn waits_for_a_listener_and_gives_up_after_its_wait() {
        // Other tests fork while holding their own fixtures. Keep this listener in a separate process
        // so those children cannot keep its socket open after this test closes it.
        let output = Process::new(std::env::current_exe().unwrap())
            .args([
                "--ignored",
                "--exact",
                "commands::websocket_runtime::tests::listener_wait_helper",
                "--nocapture",
            ])
            .output()
            .unwrap();
        assert!(
            output.status.success(),
            "{}{}",
            String::from_utf8_lossy(&output.stdout),
            String::from_utf8_lossy(&output.stderr)
        );
    }

    #[test]
    #[ignore = "runs in its own process from the listener wait test"]
    fn listener_wait_helper() {
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let port = listener.local_addr().unwrap().port();
        assert!(listening(port, Duration::from_secs(1)));
        drop(listener);
        assert!(!listening(port, Duration::from_millis(200)));
    }

    #[test]
    fn refuses_a_relative_path_or_unknown_account_before_any_change() {
        let input = |path: &str, user: &str| ConvergeInput {
            user: user.into(),
            group: "root".into(),
            install_path: path.into(),
            repository: "https://example.test/reverb.git".into(),
            reference: "main".into(),
            port: 8790,
            environment: String::new(),
            unit: String::new(),
        };
        assert!(matches!(
            converge(&input("opt/orbit/websocket", "root")),
            Err(WebSocketError::Invalid { .. })
        ));
        assert!(matches!(
            converge(&input("/opt/orbit/websocket", "orbit-no-such-user")),
            Err(WebSocketError::Invalid { .. })
        ));
        assert!(matches!(
            remove(&RemoveInput {
                install_path: "relative".into(),
                purge: true
            }),
            Err(WebSocketError::Invalid { .. })
        ));
    }

    #[test]
    fn writes_a_file_with_its_mode_through_one_rename() {
        let base = scratch();
        let path = base.join("unit");
        write_file(&path, b"one", 0o600).unwrap();
        write_file(&path, b"two", 0o600).unwrap();
        assert_eq!(fs::read_to_string(&path).unwrap(), "two");
        assert_eq!(
            fs::metadata(&path).unwrap().permissions().mode() & 0o777,
            0o600
        );
        assert_eq!(fs::read_dir(&base).unwrap().count(), 1);
        let _ = fs::remove_dir_all(&base);
    }
}

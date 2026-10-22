//! `agent.*`: the agent's own status and installation.

use crate::command::{lock, run::file_sha256, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    path::{Path, PathBuf},
    process::Command as Process,
    time::{Duration, Instant},
};

pub const LINUX_BINARY: &str = "/usr/local/bin/orbit-agent";
pub const OBSERVER_UNIT: &str = "orbit-agent.service";
const OBSERVER_UNIT_FILE: &str = "/etc/systemd/system/orbit-agent.service";
/// An installation waits at most this long for the update lock.
const UPDATE_WAIT: Duration = Duration::from_secs(120);
/// The observer must stay active this long after a restart.
const HEALTH_WINDOW: Duration = Duration::from_secs(5);

/// Where the agent binary lives on this platform.
pub fn binary_path(platform: Platform) -> PathBuf {
    match platform {
        Platform::Linux => PathBuf::from(LINUX_BINARY),
        Platform::Macos => orbit_home().join("bin/orbit-agent"),
    }
}

fn orbit_home() -> PathBuf {
    std::env::var_os("ORBIT_HOME")
        .map(PathBuf::from)
        .unwrap_or_else(|| {
            PathBuf::from(std::env::var_os("HOME").unwrap_or_default()).join(".orbit")
        })
}

fn update_lock(platform: Platform) -> PathBuf {
    match platform {
        Platform::Linux => PathBuf::from(lock::UPDATE_LOCK_LINUX),
        Platform::Macos => orbit_home().join("self-update.lock"),
    }
}

// ---------------------------------------------------------------------------------------------------

/// Reports the running binary, so the Gateway can see which pin a Node runs.
pub struct Status;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct StatusInput {}

#[derive(Serialize)]
pub struct StatusOutput {
    pub version: &'static str,
    pub sha256: String,
    pub platform: Platform,
    pub architecture: &'static str,
    pub observer: &'static str,
}

command_errors! {
    pub enum StatusError {
        DigestUnreadable { reason: String } => "agent.digest_unreadable", "The agent cannot read its own executable.";
    }
}

impl Command for Status {
    const NAME: &'static str = "agent.status";
    const SUMMARY: &'static str = "Report the running agent binary and its observer.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = StatusInput;
    type Output = StatusOutput;
    type Error = StatusError;

    fn privilege(_: Platform) -> Option<Privilege> {
        Some(Privilege::Account)
    }

    fn run(_: StatusInput, context: &Context) -> Result<StatusOutput, StatusError> {
        let executable = if context.platform == Platform::Linux {
            PathBuf::from("/proc/self/exe")
        } else {
            std::env::current_exe().map_err(|error| StatusError::DigestUnreadable {
                reason: error.to_string(),
            })?
        };
        let sha256 = file_sha256(&executable).map_err(|error| StatusError::DigestUnreadable {
            reason: error.to_string(),
        })?;
        Ok(StatusOutput {
            version: env!("CARGO_PKG_VERSION"),
            sha256,
            platform: context.platform,
            architecture: std::env::consts::ARCH,
            observer: observer_state(context.platform),
        })
    }
}

fn observer_state(platform: Platform) -> &'static str {
    if platform != Platform::Linux || !Path::new(OBSERVER_UNIT_FILE).exists() {
        return "absent";
    }
    match Process::new("/usr/bin/systemctl")
        .args(["is-active", "--quiet", OBSERVER_UNIT])
        .status()
    {
        Ok(status) if status.success() => "active",
        _ => "inactive",
    }
}

// ---------------------------------------------------------------------------------------------------

/// Installs a staged candidate as the agent binary. It runs from that candidate, so it needs no working
/// installed agent. Only the binary changes: the observer restarts with its existing configuration and
/// secret, and a failed health check restores the kept binary.
pub struct Install;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InstallInput {
    /// The absolute path of the staged candidate.
    pub candidate: PathBuf,
    pub sha256: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct InstallOutput {
    pub replaced: bool,
    pub sha256: String,
    pub previous_sha256: Option<String>,
    pub observer: &'static str,
}

command_errors! {
    pub enum InstallError {
        CandidateInvalid { candidate: String } => "agent.candidate_invalid", "The candidate is not an absolute path to a staged file.";
        CandidateMissing { candidate: String } => "agent.candidate_missing", "The staged candidate does not exist.";
        CandidateDigestMismatch { expected: String, observed: String } => "agent.candidate_digest_mismatch", "The staged candidate is not the pinned binary.";
        UpdateBusy { lock: String } => "agent.update_busy", "Another installation holds the update lock.";
        InstallFailed { step: &'static str, reason: String } => "agent.replace_failed", "The agent binary could not be replaced.";
        HealthCheckFailed { observer: &'static str } => "agent.health_check_failed", "The observer did not stay active on the new binary; the kept binary is back.";
    }
}

impl Command for Install {
    const NAME: &'static str = "agent.install";
    const SUMMARY: &'static str =
        "Install a staged, pinned agent binary and restart the observer on it.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(180);
    type Input = InstallInput;
    type Output = InstallOutput;
    type Error = InstallError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        Some(match platform {
            Platform::Linux => Privilege::Root,
            Platform::Macos => Privilege::Account,
        })
    }

    fn run(input: InstallInput, context: &Context) -> Result<InstallOutput, InstallError> {
        let target = binary_path(context.platform);
        let wait = context.deadline().min(Instant::now() + UPDATE_WAIT);
        let observer =
            if context.platform == Platform::Linux && Path::new(OBSERVER_UNIT_FILE).exists() {
                Observer::Systemd
            } else {
                Observer::Absent
            };
        install(
            &input,
            &target,
            &update_lock(context.platform),
            wait,
            observer,
        )
    }
}

#[derive(Clone, Copy)]
pub enum Observer {
    Absent,
    Systemd,
    /// Tests: a health check that passes or fails without systemd.
    #[cfg(test)]
    Fake(bool),
}
impl Observer {
    fn label(self) -> &'static str {
        match self {
            Self::Absent => "absent",
            _ => "restarted",
        }
    }
    /// Restarts the observer and reports whether it stays active for the health window.
    fn restart_and_check(self) -> bool {
        match self {
            Self::Absent => true,
            #[cfg(test)]
            Self::Fake(healthy) => healthy,
            Self::Systemd => {
                let restarted = Process::new("/usr/bin/systemctl")
                    .args(["restart", OBSERVER_UNIT])
                    .status()
                    .is_ok_and(|status| status.success());
                if !restarted {
                    return false;
                }
                let until = Instant::now() + HEALTH_WINDOW;
                while Instant::now() < until {
                    std::thread::sleep(Duration::from_millis(500));
                    let active = Process::new("/usr/bin/systemctl")
                        .args(["is-active", "--quiet", OBSERVER_UNIT])
                        .status()
                        .is_ok_and(|status| status.success());
                    if !active {
                        return false;
                    }
                }
                true
            }
        }
    }
}

pub fn install(
    input: &InstallInput,
    target: &Path,
    update_lock: &Path,
    wait: Instant,
    observer: Observer,
) -> Result<InstallOutput, InstallError> {
    let candidate = &input.candidate;
    let shown = candidate.display().to_string();
    if !candidate.is_absolute() || candidate.file_name().is_none() {
        return Err(InstallError::CandidateInvalid { candidate: shown });
    }
    if !candidate.is_file() {
        return Err(InstallError::CandidateMissing { candidate: shown });
    }
    let observed = file_sha256(candidate).map_err(|error| failed("read candidate", error))?;
    if observed != input.sha256 {
        return Err(InstallError::CandidateDigestMismatch {
            expected: input.sha256.clone(),
            observed,
        });
    }
    let _lock = lock::acquire(update_lock, wait).map_err(|error| match error {
        lock::LockError::Busy(path) => InstallError::UpdateBusy {
            lock: path.display().to_string(),
        },
        lock::LockError::Io(path, reason) => InstallError::InstallFailed {
            step: "update lock",
            reason: format!("{}: {reason}", path.display()),
        },
    })?;
    let previous_sha256 = file_sha256(target).ok();
    if previous_sha256.as_deref() == Some(input.sha256.as_str()) {
        let _ = std::fs::remove_file(candidate);
        return Ok(InstallOutput {
            replaced: false,
            sha256: observed,
            previous_sha256,
            observer: "unchanged",
        });
    }

    let directory = target
        .parent()
        .ok_or_else(|| failed("target directory", "no parent"))?;
    std::fs::create_dir_all(directory).map_err(|error| failed("target directory", error))?;
    let name = target.file_name().unwrap().to_string_lossy();
    let staged = directory.join(format!("{name}.orbit-candidate"));
    let kept = directory.join(format!("{name}.previous"));
    copy_executable(candidate, &staged).map_err(|error| failed("stage", error))?;
    if previous_sha256.is_some() {
        copy_executable(target, &kept).map_err(|error| failed("keep previous", error))?;
    }
    std::fs::rename(&staged, target).map_err(|error| failed("replace", error))?;

    if !observer.restart_and_check() {
        if previous_sha256.is_some() {
            let _ = std::fs::rename(&kept, target);
            observer.restart_and_check();
        }
        return Err(InstallError::HealthCheckFailed {
            observer: observer.label(),
        });
    }
    let _ = std::fs::remove_file(candidate);
    Ok(InstallOutput {
        replaced: true,
        sha256: observed,
        previous_sha256,
        observer: observer.label(),
    })
}

fn copy_executable(from: &Path, to: &Path) -> std::io::Result<()> {
    use std::os::unix::fs::PermissionsExt;
    std::fs::copy(from, to)?;
    std::fs::set_permissions(to, std::fs::Permissions::from_mode(0o755))?;
    std::fs::File::open(to)?.sync_all()
}

fn failed(step: &'static str, reason: impl ToString) -> InstallError {
    InstallError::InstallFailed {
        step,
        reason: reason.to_string(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::command::run::file_sha256;

    struct Fixture {
        directory: PathBuf,
    }
    impl Fixture {
        fn new() -> Self {
            let directory = std::env::temp_dir().join(format!(
                "orbit-agent-install-{}-{}",
                std::process::id(),
                rand::random::<u64>()
            ));
            std::fs::create_dir_all(directory.join("bin")).unwrap();
            Self { directory }
        }
        fn write(&self, name: &str, contents: &str) -> PathBuf {
            let path = self.directory.join(name);
            std::fs::write(&path, contents).unwrap();
            path
        }
        fn target(&self) -> PathBuf {
            self.directory.join("bin/orbit-agent")
        }
        fn lock(&self) -> PathBuf {
            self.directory.join("update.lock")
        }
        fn input(&self, candidate: &Path) -> InstallInput {
            InstallInput {
                candidate: candidate.into(),
                sha256: file_sha256(candidate).unwrap(),
            }
        }
        fn install(
            &self,
            input: &InstallInput,
            observer: Observer,
        ) -> Result<InstallOutput, InstallError> {
            install(
                input,
                &self.target(),
                &self.lock(),
                Instant::now() + Duration::from_secs(2),
                observer,
            )
        }
    }

    #[test]
    fn installs_the_candidate_keeps_the_previous_binary_and_removes_the_candidate() {
        let fixture = Fixture::new();
        let old = fixture.write("bin/orbit-agent", "old");
        let candidate = fixture.write("candidate-1", "new");
        let input = fixture.input(&candidate);
        let output = fixture.install(&input, Observer::Fake(true)).unwrap();
        assert!(output.replaced);
        assert_eq!(
            output.previous_sha256,
            Some(crate::command::run::hex(&sha2_of("old")))
        );
        assert_eq!(std::fs::read_to_string(&old).unwrap(), "new");
        assert_eq!(
            std::fs::read_to_string(fixture.directory.join("bin/orbit-agent.previous")).unwrap(),
            "old"
        );
        assert!(!candidate.exists());
        use std::os::unix::fs::PermissionsExt;
        assert_eq!(
            std::fs::metadata(fixture.target())
                .unwrap()
                .permissions()
                .mode()
                & 0o777,
            0o755
        );
    }

    #[test]
    fn an_installed_pin_is_left_alone_so_concurrent_mismatches_install_once() {
        let fixture = Fixture::new();
        fixture.write("bin/orbit-agent", "same");
        let candidate = fixture.write("candidate-2", "same");
        let output = fixture
            .install(&fixture.input(&candidate), Observer::Fake(false))
            .unwrap();
        assert!(!output.replaced);
        assert_eq!(output.observer, "unchanged");
        assert!(!candidate.exists());
    }

    #[test]
    fn a_failed_health_check_restores_the_kept_binary() {
        let fixture = Fixture::new();
        fixture.write("bin/orbit-agent", "old");
        let candidate = fixture.write("candidate-3", "broken");
        let error = fixture
            .install(&fixture.input(&candidate), Observer::Fake(false))
            .unwrap_err();
        assert_eq!(
            error,
            InstallError::HealthCheckFailed {
                observer: "restarted"
            }
        );
        assert_eq!(std::fs::read_to_string(fixture.target()).unwrap(), "old");
    }

    #[test]
    fn refuses_a_candidate_that_is_not_the_pin_before_any_change() {
        let fixture = Fixture::new();
        fixture.write("bin/orbit-agent", "old");
        let candidate = fixture.write("candidate-4", "tampered");
        let input = InstallInput {
            candidate: candidate.clone(),
            sha256: "0".repeat(64),
        };
        assert!(matches!(
            fixture.install(&input, Observer::Fake(true)),
            Err(InstallError::CandidateDigestMismatch { .. })
        ));
        assert_eq!(std::fs::read_to_string(fixture.target()).unwrap(), "old");
        assert!(candidate.exists());
    }

    #[test]
    fn refuses_a_missing_or_relative_candidate() {
        let fixture = Fixture::new();
        let missing = InstallInput {
            candidate: fixture.directory.join("absent"),
            sha256: "0".repeat(64),
        };
        assert!(matches!(
            fixture.install(&missing, Observer::Fake(true)),
            Err(InstallError::CandidateMissing { .. })
        ));
        let relative = InstallInput {
            candidate: PathBuf::from("candidate"),
            sha256: "0".repeat(64),
        };
        assert!(matches!(
            fixture.install(&relative, Observer::Fake(true)),
            Err(InstallError::CandidateInvalid { .. })
        ));
    }

    #[test]
    fn waits_for_the_update_lock_and_reports_busy_without_a_change() {
        let fixture = Fixture::new();
        fixture.write("bin/orbit-agent", "old");
        let candidate = fixture.write("candidate-5", "new");
        let _held = lock::acquire(&fixture.lock(), Instant::now()).unwrap();
        let error = install(
            &fixture.input(&candidate),
            &fixture.target(),
            &fixture.lock(),
            Instant::now() + Duration::from_millis(200),
            Observer::Fake(true),
        )
        .unwrap_err();
        assert!(matches!(error, InstallError::UpdateBusy { .. }));
        assert_eq!(std::fs::read_to_string(fixture.target()).unwrap(), "old");
    }

    #[test]
    fn a_first_installation_has_no_previous_binary() {
        let fixture = Fixture::new();
        let candidate = fixture.write("candidate-6", "first");
        let output = fixture
            .install(&fixture.input(&candidate), Observer::Absent)
            .unwrap();
        assert_eq!(
            output,
            InstallOutput {
                replaced: true,
                sha256: crate::command::run::hex(&sha2_of("first")),
                previous_sha256: None,
                observer: "absent"
            }
        );
    }

    fn sha2_of(text: &str) -> Vec<u8> {
        use sha2::{Digest, Sha256};
        Sha256::digest(text.as_bytes()).to_vec()
    }
}

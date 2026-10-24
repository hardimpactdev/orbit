//! `cli.inspect` and `cli.install`: the Orbit CLI in the layout `orbit self-update` keeps, the release as
//! `/usr/local/bin/orbit-<version>` and `/usr/local/bin/orbit` as a link to it, plus root's CLI profile.
//!
//! The link path decides what happens. Nothing there: install. An Orbit CLI with `self-update`: leave it, because
//! `orbit self-update` replaces it. An Orbit CLI without `self-update`: install, keep the old file as
//! `orbit.orbit-previous`, and switch the link. Anything else (another link, a script, another program, another
//! owner's file) is foreign and refused; it is never run. Every binary the check runs runs as the managed user.
//! The install holds the update lock that `orbit self-update` and `agent.install` hold.

use crate::command::{identity, lock, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    fs,
    io::{Read, Write},
    os::unix::fs::{symlink, DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::{Duration, Instant},
};

const LINK: &str = "/usr/local/bin/orbit";
const PROFILE_DIRECTORY: &str = "/root/.orbit";
const PROFILE: &str = "/root/.orbit/config.json";
/// How long an install waits for a self-update to release the update lock.
const UPDATE_WAIT: Duration = Duration::from_secs(300);

command_errors! {
    pub enum CliError {
        Foreign {} => "cli.foreign_binary", "The CLI path holds a link, a script, or a file Orbit did not install.";
        ReleaseNeeded {} => "cli.release_needed", "The Node needs the CLI, and the request names no release.";
        UpdateBusy {} => "cli.update_busy", "A self-update held the update lock for too long.";
        DownloadFailed { stderr: String } => "cli.download_failed", "The CLI download failed.";
        ChecksumMismatch {} => "cli.checksum_mismatch", "The downloaded CLI failed checksum verification.";
        CandidateInvalid {} => "cli.candidate_invalid", "The downloaded CLI does not run or does not report its version.";
        Failed { step: String } => "cli.install_failed", "A CLI install step failed.";
    }
}

fn failed(step: &str) -> CliError {
    CliError::Failed { step: step.into() }
}

#[derive(Debug, Serialize, PartialEq, Clone, Copy)]
#[serde(rename_all = "snake_case")]
pub enum State {
    Missing,
    Present,
    Stale,
    Foreign,
}

/// `orbit-0.N.0`, the name a release link may point at.
pub(super) fn release_name(name: &str) -> bool {
    name.strip_prefix("orbit-0.")
        .and_then(|rest| rest.strip_suffix(".0"))
        .is_some_and(|minor| {
            !minor.is_empty()
                && minor.len() <= 10
                && !minor.starts_with('0')
                && minor.bytes().all(|byte| byte.is_ascii_digit())
        })
}

/// `Orbit 0.N.0` or a pre-release `Orbit <40-hex commit>`.
fn orbit_version(line: &str) -> bool {
    line.strip_prefix("Orbit ").is_some_and(|version| {
        release_name(&format!("orbit-{version}"))
            || (version.len() == 40
                && version
                    .bytes()
                    .all(|byte| matches!(byte, b'0'..=b'9' | b'a'..=b'f')))
    })
}

/// Runs a binary as the managed user, at most 60 seconds, and answers its first output line when it succeeds.
fn run_as(user: &str, binary: &Path, arguments: &[&str]) -> Option<String> {
    let mut command = Process::new("timeout");
    command
        .arg("60")
        .arg(binary)
        .args(arguments)
        .stdin(Stdio::null())
        .stderr(Stdio::null());
    identity::drop_to(&mut command, user).ok()?;
    let output = command.output().ok()?;
    output.status.success().then(|| {
        String::from_utf8_lossy(&output.stdout)
            .lines()
            .next()
            .unwrap_or_default()
            .to_string()
    })
}

fn elf(path: &Path) -> bool {
    let mut magic = [0u8; 4];
    fs::File::open(path)
        .and_then(|mut file| file.read_exact(&mut magic))
        .is_ok()
        && magic == *b"\x7fELF"
}

fn checked_binary(link: &Path) -> Result<PathBuf, State> {
    let binary = match fs::symlink_metadata(link) {
        Err(_) => return Err(State::Missing),
        Ok(metadata) if metadata.file_type().is_symlink() => {
            let Some(target) = fs::read_link(link)
                .ok()
                .and_then(|target| target.to_str().map(String::from))
            else {
                return Err(State::Foreign);
            };
            if !release_name(&target) {
                return Err(State::Foreign);
            }
            link.with_file_name(target)
        }
        Ok(_) => link.to_path_buf(),
    };
    let Ok(metadata) = fs::symlink_metadata(&binary) else {
        return Err(State::Foreign);
    };
    if !metadata.is_file() || metadata.uid() != 0 || metadata.mode() & 0o111 == 0 || !elf(&binary) {
        return Err(State::Foreign);
    }
    Ok(binary)
}

/// Reads an Orbit-owned executable as the enrolled account. The caller owns update locking.
pub(super) fn installed_version(link: &Path, user: &str) -> Option<String> {
    let binary = checked_binary(link).ok()?;
    let line = run_as(user, &binary, &["--version"])?;
    orbit_version(&line).then(|| line.trim_start_matches("Orbit ").to_owned())
}

pub fn inspect(link: &Path, user: &str) -> State {
    let binary = match checked_binary(link) {
        Ok(binary) => binary,
        Err(state) => return state,
    };
    if !run_as(user, &binary, &["--version"]).is_some_and(|line| orbit_version(&line)) {
        return State::Foreign;
    }
    if run_as(user, &binary, &["self-update", "--help"]).is_some() {
        State::Present
    } else {
        State::Stale
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectInput {
    pub user: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Inspected {
    pub state: State,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Release {
    pub version: String,
    pub url: String,
    pub sha256: String,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InstallInput {
    pub user: String,
    /// The release to install when the Node needs one; null when the Gateway has none to offer.
    pub release: Option<Release>,
    /// Root's CLI profile, written when it differs.
    pub profile: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Installed {
    /// The version installed, or null when the Node keeps its CLI.
    pub installed: Option<String>,
    pub configured: bool,
}

fn download(release: &Release, candidate: &Path) -> Result<(), CliError> {
    let output = Process::new("curl")
        .args([
            "--fail",
            "--location",
            "--silent",
            "--show-error",
            "--proto",
            "=https",
            "--proto-redir",
            "=https",
            "--connect-timeout",
            "20",
            "--max-time",
            "180",
            "--output",
        ])
        .arg(candidate)
        .arg("--")
        .arg(&release.url)
        .stdin(Stdio::null())
        .output()
        .map_err(|error| CliError::DownloadFailed {
            stderr: error.to_string(),
        })?;
    if output.status.success() {
        Ok(())
    } else {
        Err(CliError::DownloadFailed {
            stderr: String::from_utf8_lossy(&output.stderr).trim().to_string(),
        })
    }
}

/// Downloads, checks, and moves the release into place, then switches the link. A failure before the move removes
/// the candidate and leaves the link as it was.
fn install_release(link: &Path, user: &str, release: &Release) -> Result<(), CliError> {
    if !release_name(&format!("orbit-{}", release.version)) {
        return Err(failed("version"));
    }
    let target = link.with_file_name(format!("orbit-{}", release.version));
    let candidate = PathBuf::from(format!("{}.orbit-candidate", target.display()));
    let _ = fs::remove_file(&candidate);
    let result = (|| {
        download(release, &candidate)?;
        let bytes = fs::read(&candidate).map_err(|_| failed("read"))?;
        if format!("{:x}", Sha256::digest(&bytes)) != release.sha256 {
            return Err(CliError::ChecksumMismatch {});
        }
        rustix::fs::chown(
            &candidate,
            Some(rustix::fs::Uid::ROOT),
            Some(rustix::fs::Gid::ROOT),
        )
        .map_err(|_| failed("owner"))?;
        fs::set_permissions(&candidate, fs::Permissions::from_mode(0o755))
            .map_err(|_| failed("mode"))?;
        if !run_as(user, &candidate, &["--version"])
            .is_some_and(|line| line.contains(&release.version))
        {
            return Err(CliError::CandidateInvalid {});
        }
        fs::rename(&candidate, &target).map_err(|_| failed("move"))
    })();
    if result.is_err() {
        let _ = fs::remove_file(&candidate);
        return result;
    }
    switch_link(link, &target)
}

/// Points the link at the release with one rename, keeping a plain binary there as `orbit.orbit-previous`.
fn switch_link(link: &Path, target: &Path) -> Result<(), CliError> {
    if fs::symlink_metadata(link).is_ok_and(|metadata| metadata.is_file()) {
        let previous = link.with_file_name("orbit.orbit-previous");
        let _ = fs::remove_file(&previous);
        fs::copy(link, &previous)
            .and_then(|_| fs::set_permissions(&previous, fs::metadata(link)?.permissions()))
            .map_err(|_| failed("keep"))?;
    }
    let staged = PathBuf::from(format!("{}.orbit-link", link.display()));
    let _ = fs::remove_file(&staged);
    let name = target.file_name().ok_or_else(|| failed("link"))?;
    symlink(name, &staged)
        .and_then(|()| fs::rename(&staged, link))
        .map_err(|_| failed("link"))
}

/// Writes root's profile, `0600` in a `0700` directory, when it differs or is a link. Whether it wrote.
fn configure(directory: &Path, path: &Path, profile: &str) -> Result<bool, CliError> {
    let current = fs::symlink_metadata(path)
        .ok()
        .filter(|metadata| metadata.is_file())
        .and_then(|_| fs::read_to_string(path).ok());
    if current.as_deref() == Some(profile) {
        return Ok(false);
    }
    match fs::DirBuilder::new().mode(0o700).create(directory) {
        Ok(()) => {}
        Err(error) if error.kind() == std::io::ErrorKind::AlreadyExists => {}
        Err(_) => return Err(failed("configure")),
    }
    let candidate = PathBuf::from(format!("{}.orbit-candidate", path.display()));
    let _ = fs::remove_file(&candidate);
    let written = fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&candidate)
        .and_then(|mut file| file.write_all(profile.as_bytes()))
        .and_then(|()| fs::rename(&candidate, path));
    if written.is_err() {
        let _ = fs::remove_file(&candidate);
        return Err(failed("configure"));
    }
    Ok(true)
}

pub fn install(input: &InstallInput) -> Result<Installed, CliError> {
    let link = Path::new(LINK);
    let installed = match inspect(link, &input.user) {
        State::Present => None,
        State::Foreign => return Err(CliError::Foreign {}),
        State::Missing | State::Stale => {
            let release = input.release.as_ref().ok_or(CliError::ReleaseNeeded {})?;
            let _held = lock::acquire(
                Path::new(lock::UPDATE_LOCK_LINUX),
                Instant::now() + UPDATE_WAIT,
            )
            .map_err(|_| CliError::UpdateBusy {})?;
            // A self-update may have changed the path while this install waited.
            match inspect(link, &input.user) {
                State::Present => None,
                State::Foreign => return Err(CliError::Foreign {}),
                State::Missing | State::Stale => {
                    install_release(link, &input.user, release)?;
                    Some(release.version.clone())
                }
            }
        }
    };
    let configured = configure(
        Path::new(PROFILE_DIRECTORY),
        Path::new(PROFILE),
        &input.profile,
    )?;
    Ok(Installed {
        installed,
        configured,
    })
}

pub struct InspectCli;

impl Command for InspectCli {
    const NAME: &'static str = "cli.inspect";
    const SUMMARY: &'static str =
        "Say whether the Orbit CLI on the Node is missing, current, older, or foreign.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(150);
    type Input = InspectInput;
    type Output = Inspected;
    type Error = CliError;

    /// A read as the managed user, the only account that runs the found binary.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: InspectInput, _: &Context) -> Result<Inspected, CliError> {
        Ok(Inspected {
            state: inspect(Path::new(LINK), &input.user),
        })
    }
}

pub struct InstallCli;

impl Command for InstallCli {
    const NAME: &'static str = "cli.install";
    const SUMMARY: &'static str =
        "Install the Orbit CLI release when the Node needs it, and write root's CLI profile.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(720);
    type Input = InstallInput;
    type Output = Installed;
    type Error = CliError;

    /// `/usr/local/bin` and root's profile belong to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: InstallInput, _: &Context) -> Result<Installed, CliError> {
        install(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn scratch() -> PathBuf {
        let path = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-cli-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(&path).unwrap();
        path
    }

    fn me() -> String {
        String::from_utf8(Process::new("id").arg("-un").output().unwrap().stdout)
            .unwrap()
            .trim()
            .to_string()
    }

    #[test]
    fn release_names_and_versions_are_strict() {
        assert!(release_name("orbit-0.12.0") && release_name("orbit-0.1.0"));
        assert!(
            !release_name("orbit-0.012.0")
                && !release_name("orbit-1.2.0")
                && !release_name("orbit-0..0")
        );
        assert!(
            orbit_version("Orbit 0.12.0") && orbit_version(&format!("Orbit {}", "a".repeat(40)))
        );
        assert!(
            !orbit_version("Orbit 0.12.1")
                && !orbit_version("orbit 0.12.0")
                && !orbit_version("Other 0.12.0")
        );
    }

    #[test]
    fn a_missing_path_is_missing_and_a_script_or_other_link_is_foreign() {
        let base = scratch();
        let link = base.join("orbit");
        assert_eq!(inspect(&link, &me()), State::Missing);

        fs::write(&link, "#!/bin/sh\necho Orbit 0.12.0\n").unwrap();
        fs::set_permissions(&link, fs::Permissions::from_mode(0o755)).unwrap();
        assert_eq!(
            inspect(&link, &me()),
            State::Foreign,
            "a script is never run"
        );

        fs::remove_file(&link).unwrap();
        symlink("/usr/bin/true", &link).unwrap();
        assert_eq!(inspect(&link, &me()), State::Foreign);
        let _ = fs::remove_dir_all(&base);
    }

    #[test]
    fn switching_keeps_a_plain_binary_and_points_the_link_at_the_release() {
        let base = scratch();
        let link = base.join("orbit");
        fs::write(&link, "old").unwrap();
        fs::write(base.join("orbit-0.12.0"), "new").unwrap();

        switch_link(&link, &base.join("orbit-0.12.0")).unwrap();

        assert_eq!(fs::read_link(&link).unwrap(), PathBuf::from("orbit-0.12.0"));
        assert_eq!(
            fs::read_to_string(base.join("orbit.orbit-previous")).unwrap(),
            "old"
        );
        switch_link(&link, &base.join("orbit-0.12.0")).unwrap();
        assert_eq!(fs::read_link(&link).unwrap(), PathBuf::from("orbit-0.12.0"));
        let _ = fs::remove_dir_all(&base);
    }

    #[test]
    fn the_profile_is_written_only_when_it_differs_and_never_through_a_link() {
        let base = scratch();
        let directory = base.join(".orbit");
        let path = directory.join("config.json");

        assert_eq!(configure(&directory, &path, "{}\n"), Ok(true));
        assert_eq!(fs::metadata(&path).unwrap().mode() & 0o777, 0o600);
        assert_eq!(configure(&directory, &path, "{}\n"), Ok(false));

        fs::remove_file(&path).unwrap();
        fs::write(base.join("elsewhere"), "{}\n").unwrap();
        symlink(base.join("elsewhere"), &path).unwrap();
        assert_eq!(configure(&directory, &path, "{}\n"), Ok(true));
        assert!(!fs::symlink_metadata(&path)
            .unwrap()
            .file_type()
            .is_symlink());
        let _ = fs::remove_dir_all(&base);
    }
}

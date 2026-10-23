//! Node Caddy: the publication lock that Caddy builds and certificate steps share at `/run/lock/orbit/caddy.lock`,
//! a reload under it, and `caddy.install-certificate` and `caddy.remove-certificate` for a role's certificate
//! directory that Caddy reads.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use sha2::Digest;
use std::{
    fs, io,
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::{Duration, Instant},
};

/// The lock a Node Caddy build holds while it swaps the Caddyfile and reloads.
pub const LOCK: &str = "/run/lock/orbit/caddy.lock";

/// Holds the Caddy publication lock, a root-owned 0600 file in a root-owned 0700 directory, for up to 30 seconds.
pub fn lock() -> io::Result<fs::File> {
    let path = Path::new(LOCK);
    let directory = path.parent().ok_or(io::ErrorKind::InvalidInput)?;
    match fs::DirBuilder::new().mode(0o700).create(directory) {
        Err(error) if error.kind() != io::ErrorKind::AlreadyExists => return Err(error),
        _ => {}
    }
    let metadata = fs::symlink_metadata(directory)?;
    if !metadata.is_dir()
        || metadata.uid() != 0
        || metadata.gid() != 0
        || metadata.mode() & 0o7777 != 0o700
    {
        return Err(io::Error::other(
            "the Caddy lock directory is not private to root",
        ));
    }
    let file = fs::OpenOptions::new()
        .create(true)
        .append(true)
        .mode(0o600)
        .custom_flags(libc::O_NOFOLLOW)
        .open(path)?;
    let metadata = file.metadata()?;
    if !metadata.is_file() || metadata.uid() != 0 || metadata.gid() != 0 {
        return Err(io::Error::other("the Caddy lock is not a root-owned file"));
    }
    file.set_permissions(fs::Permissions::from_mode(0o600))?;
    let deadline = Instant::now() + Duration::from_secs(30);
    loop {
        match rustix::fs::flock(&file, rustix::fs::FlockOperation::NonBlockingLockExclusive) {
            Ok(()) => return Ok(file),
            Err(rustix::io::Errno::WOULDBLOCK) if Instant::now() < deadline => {
                std::thread::sleep(Duration::from_millis(50));
            }
            Err(error) => return Err(error.into()),
        }
    }
}

fn systemctl(arguments: &[&str]) -> io::Result<bool> {
    Ok(Process::new("systemctl")
        .args(arguments)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()?
        .success())
}

/// Reloads `service` under the publication lock when it runs, so a live site picks up a changed certificate.
pub fn reload_if_active(service: &str) -> io::Result<()> {
    if !systemctl(&["is-active", "--quiet", service])? {
        return Ok(());
    }
    let _lock = lock()?;
    if systemctl(&["reload-or-restart", service])? {
        Ok(())
    } else {
        Err(io::Error::other(format!("{service} did not reload")))
    }
}

/// The public key of a PEM certificate or key, as `openssl` prints it.
pub fn public_key(kind: &str, path: &Path) -> Option<Vec<u8>> {
    let arguments: &[&str] = if kind == "certificate" {
        &["x509", "-pubkey", "-noout", "-in"]
    } else {
        &["pkey", "-pubout", "-in"]
    };
    let output = Process::new("openssl")
        .args(arguments)
        .arg(path)
        .stdin(Stdio::null())
        .stderr(Stdio::null())
        .output()
        .ok()?;
    (output.status.success() && !output.stdout.is_empty()).then_some(output.stdout)
}

pub fn group_id(name: &str) -> Option<u32> {
    crate::command::identity::group_id(name)
}

pub fn chown(path: &Path, uid: u32, gid: u32) -> io::Result<()> {
    rustix::fs::chown(
        path,
        Some(rustix::fs::Uid::from_raw(uid)),
        Some(rustix::fs::Gid::from_raw(gid)),
    )?;
    Ok(())
}

command_errors! {
    pub enum CertificateError {
        Invalid {} => "caddy.certificate_invalid", "The key does not belong to the certificate.";
        Failed { reason: String } => "caddy.certificate_failed", "The certificate could not be installed.";
    }
}

fn failed(reason: impl ToString) -> CertificateError {
    CertificateError::Failed {
        reason: reason.to_string(),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InstallInput {
    /// The directory Caddy reads, such as `/etc/caddy/orbit-websocket/current`.
    pub directory: PathBuf,
    pub certificate_file: String,
    pub key_file: String,
    pub certificate: String,
    pub key: String,
    /// The Caddy unit that reloads after a change.
    pub service: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Installed {
    pub changed: bool,
}

fn write_private(path: &Path, content: &str, caddy: u32) -> io::Result<()> {
    fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o640)
        .open(path)
        .and_then(|mut file| io::Write::write_all(&mut file, content.as_bytes()))?;
    chown(path, 0, caddy)?;
    fs::set_permissions(path, fs::Permissions::from_mode(0o640))
}

pub fn install(input: &InstallInput) -> Result<Installed, CertificateError> {
    let caddy = group_id("caddy").ok_or_else(|| failed("the caddy group does not exist"))?;
    let _lock = lock().map_err(failed)?;
    let mut candidate = input.directory.as_os_str().to_owned();
    candidate.push(".orbit-candidate");
    let candidate = PathBuf::from(candidate);
    let _ = fs::remove_dir_all(&candidate);
    let result = (|| {
        fs::DirBuilder::new()
            .mode(0o750)
            .create(&candidate)
            .map_err(failed)?;
        chown(&candidate, 0, caddy).map_err(failed)?;
        let certificate = candidate.join(&input.certificate_file);
        let key = candidate.join(&input.key_file);
        write_private(&certificate, &input.certificate, caddy).map_err(failed)?;
        write_private(&key, &input.key, caddy).map_err(failed)?;
        let certificate_key = public_key("certificate", &certificate);
        if certificate_key.is_none() || certificate_key != public_key("key", &key) {
            return Err(CertificateError::Invalid {});
        }
        let current = |name: &str| fs::read(input.directory.join(name)).ok();
        if current(&input.certificate_file).as_deref() == Some(input.certificate.as_bytes())
            && current(&input.key_file).as_deref() == Some(input.key.as_bytes())
        {
            return Ok(false);
        }
        match fs::remove_dir_all(&input.directory) {
            Err(error) if error.kind() != io::ErrorKind::NotFound => return Err(failed(error)),
            _ => {}
        }
        fs::rename(&candidate, &input.directory).map_err(failed)?;
        Ok(true)
    })();
    let _ = fs::remove_dir_all(&candidate);
    drop(_lock);
    let changed = result?;
    if changed {
        reload_if_active(&input.service).map_err(failed)?;
    }
    Ok(Installed { changed })
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RemoveInput {
    pub directory: PathBuf,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Removed {}

pub struct InstallCertificate;

impl Command for InstallCertificate {
    const NAME: &'static str = "caddy.install-certificate";
    const SUMMARY: &'static str =
        "Install a certificate and key that Caddy reads, and reload Caddy when they change.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = InstallInput;
    type Output = Installed;
    type Error = CertificateError;

    /// Caddy's certificate directories and its reload belong to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: InstallInput, _: &Context) -> Result<Installed, CertificateError> {
        install(&input)
    }
}

pub struct RemoveCertificate;

impl Command for RemoveCertificate {
    const NAME: &'static str = "caddy.remove-certificate";
    const SUMMARY: &'static str = "Remove a certificate directory that Caddy reads.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = RemoveInput;
    type Output = Removed;
    type Error = CertificateError;

    /// Caddy's certificate directories belong to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: RemoveInput, _: &Context) -> Result<Removed, CertificateError> {
        match fs::remove_dir_all(&input.directory) {
            Err(error) if error.kind() != io::ErrorKind::NotFound => Err(failed(error)),
            _ => Ok(Removed {}),
        }
    }
}

command_errors! {
    pub enum PackageError {
        Unsafe {} => "caddy.package_unsafe", "An Orbit Caddy file has unsafe ownership or mode.";
        Failed { reason: String } => "caddy.package_failed", "The Caddy package could not be installed.";
    }
}

fn package_failed(reason: impl ToString) -> PackageError {
    PackageError::Failed {
        reason: reason.to_string(),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PackageInput {
    pub release: String,
    pub release_url: String,
    pub amd64_sha512: String,
    pub arm64_sha512: String,
    /// The oldest Caddy release Orbit renders against.
    pub minimum_version: String,
    pub kernel_setting_path: PathBuf,
    pub kernel_setting: String,
    /// The Cloudsmith keyring and apt source that Orbit no longer uses.
    pub legacy_paths: Vec<PathBuf>,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Package {
    pub changed: bool,
    pub version: String,
}

fn run_checked(program: &str, arguments: &[&str]) -> Result<String, PackageError> {
    let output = Process::new(program)
        .args(arguments)
        .stdin(Stdio::null())
        .output()
        .map_err(|error| package_failed(format!("{program}: {error}")))?;
    if !output.status.success() {
        return Err(package_failed(format!(
            "{program} failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        )));
    }
    Ok(String::from_utf8_lossy(&output.stdout).into_owned())
}

/// The version `caddy version` reports, without its `v`, or none when Caddy is absent.
fn installed_version() -> Option<String> {
    let output = Process::new("caddy")
        .arg("version")
        .stdin(Stdio::null())
        .output()
        .ok()?;
    let text = String::from_utf8_lossy(&output.stdout);
    let first = text.lines().next()?.split_whitespace().next()?;
    Some(first.trim_start_matches('v').to_string()).filter(|version| !version.is_empty())
}

fn at_least(version: &str, minimum: &str) -> bool {
    Process::new("dpkg")
        .args(["--compare-versions", version, "ge", minimum])
        .status()
        .is_ok_and(|status| status.success())
}

pub fn install_package(input: &PackageInput) -> Result<Package, PackageError> {
    let mut changed = false;
    let setting = &input.kernel_setting_path;
    if let Ok(metadata) = fs::symlink_metadata(setting) {
        if !metadata.is_file()
            || metadata.uid() != 0
            || metadata.gid() != 0
            || metadata.mode() & 0o7777 != 0o644
        {
            return Err(PackageError::Unsafe {});
        }
    }
    let body = format!("{}\n", input.kernel_setting);
    let staged = std::env::temp_dir().join(format!(
        "orbit-caddy-sysctl-{:016x}.conf",
        rand::random::<u64>()
    ));
    fs::write(&staged, &body).map_err(package_failed)?;
    let loaded = run_checked(
        "sysctl",
        &["--quiet", &format!("--load={}", staged.display())],
    );
    let _ = fs::remove_file(&staged);
    loaded?;
    if fs::read_to_string(setting).ok().as_deref() != Some(body.as_str()) {
        fs::write(setting, &body).map_err(package_failed)?;
        chown(setting, 0, 0).map_err(package_failed)?;
        fs::set_permissions(setting, fs::Permissions::from_mode(0o644)).map_err(package_failed)?;
        changed = true;
    }
    for legacy in &input.legacy_paths {
        if fs::symlink_metadata(legacy).is_ok() {
            fs::remove_file(legacy).map_err(package_failed)?;
            changed = true;
        }
    }
    let mut version = installed_version();
    if !version
        .as_deref()
        .is_some_and(|version| at_least(version, &input.minimum_version))
    {
        let architecture = run_checked("dpkg", &["--print-architecture"])?
            .trim()
            .to_string();
        let expected = match architecture.as_str() {
            "amd64" => &input.amd64_sha512,
            "arm64" => &input.arm64_sha512,
            other => {
                return Err(package_failed(format!(
                    "Orbit pins no Caddy package for the {other} architecture."
                )))
            }
        };
        let directory =
            std::env::temp_dir().join(format!("orbit-caddy-{:016x}", rand::random::<u64>()));
        fs::DirBuilder::new()
            .mode(0o755)
            .create(&directory)
            .map_err(package_failed)?;
        let result = (|| {
            let file = format!("caddy_{}_linux_{architecture}.deb", input.release);
            let package = directory.join(&file);
            let package_text = package.to_string_lossy().into_owned();
            let url = format!("{}/{file}", input.release_url);
            run_checked(
                "curl",
                &[
                    "--fail",
                    "--silent",
                    "--show-error",
                    "--location",
                    "--proto",
                    "=https",
                    "--tlsv1.2",
                    "--output",
                    &package_text,
                    &url,
                ],
            )?;
            let digest = format!(
                "{:x}",
                sha2::Sha512::digest(fs::read(&package).map_err(package_failed)?)
            );
            if &digest != expected {
                return Err(package_failed(format!(
                    "The Caddy {} package does not match the Orbit pin.",
                    input.release
                )));
            }
            fs::set_permissions(&package, fs::Permissions::from_mode(0o644))
                .map_err(package_failed)?;
            let status = Process::new("apt-get")
                .args([
                    "-o",
                    "DPkg::Lock::Timeout=300",
                    "-o",
                    "Dpkg::Options::=--force-confold",
                    "install",
                    "--yes",
                    "--no-install-recommends",
                    "--no-remove",
                    "--",
                    &package_text,
                ])
                .env("DEBIAN_FRONTEND", "noninteractive")
                .stdin(Stdio::null())
                .stdout(Stdio::null())
                .status()
                .map_err(package_failed)?;
            if !status.success() {
                return Err(package_failed(
                    "apt-get could not install the Caddy package",
                ));
            }
            Ok(())
        })();
        let _ = fs::remove_dir_all(&directory);
        result?;
        changed = true;
        version = installed_version();
    }
    let version = version.ok_or_else(|| package_failed("Caddy reported no version."))?;
    if !at_least(&version, &input.minimum_version) {
        return Err(package_failed(format!(
            "Caddy {version} is older than the {} Orbit renders against.",
            input.minimum_version
        )));
    }
    Ok(Package { changed, version })
}

pub struct InstallPackage;

impl Command for InstallPackage {
    const NAME: &'static str = "caddy.install-package";
    const SUMMARY: &'static str =
        "Install the pinned Caddy release when the Node's Caddy is older than Orbit's floor.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(600);
    type Input = PackageInput;
    type Output = Package;
    type Error = PackageError;

    /// Packages and kernel settings belong to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(_: &PackageInput) -> Option<String> {
        Some("caddy-package".into())
    }

    const RESOURCES: &'static [&'static str] = &["apt"];

    fn run(input: PackageInput, _: &Context) -> Result<Package, PackageError> {
        install_package(&input)
    }
}

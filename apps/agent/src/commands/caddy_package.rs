//! Pinned Caddy package installation and the kernel setting needed for graceful reloads.
use super::recipe::{self, Files, Host, Invocation, Native, WorkDir};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{MetadataExt, PermissionsExt},
    time::Duration,
};

command_errors! {
    pub enum PackageError {
        Invalid {} => "caddy.package_invalid", "Caddy package pins are invalid.";
        Unsafe {} => "caddy.package_unsafe", "An Orbit Caddy file has unsafe ownership or mode.";
        Failed { reason: String } => "caddy.package_failed", "The Caddy package could not be installed.";
    }
}
fn failed(reason: impl ToString) -> PackageError {
    PackageError::Failed {
        reason: reason.to_string(),
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PackageInput {
    pub release: String,
    pub amd64_sha512: String,
    /// The oldest Caddy release Orbit renders against.
    pub minimum_version: String,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Package {
    pub changed: bool,
    pub version: String,
}

const SETTING: &str = "/etc/sysctl.d/60-orbit-caddy.conf";
const CONTENT: &str = "net.ipv4.tcp_migrate_req = 1";
const LEGACY: [&str; 2] = [
    "/usr/share/keyrings/orbit-caddy.gpg",
    "/etc/apt/sources.list.d/orbit-caddy.sources",
];
fn version(value: &str) -> bool {
    value.split('.').count() == 3
        && value
            .split('.')
            .all(|part| !part.is_empty() && part.bytes().all(|b| b.is_ascii_digit()))
}
fn validate(input: &PackageInput) -> Result<(), PackageError> {
    if !version(&input.release)
        || !version(&input.minimum_version)
        || input.amd64_sha512.len() != 128
        || !input
            .amd64_sha512
            .bytes()
            .all(|b| b.is_ascii_hexdigit() && !b.is_ascii_uppercase())
    {
        return Err(PackageError::Invalid {});
    }
    Ok(())
}
fn preflight(files: &Files) -> Result<(), PackageError> {
    for directory in [
        "/etc",
        "/etc/sysctl.d",
        "/etc/apt",
        "/etc/apt/sources.list.d",
        "/usr",
        "/usr/share",
        "/usr/share/keyrings",
    ] {
        let meta = fs::symlink_metadata(files.path(directory)).map_err(failed)?;
        if !meta.is_dir() || meta.uid() != files.uid {
            return Err(PackageError::Unsafe {});
        }
    }
    for name in [SETTING, LEGACY[0], LEGACY[1]] {
        match fs::symlink_metadata(files.path(name)) {
            Ok(meta)
                if meta.is_file()
                    && meta.uid() == files.uid
                    && (name != SETTING
                        || meta.gid() == files.gid && meta.mode() & 0o7777 == 0o644) => {}
            Ok(_) => return Err(PackageError::Unsafe {}),
            Err(e) if e.kind() == io::ErrorKind::NotFound => {}
            Err(e) => return Err(failed(e)),
        }
    }
    Ok(())
}
fn run(host: &mut impl Host, argv: &[&str]) -> Result<String, PackageError> {
    let result = recipe::run(host, &Invocation::new(argv), true).map_err(|e| {
        let detail = e.diagnostic.map(|d| d.stderr).unwrap_or(e.reason);
        failed(format!("{} failed: {}", argv[0], detail.trim()))
    })?;
    Ok(result.stdout)
}
fn installed(host: &mut impl Host) -> Result<Option<String>, PackageError> {
    let result = recipe::run(host, &Invocation::new(&["caddy", "version"]), false)
        .map_err(|_| failed("Could not inspect Caddy."))?;
    if result.code == 127 {
        return Ok(None);
    }
    if result.code != 0 {
        return Err(failed("Caddy could not report its version."));
    }
    let first = result
        .stdout
        .split_whitespace()
        .next()
        .unwrap_or("")
        .trim_start_matches('v');
    if !version(first) {
        return Err(failed("Caddy reported no valid version."));
    }
    Ok(Some(first.into()))
}
fn at_least(host: &mut impl Host, version: &str, minimum: &str) -> Result<bool, PackageError> {
    let result = recipe::run(
        host,
        &Invocation::new(&["dpkg", "--compare-versions", version, "ge", minimum]),
        false,
    )
    .map_err(|_| failed("Could not compare Caddy versions."))?;
    match result.code {
        0 => Ok(true),
        1 => Ok(false),
        _ => Err(failed("Could not compare Caddy versions.")),
    }
}
fn install(
    input: &PackageInput,
    files: &Files,
    host: &mut impl Host,
) -> Result<Package, PackageError> {
    validate(input)?;
    preflight(files)?;
    let mut current = installed(host)?;
    let needs_package = match &current {
        Some(v) => !at_least(host, v, &input.minimum_version)?,
        None => true,
    };
    let work = WorkDir::new().map_err(|e| failed(e.reason))?;
    let package = if needs_package {
        let architecture = run(host, &["dpkg", "--print-architecture"])?;
        let architecture = architecture.trim();
        if architecture != "amd64" {
            return Err(failed(format!(
                "Orbit pins no Caddy package for the {architecture} architecture."
            )));
        }
        let expected = &input.amd64_sha512;
        let name = format!("caddy_{}_linux_{architecture}.deb", input.release);
        let path = work.0.join(&name);
        let url = format!(
            "https://github.com/caddyserver/caddy/releases/download/v{}/{name}",
            input.release
        );
        run(
            host,
            &[
                "curl",
                "--fail",
                "--silent",
                "--show-error",
                "--location",
                "--proto",
                "=https",
                "--proto-redir",
                "=https",
                "--tlsv1.2",
                "--max-filesize",
                "536870912",
                "--output",
                path.to_str().unwrap(),
                &url,
            ],
        )?;
        let artifact = super::recipe::types::Artifact {
            url: Some(url),
            sha512: Some(expected.clone()),
            sha256: None,
            filename: None,
            version: None,
        };
        super::recipe::artifacts::verify(&path, &artifact).map_err(|_| {
            failed(format!(
                "The Caddy {} package does not match the Orbit pin.",
                input.release
            ))
        })?;
        Some(path)
    } else {
        None
    };
    let setting = files.path(SETTING);
    let body = format!("{CONTENT}\n");
    let staged = work.0.join("sysctl.conf");
    fs::write(&staged, &body).map_err(failed)?;
    run(
        host,
        &["sysctl", "--quiet", &format!("--load={}", staged.display())],
    )?;
    let mut changed = fs::read_to_string(&setting).ok().as_deref() != Some(body.as_str());
    if changed {
        files
            .publish_root(&setting, body.as_bytes())
            .map_err(|e| failed(e.reason))?;
    }
    for name in LEGACY {
        match fs::remove_file(files.path(name)) {
            Ok(()) => changed = true,
            Err(e) if e.kind() == io::ErrorKind::NotFound => {}
            Err(e) => return Err(failed(e)),
        }
    }
    if let Some(path) = package {
        // apt's sandbox user must be able to read the verified local package.
        fs::set_permissions(&work.0, fs::Permissions::from_mode(0o755)).map_err(failed)?;
        fs::set_permissions(&path, fs::Permissions::from_mode(0o644)).map_err(failed)?;
        recipe::run(
            host,
            &Invocation::new(&[
                "apt-get",
                "-o",
                "DPkg::Lock::Timeout=300",
                "-o",
                "Dpkg::Options::=--force-confold",
                "install",
                "--yes",
                "--no-install-recommends",
                "--no-remove",
                "--",
                path.to_str().unwrap(),
            ])
            .env("DEBIAN_FRONTEND", "noninteractive"),
            true,
        )
        .map_err(|_| failed("apt-get could not install the Caddy package"))?;
        current = installed(host)?;
        changed = true;
    }
    let current = current.ok_or_else(|| failed("Caddy reported no version."))?;
    if !at_least(host, &current, &input.minimum_version)? {
        return Err(failed(format!(
            "Caddy {current} is older than the {} Orbit renders against.",
            input.minimum_version
        )));
    }
    Ok(Package {
        changed,
        version: current,
    })
}

pub struct InstallPackage;
impl Command for InstallPackage {
    const NAME: &'static str = "caddy.install-package";
    const SUMMARY: &'static str =
        "Install the pinned Caddy package when the Node is below the version floor.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(600);
    const RESOURCES: &'static [&'static str] = &["packages"];
    type Input = PackageInput;
    type Output = Package;
    type Error = PackageError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &PackageInput) -> Option<String> {
        Some("caddy-package".into())
    }
    fn run(input: PackageInput, context: &Context) -> Result<Package, PackageError> {
        install(&input, &Files::system(), &mut Native(context))
    }
}
#[cfg(test)]
mod tests;

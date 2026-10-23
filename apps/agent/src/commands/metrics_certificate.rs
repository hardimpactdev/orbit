//! `metrics.certificate`: the Metrics site's Orbit CA certificate on the Gateway's Node, in versioned directories
//! under `/etc/caddy/orbit-metrics-cert-versions` that a `current` link selects. A publish keeps the previous version
//! until Caddy reloads, and a failed reload puts it back; a restore undoes a publish that a later step rolled back.

use super::caddy::{chown, group_id, lock, public_key};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{symlink, DirBuilderExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum MetricsCertificateError {
        Failed { reason: String } => "metrics.certificate_failed", "The Metrics certificate step did not complete.";
    }
}

fn failed(reason: impl ToString) -> MetricsCertificateError {
    MetricsCertificateError::Failed {
        reason: reason.to_string(),
    }
}

const VERSIONS: &str = "/etc/caddy/orbit-metrics-cert-versions";
const CURRENT: &str = "/etc/caddy/orbit-metrics-cert-current";
const OWNER_MARK: &str = "metrics-certificate\n";

#[derive(Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum MetricsCertificateInput {
    Publish {
        version: String,
        certificate: String,
        key: String,
    },
    /// Undoes a publish: `created` removes the link, `replaced` points it back at `previous`.
    Restore {
        change: String,
        previous: Option<PathBuf>,
    },
    Remove,
}

#[derive(Debug, Serialize, PartialEq)]
#[serde(untagged)]
pub enum MetricsCertificateOutput {
    /// `unchanged`, `created`, or `replaced` with the link target it replaced.
    Published {
        change: &'static str,
        previous: Option<PathBuf>,
    },
    Done {},
}

fn inside_versions(path: &Path) -> bool {
    path.starts_with(VERSIONS) && path != Path::new(VERSIONS)
}

/// The versions directory carries Orbit's owner mark, or does not exist yet.
fn owned_versions() -> Result<bool, MetricsCertificateError> {
    let versions = Path::new(VERSIONS);
    if fs::symlink_metadata(versions).is_err() {
        return Ok(false);
    }
    let marked = versions.is_dir()
        && fs::read_to_string(versions.join(".orbit-owner"))
            .ok()
            .as_deref()
            == Some(OWNER_MARK);
    if marked {
        Ok(true)
    } else {
        Err(failed(
            "the Metrics certificate versions do not carry Orbit's owner mark",
        ))
    }
}

/// The resolved target of `current`, which must lie in the versions directory, or none without a link.
fn current_target() -> Result<Option<(PathBuf, PathBuf)>, MetricsCertificateError> {
    let current = Path::new(CURRENT);
    match fs::symlink_metadata(current) {
        Err(_) => Ok(None),
        Ok(metadata) if metadata.file_type().is_symlink() => {
            let link = fs::read_link(current).map_err(failed)?;
            let resolved = fs::canonicalize(current).map_err(failed)?;
            if !inside_versions(&resolved) {
                return Err(failed(
                    "the current Metrics certificate lies outside its versions",
                ));
            }
            Ok(Some((link, resolved)))
        }
        Ok(_) => Err(failed("the current Metrics certificate is not a link")),
    }
}

fn point_current(target: &Path) -> io::Result<()> {
    let staged = PathBuf::from(format!("{CURRENT}.candidate"));
    let _ = fs::remove_file(&staged);
    symlink(target, &staged)?;
    fs::rename(&staged, CURRENT)
}

fn caddy_is_active() -> bool {
    Process::new("systemctl")
        .args(["is-active", "--quiet", "caddy"])
        .status()
        .is_ok_and(|status| status.success())
}

fn reload() -> bool {
    Process::new("systemctl")
        .args(["reload-or-restart", "caddy"])
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok_and(|status| status.success())
}

fn write_file(path: &Path, content: &str, caddy: u32) -> io::Result<()> {
    fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o640)
        .open(path)
        .and_then(|mut file| io::Write::write_all(&mut file, content.as_bytes()))?;
    chown(path, 0, caddy)?;
    fs::set_permissions(path, fs::Permissions::from_mode(0o640))
}

fn publish(
    version: &str,
    certificate: &str,
    key: &str,
) -> Result<MetricsCertificateOutput, MetricsCertificateError> {
    if version.is_empty() || !version.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return Err(failed("the version is not a hexadecimal name"));
    }
    let caddy = group_id("caddy").ok_or_else(|| failed("the caddy group does not exist"))?;
    if !owned_versions()? {
        fs::DirBuilder::new()
            .mode(0o750)
            .create(VERSIONS)
            .map_err(failed)?;
        chown(Path::new(VERSIONS), 0, caddy).map_err(failed)?;
        write_file(&Path::new(VERSIONS).join(".orbit-owner"), OWNER_MARK, caddy).map_err(failed)?;
    }
    let previous = current_target()?;
    if let Some((_, resolved)) = &previous {
        let same = |name: &str, content: &str| {
            fs::read(resolved.join(name)).ok().as_deref() == Some(content.as_bytes())
        };
        if same("metrics.pem", certificate) && same("metrics.key", key) {
            return Ok(MetricsCertificateOutput::Published {
                change: "unchanged",
                previous: None,
            });
        }
    }
    let directory = Path::new(VERSIONS).join(version);
    let candidate = Path::new(VERSIONS).join(format!("{version}.candidate"));
    let _ = fs::remove_dir_all(&candidate);
    let staged = (|| {
        fs::DirBuilder::new()
            .mode(0o750)
            .create(&candidate)
            .map_err(failed)?;
        chown(&candidate, 0, caddy).map_err(failed)?;
        write_file(&candidate.join("metrics.pem"), certificate, caddy).map_err(failed)?;
        write_file(&candidate.join("metrics.key"), key, caddy).map_err(failed)?;
        let certificate_key = public_key("certificate", &candidate.join("metrics.pem"));
        if certificate_key.is_none()
            || certificate_key != public_key("key", &candidate.join("metrics.key"))
        {
            return Err(failed("the key does not belong to the certificate"));
        }
        fs::rename(&candidate, &directory).map_err(failed)
    })();
    if let Err(error) = staged {
        let _ = fs::remove_dir_all(&candidate);
        return Err(error);
    }
    point_current(&directory).map_err(failed)?;
    if caddy_is_active() && !reload() {
        match &previous {
            Some((link, _)) => {
                let _ = point_current(link);
            }
            None => {
                let _ = fs::remove_file(CURRENT);
            }
        }
        reload();
        let _ = fs::remove_dir_all(&directory);
        return Err(failed(
            "Caddy did not reload with the new Metrics certificate",
        ));
    }
    Ok(match previous {
        Some((link, _)) => MetricsCertificateOutput::Published {
            change: "replaced",
            previous: Some(link),
        },
        None => MetricsCertificateOutput::Published {
            change: "created",
            previous: None,
        },
    })
}

fn restore(
    change: &str,
    previous: Option<&Path>,
) -> Result<MetricsCertificateOutput, MetricsCertificateError> {
    if !owned_versions()? {
        return Err(failed("the Metrics certificate versions do not exist"));
    }
    let (published_link, published) =
        current_target()?.ok_or_else(|| failed("no Metrics certificate is current"))?;
    match (change, previous) {
        ("created", None) => fs::remove_file(CURRENT).map_err(failed)?,
        ("replaced", Some(previous)) => {
            let resolved = fs::canonicalize(previous).map_err(failed)?;
            if !inside_versions(&resolved) || !resolved.is_dir() {
                return Err(failed(
                    "the previous Metrics certificate lies outside its versions",
                ));
            }
            point_current(previous).map_err(failed)?;
        }
        _ => return Err(failed("the change to restore is not valid")),
    }
    if caddy_is_active() && !reload() {
        let _ = point_current(&published_link);
        reload();
        return Err(failed(
            "Caddy did not reload with the restored Metrics certificate",
        ));
    }
    fs::remove_dir_all(&published).map_err(failed)?;
    Ok(MetricsCertificateOutput::Done {})
}

fn remove() -> Result<MetricsCertificateOutput, MetricsCertificateError> {
    let current_exists = fs::symlink_metadata(CURRENT).is_ok();
    if fs::symlink_metadata(VERSIONS).is_err() && !current_exists {
        return Ok(MetricsCertificateOutput::Done {});
    }
    if !owned_versions()? {
        return Err(failed("the Metrics certificate versions do not exist"));
    }
    current_target()?;
    if current_exists {
        fs::remove_file(CURRENT).map_err(failed)?;
    }
    fs::remove_dir_all(VERSIONS).map_err(failed)?;
    Ok(MetricsCertificateOutput::Done {})
}

pub fn run(
    input: &MetricsCertificateInput,
) -> Result<MetricsCertificateOutput, MetricsCertificateError> {
    let _lock = lock().map_err(failed)?;
    match input {
        MetricsCertificateInput::Publish {
            version,
            certificate,
            key,
        } => publish(version, certificate, key),
        MetricsCertificateInput::Restore { change, previous } => {
            restore(change, previous.as_deref())
        }
        MetricsCertificateInput::Remove => remove(),
    }
}

pub struct MetricsCertificate;

impl Command for MetricsCertificate {
    const NAME: &'static str = "metrics.certificate";
    const SUMMARY: &'static str =
        "Publish, restore, or remove the Metrics site's certificate for Caddy.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = MetricsCertificateInput;
    type Output = MetricsCertificateOutput;
    type Error = MetricsCertificateError;

    /// Caddy's certificate directories and its reload belong to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(
        input: MetricsCertificateInput,
        _: &Context,
    ) -> Result<MetricsCertificateOutput, MetricsCertificateError> {
        run(&input)
    }
}

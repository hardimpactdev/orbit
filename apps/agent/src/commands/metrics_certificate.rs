//! `metrics.certificate`: the Metrics site's Orbit CA certificate on the Gateway's Node, in versioned directories
//! under `/etc/caddy/orbit-metrics-cert-versions` that a `current` link selects. A publish keeps the previous version
//! until Caddy reloads, and a failed reload puts it back; a restore undoes a publish that a later step rolled back.
//! Every operation holds the Caddy publication lock and uses the Caddy runtime of the shared certificate commands.

use super::caddy::{chown, group_id, lock};
use super::caddy_certificate::{Native, Runtime};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{symlink, DirBuilderExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
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

#[derive(schemars::JsonSchema, Deserialize)]
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
    Remove {},
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
#[serde(untagged)]
pub enum MetricsCertificateOutput {
    /// `unchanged`, `created`, or `replaced` with the link target it replaced.
    Published {
        change: &'static str,
        previous: Option<PathBuf>,
    },
    Done {},
}

/// The fixed paths under a root that only Rust callers choose: `/` in production, a temporary directory in tests.
/// `uid` owns every file Orbit writes.
struct Paths {
    root: PathBuf,
    uid: u32,
}

impl Paths {
    fn versions(&self) -> PathBuf {
        self.root.join(VERSIONS.trim_start_matches('/'))
    }

    fn current(&self) -> PathBuf {
        self.root.join(CURRENT.trim_start_matches('/'))
    }

    fn inside_versions(&self, path: &Path) -> bool {
        let versions = self.versions();
        path.starts_with(&versions) && path != versions
    }

    /// The versions directory carries Orbit's owner mark, or does not exist yet.
    fn owned_versions(&self) -> Result<bool, MetricsCertificateError> {
        let versions = self.versions();
        if fs::symlink_metadata(&versions).is_err() {
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

    /// The link and resolved target of `current`, which must lie in the versions directory, or none without a link.
    fn current_target(&self) -> Result<Option<(PathBuf, PathBuf)>, MetricsCertificateError> {
        let current = self.current();
        match fs::symlink_metadata(&current) {
            Err(_) => Ok(None),
            Ok(metadata) if metadata.file_type().is_symlink() => {
                let link = fs::read_link(&current).map_err(failed)?;
                let resolved = fs::canonicalize(&current).map_err(failed)?;
                if !self.inside_versions(&resolved) {
                    return Err(failed(
                        "the current Metrics certificate lies outside its versions",
                    ));
                }
                Ok(Some((link, resolved)))
            }
            Ok(_) => Err(failed("the current Metrics certificate is not a link")),
        }
    }

    fn point_current(&self, target: &Path) -> io::Result<()> {
        let current = self.current();
        let staged = PathBuf::from(format!("{}.candidate", current.display()));
        let _ = fs::remove_file(&staged);
        symlink(target, &staged)?;
        fs::rename(&staged, current)
    }

    /// Writes a new `0640` file that the caddy group `gid` reads.
    fn write_file(&self, path: &Path, content: &str, gid: u32) -> io::Result<()> {
        fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o640)
            .open(path)
            .and_then(|mut file| io::Write::write_all(&mut file, content.as_bytes()))?;
        chown(path, self.uid, gid)?;
        fs::set_permissions(path, fs::Permissions::from_mode(0o640))
    }

    /// Creates a new `0750` directory that the caddy group `gid` reads.
    fn create_directory(&self, path: &Path, gid: u32) -> io::Result<()> {
        fs::DirBuilder::new().mode(0o750).create(path)?;
        chown(path, self.uid, gid)
    }
}

/// The caller holds the Caddy publication lock through the change, the reload, and any rollback.
fn publish(
    paths: &Paths,
    gid: u32,
    runtime: &mut impl Runtime,
    version: &str,
    certificate: &str,
    key: &str,
) -> Result<MetricsCertificateOutput, MetricsCertificateError> {
    if version.is_empty() || !version.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return Err(failed("the version is not a hexadecimal name"));
    }
    let versions = paths.versions();
    if !paths.owned_versions()? {
        paths.create_directory(&versions, gid).map_err(failed)?;
        paths
            .write_file(&versions.join(".orbit-owner"), OWNER_MARK, gid)
            .map_err(failed)?;
    }
    let previous = paths.current_target()?;
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
    let directory = versions.join(version);
    if previous
        .as_ref()
        .is_some_and(|(_, resolved)| resolved == &directory)
    {
        return Err(failed(
            "the version is current with another Metrics certificate",
        ));
    }
    let active = runtime
        .active()
        .map_err(|_| failed("the Caddy service state is not readable"))?;
    // A directory under this version that `current` does not select is left from an earlier attempt.
    match fs::remove_dir_all(&directory) {
        Err(error) if error.kind() != io::ErrorKind::NotFound => return Err(failed(error)),
        _ => {}
    }
    let candidate = versions.join(format!("{version}.candidate"));
    let _ = fs::remove_dir_all(&candidate);
    let staged = (|| {
        paths.create_directory(&candidate, gid).map_err(failed)?;
        paths
            .write_file(&candidate.join("metrics.pem"), certificate, gid)
            .map_err(failed)?;
        paths
            .write_file(&candidate.join("metrics.key"), key, gid)
            .map_err(failed)?;
        if !runtime.valid(
            &candidate.join("metrics.pem"),
            &candidate.join("metrics.key"),
        ) {
            return Err(failed("the key does not belong to the certificate"));
        }
        fs::rename(&candidate, &directory).map_err(failed)
    })();
    if let Err(error) = staged {
        let _ = fs::remove_dir_all(&candidate);
        return Err(error);
    }
    paths.point_current(&directory).map_err(failed)?;
    if active && runtime.reload().is_err() {
        match &previous {
            Some((link, _)) => {
                let _ = paths.point_current(link);
            }
            None => {
                let _ = fs::remove_file(paths.current());
            }
        }
        let _ = runtime.reload();
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

/// The caller holds the Caddy publication lock, as for [`publish`].
fn restore(
    paths: &Paths,
    runtime: &mut impl Runtime,
    change: &str,
    previous: Option<&Path>,
) -> Result<MetricsCertificateOutput, MetricsCertificateError> {
    if !paths.owned_versions()? {
        return Err(failed("the Metrics certificate versions do not exist"));
    }
    let (published_link, published) = paths
        .current_target()?
        .ok_or_else(|| failed("no Metrics certificate is current"))?;
    let previous = match (change, previous) {
        ("created", None) => None,
        ("replaced", Some(previous)) => {
            let resolved = fs::canonicalize(previous).map_err(failed)?;
            if !paths.inside_versions(&resolved) || !resolved.is_dir() {
                return Err(failed(
                    "the previous Metrics certificate lies outside its versions",
                ));
            }
            Some(previous)
        }
        _ => return Err(failed("the change to restore is not valid")),
    };
    let active = runtime
        .active()
        .map_err(|_| failed("the Caddy service state is not readable"))?;
    match previous {
        None => fs::remove_file(paths.current()).map_err(failed)?,
        Some(previous) => paths.point_current(previous).map_err(failed)?,
    }
    if active && runtime.reload().is_err() {
        let _ = paths.point_current(&published_link);
        let _ = runtime.reload();
        return Err(failed(
            "Caddy did not reload with the restored Metrics certificate",
        ));
    }
    fs::remove_dir_all(&published).map_err(failed)?;
    Ok(MetricsCertificateOutput::Done {})
}

fn remove(paths: &Paths) -> Result<MetricsCertificateOutput, MetricsCertificateError> {
    let current = paths.current();
    let current_exists = fs::symlink_metadata(&current).is_ok();
    if fs::symlink_metadata(paths.versions()).is_err() && !current_exists {
        return Ok(MetricsCertificateOutput::Done {});
    }
    if !paths.owned_versions()? {
        return Err(failed("the Metrics certificate versions do not exist"));
    }
    paths.current_target()?;
    if current_exists {
        fs::remove_file(&current).map_err(failed)?;
    }
    fs::remove_dir_all(paths.versions()).map_err(failed)?;
    Ok(MetricsCertificateOutput::Done {})
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
        let _lock = lock().map_err(failed)?;
        let paths = Paths {
            root: "/".into(),
            uid: 0,
        };
        match input {
            MetricsCertificateInput::Publish {
                version,
                certificate,
                key,
            } => {
                let gid =
                    group_id("caddy").ok_or_else(|| failed("the caddy group does not exist"))?;
                publish(&paths, gid, &mut Native, &version, &certificate, &key)
            }
            MetricsCertificateInput::Restore { change, previous } => {
                restore(&paths, &mut Native, &change, previous.as_deref())
            }
            MetricsCertificateInput::Remove {} => remove(&paths),
        }
    }
}

#[cfg(test)]
#[path = "metrics_certificate_tests.rs"]
mod tests;

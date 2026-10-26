//! The fixed ClickHouse configuration shipped by Plausible. Process intent stays in the Gateway.
use super::recipe::Files;
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::{self, Read},
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    time::Duration,
};

const ROOT: &str = "/etc/orbit/analytics/clickhouse";
const FILES: &[(&str, &[u8])] = &[
    ("config.d/logs.xml", include_bytes!("../../resources/analytics/clickhouse/config.d/logs.xml")),
    ("config.d/ipv4-only.xml", include_bytes!("../../resources/analytics/clickhouse/config.d/ipv4-only.xml")),
    ("config.d/low-resources.xml", include_bytes!("../../resources/analytics/clickhouse/config.d/low-resources.xml")),
    ("users.d/default-profile-low-resources-overrides.xml", include_bytes!("../../resources/analytics/clickhouse/users.d/default-profile-low-resources-overrides.xml")),
];
const DIRECTORIES: &[&str] = &[
    "/etc",
    "/etc/orbit",
    "/etc/orbit/analytics",
    ROOT,
    "/etc/orbit/analytics/clickhouse/config.d",
    "/etc/orbit/analytics/clickhouse/users.d",
];
command_errors! {
    pub enum Error {
        Unsafe { path: String } => "analytics.clickhouse_config_unsafe", "The ClickHouse configuration path is not owned by Orbit.";
        Failed { stage: String } => "analytics.clickhouse_config_failed", "The ClickHouse configuration could not be published.";
        Recovery { path: String } => "analytics.clickhouse_recovery_failed", "The previous ClickHouse configuration could not be restored.";
    }
}
fn unsafe_path(path: &Path) -> Error {
    Error::Unsafe {
        path: path.display().to_string(),
    }
}
fn failed(stage: &str) -> Error {
    Error::Failed {
        stage: stage.into(),
    }
}
fn metadata(path: &Path) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(meta) => Ok(Some(meta)),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(failed("inspect")),
    }
}
fn previous(files: &Files, path: &Path) -> Result<Option<Vec<u8>>, Error> {
    let Some(meta) = metadata(path)? else {
        return Ok(None);
    };
    if !meta.is_file()
        || meta.uid() != files.uid
        || meta.gid() != files.gid
        || meta.mode() & 0o7777 != 0o644
        || meta.len() > 1024 * 1024
    {
        return Err(unsafe_path(path));
    }
    let file = fs::OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW)
        .open(path)
        .map_err(|_| failed("read"))?;
    let mut bytes = vec![];
    file.take(1024 * 1024 + 1)
        .read_to_end(&mut bytes)
        .map_err(|_| failed("read"))?;
    if bytes.len() > 1024 * 1024 {
        return Err(unsafe_path(path));
    }
    Ok(Some(bytes))
}
trait Publisher {
    fn publish(&mut self, files: &Files, path: &Path, bytes: &[u8]) -> Result<(), Error>;
}
struct Native;
impl Publisher for Native {
    fn publish(&mut self, files: &Files, path: &Path, bytes: &[u8]) -> Result<(), Error> {
        files
            .publish_root(path, bytes)
            .map_err(|_| failed("publish"))
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Output {
    changed: bool,
}
fn configure(files: &Files, publisher: &mut impl Publisher) -> Result<Output, Error> {
    // Inspect every parent and file before creating directories or publishing the first file.
    for directory in DIRECTORIES {
        let path = files.path(directory);
        if let Some(meta) = metadata(&path)? {
            if !meta.is_dir() || meta.uid() != files.uid || meta.mode() & 0o022 != 0 {
                return Err(unsafe_path(&path));
            }
        }
    }
    let mut snapshots = Vec::new();
    for (name, content) in FILES {
        let path = files.path(ROOT).join(name);
        let old = previous(files, &path)?;
        if old.as_deref() != Some(*content) {
            snapshots.push((path, old, *content));
        }
    }
    for directory in DIRECTORIES {
        let path = files.path(directory);
        if metadata(&path)?.is_none() {
            files
                .directory(&path, files.uid, files.gid)
                .map_err(|_| failed("directory"))?;
            fs::set_permissions(&path, fs::Permissions::from_mode(0o755))
                .map_err(|_| failed("directory"))?;
        }
    }
    let mut published: Vec<(&PathBuf, &Option<Vec<u8>>)> = vec![];
    for (path, old, content) in &snapshots {
        if let Err(error) = publisher.publish(files, path, content) {
            let mut recovery = None;
            for (path, old) in published.into_iter().rev() {
                let result = match old {
                    Some(bytes) => publisher.publish(files, path, bytes),
                    None => fs::remove_file(path).map_err(|_| failed("restore")),
                };
                if result.is_err() {
                    recovery = Some(path.display().to_string());
                }
            }
            return Err(recovery.map_or(error, |path| Error::Recovery { path }));
        }
        published.push((path, old));
    }
    Ok(Output {
        changed: !snapshots.is_empty(),
    })
}
pub struct Configure;
impl Command for Configure {
    const NAME: &'static str = "analytics.configure-clickhouse";
    const SUMMARY: &'static str = "Publish Plausible's four fixed ClickHouse configuration files.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("analytics-clickhouse".into())
    }
    fn run(_: Input, _: &Context) -> Result<Output, Error> {
        configure(&Files::system(), &mut Native)
    }
}
#[cfg(test)]
#[path = "analytics_clickhouse_tests.rs"]
mod tests;

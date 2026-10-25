//! Shared distribution PHP-FPM pools: discover, retire moving sockets, publish, and recover as one mutation.
use super::php_runtime::valid_version as version;
use super::recipe::{self, Files, Host, Invocation, Native};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    collections::{BTreeMap, BTreeSet},
    fs, io,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "php.shared_pool_invalid", "The shared PHP pool configuration is invalid.";
        Unsafe {} => "php.shared_pool_unsafe", "Shared PHP configuration needs owned regular files and directories.";
        Failed { stage: String } => "php.shared_pool_failed", "Shared PHP pool convergence failed.";
        RecoveryFailed {} => "php.shared_pool_recovery_failed", "The previous shared PHP pools or service state could not be restored.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub configurations: BTreeMap<String, String>,
}
#[derive(schemars::JsonSchema, Debug, Clone, PartialEq, Serialize)]
pub struct MissingPool {
    pub pool: String,
    pub version: String,
    pub directory: String,
    pub installed: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Inspection {
    pub versions: Vec<String>,
    pub missing_pools: Vec<MissingPool>,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Output {
    pub changed: bool,
    pub missing_pools: Vec<MissingPool>,
}
fn failed(stage: &str) -> Error {
    Error::Failed {
        stage: stage.into(),
    }
}
#[derive(Clone)]
struct Pool {
    name: String,
    directory: Option<String>,
    text: String,
}
#[derive(Clone, Default)]
struct Configuration {
    prefix: String,
    pools: Vec<Pool>,
}
impl Configuration {
    fn parse(text: &str) -> Result<Self, Error> {
        if text.len() > 1024 * 1024 || text.contains('\0') {
            return Err(Error::Invalid {});
        }
        let pool_name = regex::Regex::new(
            r"^orbit-(?:instance|workspace|app-instance)-[1-9][0-9]*(?:-[0-9a-f]{8})?$",
        )
        .unwrap();
        let mut result = Self::default();
        let mut names = BTreeSet::new();
        for line in text.split_inclusive('\n') {
            let trimmed = line.trim();
            if trimmed.starts_with('[') {
                let name = trimmed
                    .strip_prefix('[')
                    .and_then(|s| s.strip_suffix(']'))
                    .ok_or(Error::Invalid {})?;
                if !pool_name.is_match(name) || !names.insert(name.to_owned()) {
                    return Err(Error::Invalid {});
                }
                result.pools.push(Pool {
                    name: name.into(),
                    directory: None,
                    text: String::new(),
                });
            }
            if let Some(pool) = result.pools.last_mut() {
                if let Some((key, value)) = trimmed.split_once('=') {
                    if key.trim() == "chdir" {
                        let directory = value.trim();
                        if !directory.starts_with('/')
                            || directory.bytes().any(|b| b.is_ascii_control())
                            || pool.directory.is_some()
                        {
                            return Err(Error::Invalid {});
                        }
                        pool.directory = Some(directory.into());
                    }
                }
                pool.text.push_str(line);
            } else if trimmed.is_empty() || trimmed.starts_with(';') || trimmed.starts_with('#') {
                result.prefix.push_str(line);
            } else {
                return Err(Error::Invalid {});
            }
        }
        Ok(result)
    }
    fn render(&self, keep: impl Fn(&Pool) -> bool) -> String {
        let pools: Vec<&Pool> = self.pools.iter().filter(|p| keep(p)).collect();
        if pools.is_empty() && !self.pools.is_empty() {
            return String::new();
        }
        let mut result = self.prefix.clone();
        for pool in pools {
            result.push_str(&pool.text);
        }
        result
    }
    fn present(&self, files: &Files) -> String {
        self.render(|p| p.directory.as_ref().is_none_or(|d| files.path(d).is_dir()))
    }
    fn missing(&self, files: &Files, version: &str, installed: bool) -> Vec<MissingPool> {
        self.pools
            .iter()
            .filter_map(|p| {
                p.directory
                    .as_ref()
                    .filter(|d| !files.path(d).is_dir())
                    .map(|d| MissingPool {
                        pool: p.name.clone(),
                        version: version.into(),
                        directory: d.clone(),
                        installed,
                    })
            })
            .collect()
    }
}
fn desired(input: &Input) -> Result<BTreeMap<String, Configuration>, Error> {
    let mut names = BTreeSet::new();
    input
        .configurations
        .iter()
        .map(|(v, text)| {
            if !version(v) {
                return Err(Error::Invalid {});
            }
            let configuration = Configuration::parse(text)?;
            for pool in &configuration.pools {
                if pool.directory.is_none() || !names.insert(pool.name.clone()) {
                    return Err(Error::Invalid {});
                }
            }
            Ok((v.clone(), configuration))
        })
        .collect()
}
fn metadata(path: &Path, uid: u32, directory: bool) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(m) if m.uid() == uid && if directory { m.is_dir() } else { m.is_file() } => Ok(Some(m)),
        Ok(_) => Err(Error::Unsafe {}),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(failed("inspect")),
    }
}
fn pool_path(files: &Files, version: &str) -> PathBuf {
    files.path(&format!("/etc/php/{version}/fpm/pool.d/orbit-scopes.conf"))
}
fn check_parents(files: &Files, version: &str) -> Result<(), Error> {
    for path in [
        "/etc".into(),
        "/etc/php".into(),
        format!("/etc/php/{version}"),
        format!("/etc/php/{version}/fpm"),
        format!("/etc/php/{version}/fpm/pool.d"),
    ] {
        metadata(&files.path(&path), files.uid, true)?.ok_or_else(|| failed("directory"))?;
    }
    Ok(())
}
struct Snapshot {
    configuration: Configuration,
    bytes: Option<String>,
    metadata: Option<fs::Metadata>,
    active: bool,
    enabled: bool,
}
fn installed(files: &Files) -> Result<BTreeMap<String, Snapshot>, Error> {
    metadata(&files.path("/etc"), files.uid, true)?.ok_or(Error::Unsafe {})?;
    if metadata(&files.path("/etc/php"), files.uid, true)?.is_none() {
        return Ok(BTreeMap::new());
    }
    let mut result = BTreeMap::new();
    for entry in fs::read_dir(files.path("/etc/php")).map_err(|_| failed("inspect"))? {
        let entry = entry.map_err(|_| failed("inspect"))?;
        let v = entry.file_name().to_string_lossy().into_owned();
        if !version(&v) {
            continue;
        }
        // Versions without FPM may contain CLI alone.
        metadata(&entry.path(), files.uid, true)?.ok_or(Error::Unsafe {})?;
        if metadata(&entry.path().join("fpm"), files.uid, true)?.is_none() {
            continue;
        }
        if metadata(&entry.path().join("fpm/pool.d"), files.uid, true)?.is_none() {
            continue;
        }
        check_parents(files, &v)?;
        let path = pool_path(files, &v);
        if let Some(m) = metadata(&path, files.uid, false)? {
            let bytes = fs::read_to_string(&path).map_err(|_| failed("read"))?;
            result.insert(
                v,
                Snapshot {
                    configuration: Configuration::parse(&bytes)?,
                    bytes: Some(bytes),
                    metadata: Some(m),
                    active: false,
                    enabled: false,
                },
            );
        }
    }
    Ok(result)
}
fn inspect(files: &Files, input: &Input) -> Result<Inspection, Error> {
    let desired = desired(input)?;
    let mut missing = vec![];
    for (v, snapshot) in installed(files)? {
        missing.extend(snapshot.configuration.missing(files, &v, true));
    }
    let mut versions = vec![];
    for (v, configuration) in desired {
        if !configuration.present(files).is_empty() {
            versions.push(v.clone());
        }
        for pool in configuration.missing(files, &v, false) {
            if !missing.iter().any(|p| p.pool == pool.pool) {
                missing.push(pool);
            }
        }
    }
    Ok(Inspection {
        versions,
        missing_pools: missing,
    })
}
fn call(
    host: &mut impl Host,
    argv: &[&str],
    stage: &str,
    require_success: bool,
) -> Result<recipe::Output, Error> {
    recipe::run(host, &Invocation::new(argv), require_success).map_err(|_| failed(stage))
}
fn control(host: &mut impl Host, action: &str, version: &str) -> Result<(), Error> {
    call(
        host,
        &["/usr/bin/systemctl", action, &format!("php{version}-fpm")],
        action,
        true,
    )?;
    Ok(())
}
fn state(host: &mut impl Host, action: &str, version: &str) -> Result<bool, Error> {
    let result = call(
        host,
        &[
            "/usr/bin/systemctl",
            action,
            "--quiet",
            &format!("php{version}-fpm"),
        ],
        action,
        false,
    )?;
    match result.code {
        0 => Ok(true),
        1 if action == "is-enabled" => Ok(false),
        3 if action == "is-active" => Ok(false),
        _ => Err(failed(action)),
    }
}
fn validate(
    files: &Files,
    host: &mut impl Host,
    version: &str,
    configuration: &str,
) -> Result<(), Error> {
    super::php_fpm::validate(files, host, version, "orbit-scopes.conf", configuration).map_err(
        |error| match error {
            super::php_fpm::Error::Invalid => Error::Invalid {},
            super::php_fpm::Error::Unsafe => Error::Unsafe {},
            super::php_fpm::Error::Failed(stage) => failed(stage),
        },
    )
}
fn publish(
    files: &Files,
    version: &str,
    text: &str,
    prior: Option<&fs::Metadata>,
) -> Result<(), Error> {
    let path = pool_path(files, version);
    if text.is_empty() && prior.is_none() {
        if metadata(&path, files.uid, false)?.is_some() {
            fs::remove_file(path).map_err(|_| failed("publish"))?;
        }
    } else if let Some(m) = prior {
        files
            .publish(
                &path,
                text.as_bytes(),
                m.mode() & 0o7777,
                m.uid(),
                m.gid(),
                false,
            )
            .map_err(|_| failed("publish"))?;
    } else {
        files
            .publish_root(&path, text.as_bytes())
            .map_err(|_| failed("publish"))?;
    }
    Ok(())
}
fn converge(files: &Files, host: &mut impl Host, input: &Input) -> Result<Output, Error> {
    let requested = desired(input)?;
    let missing_pools = requested
        .iter()
        .flat_map(|(v, c)| c.missing(files, v, false))
        .collect();
    let desired: BTreeMap<String, Configuration> = requested
        .into_iter()
        .map(|(v, c)| Ok((v, Configuration::parse(&c.present(files))?)))
        .collect::<Result<_, Error>>()?;
    let mut snapshots = installed(files)?;
    for (v, c) in &desired {
        if c.pools.is_empty() {
            continue;
        }
        check_parents(files, v)?;
        snapshots.entry(v.clone()).or_insert(Snapshot {
            configuration: Configuration::default(),
            bytes: None,
            metadata: None,
            active: false,
            enabled: false,
        });
    }
    let desired_versions: BTreeMap<&str, &str> = desired
        .iter()
        .flat_map(|(v, c)| c.pools.iter().map(move |p| (p.name.as_str(), v.as_str())))
        .collect();
    let mut moving = BTreeSet::new();
    let mut retiring = BTreeSet::new();
    let mut activating = BTreeSet::new();
    for (v, snapshot) in &snapshots {
        for pool in &snapshot.configuration.pools {
            if desired_versions.get(pool.name.as_str()).copied() != Some(v.as_str()) {
                moving.insert(pool.name.clone());
                retiring.insert(v.clone());
                if let Some(to) = desired_versions.get(pool.name.as_str()) {
                    activating.insert((*to).to_owned());
                }
            }
        }
        if !desired.contains_key(v) {
            retiring.insert(v.clone());
        }
    }
    let mut publications = vec![];
    for v in &retiring {
        publications.push((
            v.clone(),
            desired
                .get(v)
                .map(|c| c.render(|p| !moving.contains(&p.name)))
                .unwrap_or_default(),
        ));
    }
    for v in activating.iter().chain(
        snapshots
            .keys()
            .filter(|v| !retiring.contains(*v) && !activating.contains(*v)),
    ) {
        publications.push((
            v.clone(),
            desired
                .get(v)
                .map(|c| c.render(|_| true))
                .unwrap_or_default(),
        ));
    }
    // Validate the complete transition before any live file or service changes.
    for (v, text) in &publications {
        validate(files, host, v, text)?;
    }
    for (v, snapshot) in &mut snapshots {
        snapshot.active = state(host, "is-active", v)?;
        snapshot.enabled = state(host, "is-enabled", v)?;
    }
    let mut touched = vec![];
    let mut changed = false;
    let operation = (|| -> Result<(), Error> {
        for (v, text) in &publications {
            let path = pool_path(files, v);
            let current = fs::read_to_string(&path).ok();
            let m = metadata(&path, files.uid, false)?;
            let same = if text.is_empty() {
                m.is_none()
            } else {
                current.as_deref() == Some(text)
                    && m.as_ref()
                        .is_some_and(|m| m.gid() == files.gid && m.mode() & 0o7777 == 0o644)
            };
            let active = state(host, "is-active", v)?;
            let enabled = state(host, "is-enabled", v)?;
            if same && active && enabled {
                continue;
            }
            touched.push(v.clone());
            if !same {
                publish(files, v, text, None)?;
            }
            if !enabled {
                control(host, "enable", v)?;
            }
            if !same || !active {
                control(host, "reload-or-restart", v)?;
            }
            changed = true;
        }
        Ok(())
    })();
    if let Err(error) = operation {
        let mut restored = BTreeSet::new();
        let mut recovered = true;
        for v in touched
            .iter()
            .rev()
            .filter(|v| restored.insert((*v).clone()))
        {
            let snapshot = &snapshots[v];
            let text = if snapshot.bytes.is_some() {
                snapshot.configuration.present(files)
            } else {
                String::new()
            };
            let restore = (|| -> Result<(), Error> {
                let metadata = if !text.is_empty() || snapshot.bytes.as_deref() == Some("") {
                    snapshot.metadata.as_ref()
                } else {
                    None
                };
                publish(files, v, &text, metadata)?;
                control(
                    host,
                    if snapshot.enabled {
                        "enable"
                    } else {
                        "disable"
                    },
                    v,
                )?;
                control(
                    host,
                    if snapshot.active {
                        "reload-or-restart"
                    } else {
                        "stop"
                    },
                    v,
                )
            })();
            recovered &= restore.is_ok();
        }
        return Err(if recovered {
            error
        } else {
            Error::RecoveryFailed {}
        });
    }
    Ok(Output {
        changed,
        missing_pools,
    })
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "php.inspect-shared-pools";
    const SUMMARY: &'static str =
        "Inspect shared PHP pool working directories and required versions.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Inspection;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, _: &Context) -> Result<Inspection, Error> {
        inspect(&Files::system(), &input)
    }
}
pub struct Converge;
impl Command for Converge {
    const RESOURCES: &'static [&'static str] = &["php-fpm"];
    const NAME: &'static str = "php.converge-shared-pools";
    const SUMMARY: &'static str =
        "Publish shared PHP pools with ordered version changes and recovery.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(180);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("shared-php-pools".into())
    }
    fn run(input: Input, context: &Context) -> Result<Output, Error> {
        converge(&Files::system(), &mut Native(context), &input)
    }
}
#[cfg(test)]
mod tests;

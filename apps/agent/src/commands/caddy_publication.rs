//! Whole-Node Caddy publication. The Gateway renders intent; this command owns the files and service.
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    ffi::OsStr,
    fs,
    io::{self, Read},
    net::Ipv4Addr,
    os::unix::fs::{symlink, DirBuilderExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

const MARKER: &str = "# Managed by Orbit: Node Caddy build";
const LIMIT: u64 = 4_194_304;

command_errors! {
    pub enum PublicationError {
        Invalid {} => "caddy.publication_invalid", "The Caddy publication input is invalid.";
        Failed { stage: String, message: String } => "caddy.publication_failed", "The Caddy publication failed.";
    }
}
fn failed(stage: &str, message: impl ToString) -> PublicationError {
    PublicationError::Failed {
        stage: stage.into(),
        message: message.to_string().chars().take(2000).collect(),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PublishInput {
    content: String,
    version: String,
    listen_addresses: Vec<Ipv4Addr>,
    /// No site renders, or no Caddy role is active or converging.
    unserved: bool,
}
#[derive(Debug, Serialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum Publication {
    Published,
    Unchanged,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Published {
    result: Publication,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct AddressesInput {
    listen_addresses: Vec<Ipv4Addr>,
}
#[derive(Debug, Serialize)]
pub struct Checked {}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReadInput {}
#[derive(Debug, Serialize, PartialEq)]
pub struct Live {
    content: String,
}

struct Layout {
    root: PathBuf,
    uid: u32,
    gid: Option<u32>,
}
impl Layout {
    fn system() -> Self {
        Self {
            root: "/etc/caddy".into(),
            uid: 0,
            gid: super::caddy::group_id("caddy"),
        }
    }
    fn directory(&self, path: &Path, mode: u32) -> io::Result<()> {
        fs::DirBuilder::new()
            .recursive(true)
            .mode(mode)
            .create(path)?;
        if !fs::symlink_metadata(path)?.is_dir() {
            return Err(io::Error::other(
                "A Caddy directory is not a real directory.",
            ));
        }
        super::caddy::chown(
            path,
            self.uid,
            if mode == 0o700 {
                self.uid
            } else {
                self.gid
                    .ok_or_else(|| io::Error::other("The caddy group does not exist."))?
            },
        )?;
        fs::set_permissions(path, fs::Permissions::from_mode(mode))
    }
}

/// Only the fixed programs whose observations or activation the publication needs.
trait Runtime {
    fn binary_exists(&self) -> bool;
    fn version(&mut self) -> String;
    fn addresses(&mut self) -> Result<Vec<Ipv4Addr>, String>;
    fn validate(&mut self, path: &Path) -> Result<(), String>;
    fn service(&mut self, args: &[&str]) -> bool;
    fn package_default(&mut self, path: &Path) -> bool;
    fn reload_failure(&mut self, since: i64) -> String;
}
struct Native;
fn output(program: &str, args: &[&OsStr]) -> Option<String> {
    let result = Process::new(program)
        .args(args)
        .stdin(Stdio::null())
        .output()
        .ok()?;
    result
        .status
        .success()
        .then(|| String::from_utf8_lossy(&result.stdout).into_owned())
}
impl Runtime for Native {
    fn binary_exists(&self) -> bool {
        Path::new("/usr/bin/caddy").exists()
    }
    fn version(&mut self) -> String {
        output("/usr/bin/caddy", &["version".as_ref()]).unwrap_or_default()
    }
    fn addresses(&mut self) -> Result<Vec<Ipv4Addr>, String> {
        let text = output(
            "/usr/sbin/ip",
            &[
                "-o".as_ref(),
                "-4".as_ref(),
                "addr".as_ref(),
                "show".as_ref(),
            ],
        )
        .ok_or("Node addresses could not be read.")?;
        Ok(text
            .lines()
            .filter_map(|line| {
                line.split_whitespace()
                    .nth(3)?
                    .split('/')
                    .next()?
                    .parse()
                    .ok()
            })
            .collect())
    }
    fn validate(&mut self, path: &Path) -> Result<(), String> {
        let result = Process::new("/usr/sbin/runuser")
            .args([
                "-u",
                "caddy",
                "--",
                "/usr/bin/caddy",
                "validate",
                "--config",
            ])
            .arg(path)
            .args(["--adapter", "caddyfile"])
            .stdin(Stdio::null())
            .output()
            .map_err(|error| error.to_string())?;
        if result.status.success() {
            return Ok(());
        }
        let text = format!(
            "{}\n{}",
            String::from_utf8_lossy(&result.stdout),
            String::from_utf8_lossy(&result.stderr)
        );
        let mut lines: Vec<_> = text
            .lines()
            .filter(|line| {
                line.starts_with("Error:")
                    || line.contains("\"level\":\"error\"")
                    || line.contains("\"level\":\"fatal\"")
            })
            .collect();
        if lines.is_empty() {
            lines = text
                .lines()
                .filter(|line| {
                    !["debug", "info", "warn"]
                        .iter()
                        .any(|level| line.contains(&format!("\"level\":\"{level}\"")))
                })
                .collect();
        }
        Err(lines[lines.len().saturating_sub(5)..].join("\n"))
    }
    fn service(&mut self, args: &[&str]) -> bool {
        Process::new("/usr/bin/systemctl")
            .args(args)
            .arg("caddy")
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .is_ok_and(|status| status.success())
    }
    fn reload_failure(&mut self, since: i64) -> String {
        let since = format!("@{since}");
        let journal = output(
            "/usr/bin/journalctl",
            &[
                "-u".as_ref(),
                "caddy".as_ref(),
                "--since".as_ref(),
                since.as_ref(),
                "--no-pager".as_ref(),
                "-o".as_ref(),
                "cat".as_ref(),
                "--lines=50".as_ref(),
            ],
        )
        .unwrap_or_default();
        let error = journal
            .lines()
            .find(|line| line.starts_with("Error:"))
            .unwrap_or("");
        format!(
            "{error} Caddy did not reload the new version; the previous configuration is restored."
        )
        .trim()
        .to_string()
    }
    fn package_default(&mut self, path: &Path) -> bool {
        let Some(config) = output(
            "/usr/bin/dpkg-query",
            &[
                "-W".as_ref(),
                "-f=${Conffiles}\n".as_ref(),
                "caddy".as_ref(),
            ],
        ) else {
            return false;
        };
        let expected = config.lines().find_map(|line| {
            let mut words = line.split_whitespace();
            (words.next()? == path.to_str()?)
                .then(|| words.next())
                .flatten()
        });
        let digest = output("/usr/bin/md5sum", &["--".as_ref(), path.as_os_str()]);
        expected.is_some()
            && expected
                == digest
                    .as_deref()
                    .and_then(|value| value.split_whitespace().next())
    }
}
fn check_addresses(
    addresses: &[Ipv4Addr],
    runtime: &mut impl Runtime,
) -> Result<(), PublicationError> {
    if addresses.is_empty() {
        return Ok(());
    }
    let present = runtime
        .addresses()
        .map_err(|message| failed("addresses", message))?;
    for address in addresses {
        if !present.contains(address) {
            return Err(failed("addresses", format!("The build binds {address}, which is not an address on this Node. Correct the stored WireGuard or LAN address of the Node, then build again.")));
        }
    }
    Ok(())
}
fn version(content: &[u8]) -> String {
    format!("{:x}", Sha256::digest(content))[..32].into()
}
fn exists(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok()
}
fn remove(path: &Path) -> io::Result<()> {
    match fs::symlink_metadata(path) {
        Ok(meta) if meta.is_dir() => fs::remove_dir_all(path),
        Ok(_) => fs::remove_file(path),
        Err(error) if error.kind() == io::ErrorKind::NotFound => Ok(()),
        Err(error) => Err(error),
    }
}
fn read_live(path: &Path) -> Result<Live, PublicationError> {
    let file = match fs::File::open(path) {
        Ok(file) => file,
        Err(error) if error.kind() == io::ErrorKind::NotFound => {
            return Ok(Live {
                content: String::new(),
            })
        }
        Err(error) => return Err(failed("read-live", error)),
    };
    let mut content = String::new();
    file.take(LIMIT + 1)
        .read_to_string(&mut content)
        .map_err(|error| failed("read-live", error))?;
    if content.len() as u64 > LIMIT {
        return Err(failed(
            "read-live",
            "The live Caddyfile exceeds the read limit.",
        ));
    }
    Ok(Live { content })
}
fn reserve_backup(layout: &Layout) -> io::Result<PathBuf> {
    let backups = layout.root.join("orbit-backups");
    layout.directory(&backups, 0o700)?;
    let stamp = time::OffsetDateTime::now_utc()
        .format(time::macros::format_description!(
            "[year][month][day]T[hour][minute][second]Z"
        ))
        .map_err(io::Error::other)?;
    for suffix in 1..=100 {
        let path = backups.join(if suffix == 1 {
            stamp.clone()
        } else {
            format!("{stamp}-{suffix}")
        });
        match fs::DirBuilder::new().mode(0o700).create(&path) {
            Ok(()) => return Ok(path),
            Err(error) if error.kind() == io::ErrorKind::AlreadyExists => {}
            Err(error) => return Err(error),
        }
    }
    Err(io::Error::other("No free backup name."))
}
fn backup(layout: &Layout, source: &Path, directory: bool) -> io::Result<()> {
    let destination = reserve_backup(layout)?.join(if directory {
        source.file_name().ok_or(io::ErrorKind::InvalidInput)?
    } else {
        OsStr::new("Caddyfile")
    });
    // cp preserves symlinks, permissions, ownership, and timestamps, including old fragment directories.
    let status = Process::new("/usr/bin/cp")
        .args(["-a", "--"])
        .arg(source)
        .arg(destination)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()?;
    if status.success() {
        Ok(())
    } else {
        Err(io::Error::other(
            "The Caddy configuration could not be backed up.",
        ))
    }
}
fn digest_matches(directory: &Path) -> bool {
    let name = directory
        .file_name()
        .and_then(|name| name.to_str())
        .unwrap_or("");
    if name.len() != 32
        || !name
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase())
        || !directory.join("Caddyfile").is_file()
    {
        return true;
    }
    fs::read(directory.join("Caddyfile")).is_ok_and(|bytes| version(&bytes) == name)
}
fn backup_live(layout: &Layout, live: &Path, runtime: &mut impl Runtime) -> io::Result<()> {
    let Ok(meta) = fs::symlink_metadata(live) else {
        return Ok(());
    };
    if meta.is_symlink() {
        let Ok(target) = fs::canonicalize(live) else {
            return Ok(());
        };
        if !target.is_file() {
            return Ok(());
        }
        let directory = target.parent().ok_or(io::ErrorKind::InvalidInput)?;
        let managed = fs::read_to_string(&target)?.lines().next() == Some(MARKER);
        if target.starts_with(layout.root.join("orbit-versions"))
            && ((!managed && directory.join("fragments").is_dir())
                || (managed && !digest_matches(directory)))
        {
            backup(layout, directory, true)?;
        } else if !managed {
            backup(layout, &target, false)?;
        }
    } else if meta.is_file()
        && fs::read_to_string(live)?.lines().next() != Some(MARKER)
        && !runtime.package_default(live)
    {
        backup(layout, live, false)?;
    }
    Ok(())
}
fn prune(layout: &Layout, published: &Path) {
    let Ok(entries) = fs::read_dir(layout.root.join("orbit-versions")) else {
        return;
    };
    let mut entries: Vec<_> = entries
        .flatten()
        .map(|entry| entry.path())
        .filter(|path| {
            path != published
                && !path
                    .file_name()
                    .unwrap_or_default()
                    .as_encoded_bytes()
                    .starts_with(b".")
                && path.join("Caddyfile").is_file()
        })
        .collect();
    entries.sort_by_key(|path| {
        std::cmp::Reverse(fs::metadata(path).and_then(|meta| meta.modified()).ok())
    });
    for directory in entries.into_iter().skip(9) {
        if !digest_matches(&directory) && backup(layout, &directory, true).is_err() {
            continue;
        }
        let _ = remove(&directory);
    }
}
fn release_supported(reported: &str) -> bool {
    let token = reported
        .split_whitespace()
        .next()
        .unwrap_or("")
        .trim_start_matches('v');
    let numbers: Vec<_> = token
        .split(['-', '+'])
        .next()
        .unwrap_or("")
        .split('.')
        .map(str::parse::<u32>)
        .collect();
    matches!(numbers.as_slice(), [Ok(_), Ok(_), Ok(_)])
        && numbers
            .into_iter()
            .collect::<Result<Vec<_>, _>>()
            .is_ok_and(|v| v.as_slice() >= [2, 9, 0].as_slice())
}
fn publish(
    input: &PublishInput,
    layout: &Layout,
    runtime: &mut impl Runtime,
) -> Result<Published, PublicationError> {
    if input.content.len() as u64 > LIMIT
        || input.content.lines().next() != Some(MARKER)
        || version(input.content.as_bytes()) != input.version
    {
        return Err(PublicationError::Invalid {});
    }
    let live = layout.root.join("Caddyfile");
    if input.unserved && !runtime.binary_exists() && !runtime.service(&["is-active", "--quiet"]) {
        if exists(&live) && fs::read(&live).ok().as_deref() != Some(input.content.as_bytes()) {
            let destination = reserve_backup(layout)
                .map_err(|error| failed("release", error))?
                .join("Caddyfile");
            fs::rename(&live, destination).map_err(|error| failed("release", error))?;
        }
        return Ok(Published {
            result: Publication::Unchanged,
        });
    }
    if !release_supported(&runtime.version()) {
        return Err(failed(
            "release",
            "Caddy reported no supported release. The Orbit release floor is 2.9.0.",
        ));
    }
    check_addresses(&input.listen_addresses, runtime)?;
    let versions = layout.root.join("orbit-versions");
    let published = versions.join(&input.version);
    if fs::symlink_metadata(&live).is_ok_and(|meta| meta.is_symlink())
        && fs::canonicalize(&live).ok() == Some(published.join("Caddyfile"))
        && fs::read(&live).ok().as_deref() == Some(input.content.as_bytes())
    {
        return Ok(Published {
            result: Publication::Unchanged,
        });
    }
    let candidate = versions.join(format!(".{}.candidate", input.version));
    let replaced = versions.join(format!(".{}.replaced", input.version));
    let link = layout
        .root
        .join(format!(".Caddyfile.orbit-build-{}", input.version));
    let previous = layout
        .root
        .join(format!(".Caddyfile.orbit-previous-{}", input.version));
    let result = (|| {
        let write = || -> io::Result<()> {
            layout.directory(&versions, 0o750)?;
            remove(&candidate)?;
            layout.directory(&candidate, 0o750)?;
            use io::Write;
            let path = candidate.join("Caddyfile");
            let mut file = fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o640)
                .open(&path)?;
            file.write_all(input.content.as_bytes())?;
            super::caddy::chown(
                &path,
                layout.uid,
                layout
                    .gid
                    .ok_or_else(|| io::Error::other("The caddy group does not exist."))?,
            )?;
            file.set_permissions(fs::Permissions::from_mode(0o640))?;
            Ok(())
        };
        write().map_err(|error| failed("write", error))?;
        runtime
            .validate(&candidate.join("Caddyfile"))
            .map_err(|message| failed("validate", message))?;
        backup_live(layout, &live, runtime).map_err(|error| failed("backup", error))?;
        remove(&link)
            .and_then(|()| remove(&previous))
            .and_then(|()| remove(&replaced))
            .map_err(|error| failed("swap", error))?;
        let had_live = exists(&live);
        let previous_target = fs::read_link(&live).ok();
        if had_live && previous_target.is_none() {
            fs::copy(&live, &previous).map_err(|error| failed("swap", error))?;
        }
        let had_published = exists(&published);
        if had_published {
            fs::rename(&published, &replaced).map_err(|error| failed("swap", error))?;
        }
        let swapped = fs::rename(&candidate, &published)
            .and_then(|()| symlink(published.join("Caddyfile"), &link))
            .and_then(|()| fs::rename(&link, &live));
        let reload_started = time::OffsetDateTime::now_utc().unix_timestamp();
        let activation = match swapped {
            Err(error) => Err(failed("swap", error)),
            Ok(())
                if !runtime.service(&["enable", "--quiet"])
                    || !runtime.service(&["reload-or-restart"]) =>
            {
                Err(failed("reload", runtime.reload_failure(reload_started)))
            }
            Ok(()) => Ok(()),
        };
        if let Err(error) = activation {
            let restore = || -> io::Result<()> {
                remove(&published)?;
                if had_published {
                    fs::rename(&replaced, &published)?;
                }
                remove(&link)?;
                if let Some(target) = previous_target {
                    symlink(target, &link)?;
                    fs::rename(&link, &live)?;
                } else if had_live {
                    fs::rename(&previous, &live)?;
                } else {
                    remove(&live)?;
                }
                Ok(())
            };
            restore().map_err(|error| failed("restore", error))?;
            if had_live {
                if runtime.service(&["is-active", "--quiet"]) {
                    runtime.service(&["reload"]);
                } else {
                    runtime.service(&["start"]);
                }
            }
            return Err(error);
        }
        remove(&replaced).map_err(|error| failed("prune", error))?;
        prune(layout, &published);
        Ok(Published {
            result: Publication::Published,
        })
    })();
    let _ = remove(&candidate);
    let _ = remove(&link);
    // Preserve recovery material if restoring the old publication failed.
    if !matches!(&result, Err(PublicationError::Failed { stage, .. }) if stage == "restore") {
        let _ = remove(&previous);
    }
    result
}

pub struct Publish;
impl Command for Publish {
    const NAME: &'static str = "caddy.publish";
    const SUMMARY: &'static str = "Validate and publish a rendered Node Caddyfile, restoring the previous configuration on reload failure.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = PublishInput;
    type Output = Published;
    type Error = PublicationError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Self::Input) -> Option<String> {
        Some("caddy".into())
    }
    fn run(input: Self::Input, _: &Context) -> Result<Self::Output, Self::Error> {
        let _lock = super::caddy::lock().map_err(|error| failed("lock", error))?;
        publish(&input, &Layout::system(), &mut Native)
    }
}
pub struct CheckAddresses;
impl Command for CheckAddresses {
    const NAME: &'static str = "caddy.check-addresses";
    const SUMMARY: &'static str = "Check that every Caddy listen address exists on the Node.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = AddressesInput;
    type Output = Checked;
    type Error = PublicationError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Self::Input, _: &Context) -> Result<Self::Output, Self::Error> {
        check_addresses(&input.listen_addresses, &mut Native)?;
        Ok(Checked {})
    }
}
pub struct ReadLive;
impl Command for ReadLive {
    const NAME: &'static str = "caddy.read-live";
    const SUMMARY: &'static str = "Read the live Caddyfile without expanding imports.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = ReadInput;
    type Output = Live;
    type Error = PublicationError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(_: Self::Input, _: &Context) -> Result<Self::Output, Self::Error> {
        read_live(Path::new("/etc/caddy/Caddyfile"))
    }
}

#[cfg(test)]
#[path = "caddy_publication_tests.rs"]
mod tests;

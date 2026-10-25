//! Gateway web artifacts. GitHub selection and downloading belong to the control plane.
use super::gateway_adoption::hex;
use crate::{
    command::{identity, run::file_sha256, Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    fs,
    io::{self, Read},
    os::unix::fs::{chown, symlink, DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    time::{Duration, Instant},
};

const ARCHIVE_BYTES: u64 = 128 * 1024 * 1024;
const EXPANDED_BYTES: u64 = 512 * 1024 * 1024;
const ENTRIES: usize = 20_000;
command_errors! {
    pub enum Error {
        Directory {} => "gateway.release_web_directory_missing", "The managed web releases directory is missing or unsafe. Run orbit:gateway-web.";
        Invalid {} => "gateway.release_web_build_invalid", "The web artifact or release path is invalid, unsafe, or exceeds its declared limits.";
        Install {} => "gateway.release_web_install_failed", "The web build could not be installed with Caddy access.";
        Missing {} => "gateway.release_web_build_missing", "The requested web build is not installed.";
        Publish {} => "gateway.release_web_publish_failed", "The web app link could not be safely switched.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReleaseInput {
    web_root: PathBuf,
    release: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InstallInput {
    web_root: PathBuf,
    sha: String,
    archive: PathBuf,
    sha256: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RestoreInput {
    web_root: PathBuf,
    release: String,
    previous: Option<String>,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PruneInput {
    web_root: PathBuf,
    retained: Vec<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Status {
    installed: bool,
    archive_limit: u64,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Installed {
    reused: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Published {
    previous: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Changed {
    changed: bool,
}
fn invalid<T>(_: T) -> Error {
    Error::Invalid {}
}
fn install_failed<T>(_: T) -> Error {
    Error::Install {}
}
fn publish_failed<T>(_: T) -> Error {
    Error::Publish {}
}
fn metadata(path: &Path, uid: u32) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(m) if m.uid() == uid => Ok(Some(m)),
        Ok(_) => Err(Error::Invalid {}),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(e) => Err(invalid(e)),
    }
}
struct Layout {
    uid: u32,
    root: PathBuf,
    releases: PathBuf,
}
impl Layout {
    fn new(root: &Path) -> Result<Self, Error> {
        if !root.is_absolute()
            || root.parent().is_none()
            || fs::canonicalize(root).ok().as_deref() != Some(root)
        {
            return Err(Error::Directory {});
        }
        let uid = fs::symlink_metadata(root)
            .map_err(|_| Error::Directory {})?
            .uid();
        // Root mutations preserve the non-root account that owns the managed directory.
        // Account observations must belong to that same account.
        if uid == 0 || (identity::effective_uid() != 0 && uid != identity::effective_uid()) {
            return Err(Error::Directory {});
        }
        let releases = root.join("releases");
        for path in [root, &releases] {
            if !metadata(path, uid)
                .map_err(|_| Error::Directory {})?
                .is_some_and(|m| m.is_dir())
            {
                return Err(Error::Directory {});
            }
        }
        Ok(Self {
            uid,
            root: root.into(),
            releases,
        })
    }
    fn release(&self, id: &str) -> Result<PathBuf, Error> {
        if !hex(id, 12) {
            return Err(Error::Invalid {});
        }
        Ok(self.releases.join(id))
    }
    fn installed(&self, id: &str) -> Result<bool, Error> {
        let path = self.release(id)?;
        let Some(m) = metadata(&path, self.uid)? else {
            return Ok(false);
        };
        if !m.is_dir() {
            return Err(Error::Invalid {});
        }
        match metadata(&path.join("index.html"), self.uid)? {
            None => Ok(false),
            Some(m) if m.is_file() => Ok(true),
            _ => Err(Error::Invalid {}),
        }
    }
    fn target_id<'a>(&self, target: &'a str) -> Result<&'a str, Error> {
        let absolute = format!("{}/", self.releases.display());
        target
            .strip_prefix("releases/")
            .or_else(|| target.strip_prefix(&absolute))
            .filter(|id| hex(id, 12))
            .ok_or(Error::Publish {})
    }
    fn current(&self) -> Result<Option<String>, Error> {
        let path = self.root.join("current");
        let Some(m) = metadata(&path, self.uid)? else {
            return Ok(None);
        };
        if !m.is_symlink() {
            return Err(Error::Publish {});
        }
        let target = fs::read_link(path)
            .map_err(publish_failed)?
            .into_os_string()
            .into_string()
            .map_err(publish_failed)?;
        self.target_id(&target)?;
        Ok(Some(target))
    }
    fn serving(&self, id: &str, current: Option<&str>) -> bool {
        current.is_some_and(|target| {
            self.target_id(target)
                .is_ok_and(|current_id| current_id == id)
        })
    }
    fn switch(&self, target: &str) -> Result<bool, Error> {
        self.target_id(target)?;
        if self.current()?.as_deref() == Some(target) {
            return Ok(false);
        }
        let next = self
            .root
            .join(format!(".current.{:016x}.next", rand::random::<u64>()));
        symlink(target, &next).map_err(publish_failed)?;
        let result = (|| {
            rustix::fs::chownat(
                rustix::fs::CWD,
                &next,
                Some(rustix::fs::Uid::from_raw(self.uid)),
                None,
                rustix::fs::AtFlags::SYMLINK_NOFOLLOW,
            )
            .map_err(publish_failed)?;
            fs::rename(&next, self.root.join("current")).map_err(publish_failed)
        })();
        if result.is_err() {
            let _ = fs::remove_file(&next);
        }
        result.map(|_| true)
    }
    fn remove(&self, id: &str, current: Option<&str>) -> Result<bool, Error> {
        let path = self.release(id)?;
        if self.serving(id, current) {
            return Ok(false);
        }
        remove_directory(&path, self.uid)
    }
}
fn remove_directory(path: &Path, uid: u32) -> Result<bool, Error> {
    let Some(m) = metadata(path, uid)? else {
        return Ok(false);
    };
    if !m.is_dir() {
        return Err(Error::Invalid {});
    }
    fs::remove_dir_all(path).map_err(install_failed)?;
    Ok(true)
}
struct Entry {
    name: String,
    directory: bool,
    size: u64,
}
fn plan(zip: &mut zip::ZipArchive<fs::File>, deadline: Instant) -> Result<Vec<Entry>, Error> {
    if zip.is_empty() || zip.len() > ENTRIES {
        return Err(Error::Invalid {});
    }
    zip.validate_header_consistency().map_err(invalid)?;
    let mut entries = Vec::new();
    let mut names = BTreeMap::new();
    let mut expanded = 0u64;
    for index in 0..zip.len() {
        if Instant::now() >= deadline {
            return Err(Error::Install {});
        }
        let entry = zip.by_index_raw(index).map_err(invalid)?;
        let name = entry.name().map_err(invalid)?.into_owned();
        let directory = name.ends_with('/');
        let relative = if directory {
            &name[..name.len() - 1]
        } else {
            &name
        };
        let mode = entry.unix_mode().unwrap_or(0) & 0o170000;
        if relative.is_empty()
            || relative.len() > 1024
            || relative.contains('\\')
            || relative.bytes().any(|b| b.is_ascii_control())
            || relative
                .split('/')
                .any(|part| part.is_empty() || part == "." || part == ".." || part.len() > 255)
            || entry.encrypted()
            || (mode != 0 && mode != if directory { 0o040000 } else { 0o100000 })
            || (directory && entry.size() != 0)
            || names.insert(relative.to_owned(), directory).is_some()
        {
            return Err(Error::Invalid {});
        }
        expanded = expanded
            .checked_add(entry.size())
            .ok_or(Error::Invalid {})?;
        if expanded > EXPANDED_BYTES {
            return Err(Error::Invalid {});
        }
        entries.push(Entry {
            name: relative.into(),
            directory,
            size: entry.size(),
        });
    }
    if names.get("index.html") != Some(&false) {
        return Err(Error::Invalid {});
    }
    for entry in &entries {
        for parent in Path::new(&entry.name).ancestors().skip(1) {
            if names.get(parent.to_str().ok_or(Error::Invalid {})?) == Some(&false) {
                return Err(Error::Invalid {});
            }
        }
    }
    // Read to EOF through the ZIP reader so CRC and declared lengths are checked before any filesystem change.
    for (index, entry) in entries.iter().enumerate() {
        if Instant::now() >= deadline {
            return Err(Error::Install {});
        }
        let mut source = zip.by_index(index).map_err(invalid)?.take(entry.size + 1);
        if io::copy(&mut source, &mut io::sink()).map_err(invalid)? != entry.size {
            return Err(Error::Invalid {});
        }
    }
    Ok(entries)
}
fn directory(root: &Path, relative: &Path) -> Result<(), Error> {
    let mut path = root.to_path_buf();
    for part in relative.components() {
        path.push(part);
        match fs::DirBuilder::new().mode(0o700).create(&path) {
            Ok(()) => fs::set_permissions(&path, fs::Permissions::from_mode(0o700))
                .map_err(install_failed)?,
            Err(e) if e.kind() == io::ErrorKind::AlreadyExists && path.is_dir() => {}
            Err(e) => return Err(install_failed(e)),
        }
    }
    Ok(())
}
fn grant(path: &Path, uid: u32, gid: u32) -> Result<(), Error> {
    let m = fs::symlink_metadata(path).map_err(install_failed)?;
    if !m.is_file() && !m.is_dir() {
        return Err(Error::Invalid {});
    }
    if m.is_dir() {
        for entry in fs::read_dir(path).map_err(install_failed)? {
            grant(&entry.map_err(install_failed)?.path(), uid, gid)?;
        }
    }
    chown(path, Some(uid), Some(gid)).map_err(install_failed)?;
    fs::set_permissions(
        path,
        fs::Permissions::from_mode(if m.is_dir() { 0o750 } else { 0o640 }),
    )
    .map_err(install_failed)
}
fn install(input: &InstallInput, gid: u32, deadline: Instant) -> Result<Installed, Error> {
    if !hex(&input.sha, 40) || !hex(&input.sha256, 64) {
        return Err(Error::Invalid {});
    }
    let layout = Layout::new(&input.web_root)?;
    let id = &input.sha[..12];
    if layout.installed(id)? {
        return Ok(Installed { reused: true });
    }
    let current = layout.current()?;
    if layout.serving(id, current.as_deref()) {
        return Err(Error::Install {});
    }
    let target = layout.release(id)?;
    let staging = layout.releases.join(format!(".{id}.partial"));
    if metadata(&staging, layout.uid)?.is_some_and(|m| !m.is_dir()) {
        return Err(Error::Invalid {});
    }
    if !input.archive.is_absolute()
        || fs::canonicalize(&input.archive).ok().as_ref() != Some(&input.archive)
        || !metadata(&input.archive, layout.uid)?
            .is_some_and(|m| m.is_file() && m.len() > 0 && m.len() <= ARCHIVE_BYTES)
        || file_sha256(&input.archive).map_err(invalid)? != input.sha256
    {
        return Err(Error::Invalid {});
    }
    let mut zip =
        zip::ZipArchive::new(fs::File::open(&input.archive).map_err(invalid)?).map_err(invalid)?;
    let entries = plan(&mut zip, deadline)?;
    remove_directory(&staging, layout.uid)?;
    fs::DirBuilder::new()
        .mode(0o700)
        .create(&staging)
        .map_err(install_failed)?;
    let result = (|| {
        chown(&staging, Some(layout.uid), None).map_err(install_failed)?;
        fs::set_permissions(&staging, fs::Permissions::from_mode(0o700)).map_err(install_failed)?;
        for (index, entry) in entries.iter().enumerate() {
            if Instant::now() >= deadline {
                return Err(Error::Install {});
            }
            let relative = Path::new(&entry.name);
            directory(
                &staging,
                if entry.directory {
                    relative
                } else {
                    relative.parent().unwrap()
                },
            )?;
            if entry.directory {
                continue;
            }
            let mut source = zip.by_index(index).map_err(invalid)?.take(entry.size + 1);
            let mut file = fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .open(staging.join(relative))
                .map_err(install_failed)?;
            if io::copy(&mut source, &mut file).map_err(invalid)? != entry.size {
                return Err(Error::Invalid {});
            }
            file.sync_all().map_err(install_failed)?;
        }
        grant(&staging, layout.uid, gid)?;
        remove_directory(&target, layout.uid)?;
        fs::rename(&staging, &target).map_err(install_failed)?;
        Ok(Installed { reused: false })
    })();
    if staging.exists() {
        let _ = fs::remove_dir_all(&staging);
    }
    result
}
fn inspect(input: ReleaseInput) -> Result<Status, Error> {
    let layout = Layout::new(&input.web_root)?;
    Ok(Status {
        installed: layout.installed(&input.release)?,
        archive_limit: ARCHIVE_BYTES,
    })
}
fn publish(input: ReleaseInput) -> Result<Published, Error> {
    let layout = Layout::new(&input.web_root)?;
    if !layout.installed(&input.release)? {
        return Err(Error::Missing {});
    }
    let previous = layout.current()?;
    layout.switch(&format!("releases/{}", input.release))?;
    Ok(Published { previous })
}
fn restore(input: RestoreInput) -> Result<Changed, Error> {
    let layout = Layout::new(&input.web_root)?;
    layout.release(&input.release)?;
    let target = match input.previous {
        Some(target) => {
            layout.target_id(&target)?;
            Some(target)
        }
        None if layout.installed(&input.release)? => Some(format!("releases/{}", input.release)),
        None => None,
    };
    Ok(Changed {
        changed: match target {
            Some(target) => layout.switch(&target)?,
            None => false,
        },
    })
}
fn remove(input: ReleaseInput) -> Result<Changed, Error> {
    let layout = Layout::new(&input.web_root)?;
    Ok(Changed {
        changed: layout.remove(&input.release, layout.current()?.as_deref())?,
    })
}
fn prune(input: PruneInput) -> Result<Changed, Error> {
    if input.retained.iter().any(|id| !hex(id, 12)) {
        return Err(Error::Invalid {});
    }
    if input.retained.is_empty() {
        return Ok(Changed { changed: false });
    }
    let layout = Layout::new(&input.web_root)?;
    let current = layout.current()?;
    let mut candidates = Vec::new();
    for entry in fs::read_dir(&layout.releases).map_err(install_failed)? {
        let entry = entry.map_err(install_failed)?;
        let Some(id) = entry
            .file_name()
            .to_str()
            .filter(|id| hex(id, 12))
            .map(str::to_owned)
        else {
            continue;
        };
        if input.retained.contains(&id) || layout.serving(&id, current.as_deref()) {
            continue;
        }
        if !metadata(&entry.path(), layout.uid)?.is_some_and(|m| m.is_dir()) {
            return Err(Error::Invalid {});
        }
        candidates.push(id);
    }
    let mut changed = false;
    for id in candidates {
        changed |= layout.remove(&id, current.as_deref())?;
    }
    Ok(Changed { changed })
}
macro_rules! web_command {
    ($name:ident, $command:literal, $summary:literal, $mutates:literal, $seconds:literal, $input:ty, $output:ty, $body:expr) => {
        pub struct $name;
        impl Command for $name {
            const NAME: &'static str = $command;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = $mutates;
            const RESOURCES: &'static [&'static str] =
                if $mutates { &["gateway-web"] } else { &[] };
            const TIMEOUT: Duration = Duration::from_secs($seconds);
            type Input = $input;
            type Output = $output;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(
                    platform,
                    if Self::MUTATES {
                        Privilege::Root
                    } else {
                        Privilege::Account
                    },
                )
            }
            fn target(input: &Self::Input) -> Option<String> {
                Self::MUTATES.then(|| format!("gateway-web:{}", input.web_root.display()))
            }
            fn run(input: Self::Input, context: &Context) -> Result<Self::Output, Error> {
                ($body)(input, context)
            }
        }
    };
}
web_command!(
    Inspect,
    "gateway.inspect-web-build",
    "Inspect one web build and the artifact download limit.",
    false,
    30,
    ReleaseInput,
    Status,
    |input, _: &Context| inspect(input)
);
web_command!(
    Install,
    "gateway.install-web-build",
    "Validate and install a downloaded web artifact with Caddy access.",
    true,
    900,
    InstallInput,
    Installed,
    |input, context: &Context| install(
        &input,
        identity::group_id("caddy").ok_or(Error::Install {})?,
        context.deadline()
    )
);
web_command!(
    Publish,
    "gateway.publish-web-build",
    "Publish one installed web build and retain the exact previous target.",
    true,
    30,
    ReleaseInput,
    Published,
    |input, _: &Context| publish(input)
);
web_command!(
    Restore,
    "gateway.restore-web-build",
    "Restore the exact web target preceding a failed activation.",
    true,
    30,
    RestoreInput,
    Changed,
    |input, _: &Context| restore(input)
);
web_command!(
    Remove,
    "gateway.remove-web-build",
    "Remove one retired web build while preserving the current build.",
    true,
    120,
    ReleaseInput,
    Changed,
    |input, _: &Context| remove(input)
);
web_command!(
    Prune,
    "gateway.prune-web-builds",
    "Remove unretained web builds while preserving the current build.",
    true,
    120,
    PruneInput,
    Changed,
    |input, _: &Context| prune(input)
);
#[cfg(test)]
mod tests;

//! The recipe's immutable Node runtime shares the deployment store and its existing lock.
use super::{artifacts, types, Failure, Files, Invocation};
use serde_json::Value;
use std::{
    fs, io,
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Component, Path, PathBuf},
    time::{Duration, Instant},
};

pub const REFERENCE: &str = ".orbit-recipe-node.json";
pub struct Account {
    pub name: String,
    pub uid: u32,
    pub gid: u32,
    pub home: PathBuf,
}
impl Account {
    pub fn named(name: &str) -> Result<Self, Failure> {
        let (uid, gid, home) =
            crate::command::identity::account(name).map_err(|e| Failure::new("account", e))?;
        let current = rustix::process::geteuid().as_raw();
        if uid == 0 || (current != 0 && current != uid) {
            return Err(Failure::new(
                "account",
                "Recipe runtimes require the enrolled non-root account",
            ));
        }
        Ok(Self {
            name: name.into(),
            uid,
            gid,
            home: home.into(),
        })
    }
    pub fn invocation(&self, scope: &Path, argv: &[&str]) -> Invocation {
        let mut command = vec![];
        if rustix::process::geteuid().as_raw() != self.uid {
            command.extend(["sudo".into(), "-u".into(), self.name.clone(), "-H".into()]);
        }
        command.extend([
            "env".into(),
            "-C".into(),
            self.home.display().to_string(),
            format!("VP_HOME={}", scope.display()),
        ]);
        command.extend(argv.iter().map(|v| (*v).into()));
        Invocation::new(&command.iter().map(String::as_str).collect::<Vec<_>>())
    }
}
fn bad(reason: impl ToString) -> Failure {
    Failure::new("tools", reason)
}
pub fn version(value: &str) -> bool {
    regex::Regex::new(r"\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z")
        .unwrap()
        .is_match(value)
}
pub fn scope(files: &Files, account: &Account) -> Result<PathBuf, Failure> {
    let candidates = [
        files.path("/opt/orbit/vite-plus"),
        account.home.join(".vite-plus"),
        account.home.join(".local/share/vite-plus"),
    ];
    for candidate in &candidates {
        match fs::symlink_metadata(candidate) {
            Ok(meta) if !meta.is_dir() || meta.uid() != account.uid => {
                return Err(Failure::io(
                    "tools",
                    candidate,
                    "Vite+ scope is not the managed account's real directory",
                ))
            }
            Ok(_) => {
                if candidate.join("bin/vp").exists() {
                    return Ok(candidate.clone());
                }
            }
            Err(e) if e.kind() == io::ErrorKind::NotFound => {}
            Err(e) => return Err(Failure::io("tools", candidate, e)),
        }
    }
    Ok(candidates[2].clone())
}
pub fn json(path: &Path) -> Result<Value, Failure> {
    match fs::read(path) {
        Ok(data) => {
            let value: Value = serde_json::from_slice(&data).map_err(bad)?;
            Ok(if value.is_object() {
                value
            } else {
                serde_json::json!({})
            })
        }
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(serde_json::json!({})),
        Err(e) => Err(Failure::io("tools", path, e)),
    }
}
pub fn check_home(home: &Path, account: &Account, writable: bool) -> Result<(), Failure> {
    let meta = fs::symlink_metadata(home).map_err(|e| Failure::io("tools", home, e))?;
    if !home.is_absolute()
        || !meta.is_dir()
        || meta.uid() != account.uid
        || (!writable && meta.mode() & 0o022 != 0)
    {
        return Err(bad("The Vite+ store must belong to the enrolled account"));
    }
    Ok(())
}
pub(crate) fn check_path(home: &Path, target: &Path, account: &Account) -> Result<(), Failure> {
    if !target.starts_with(home) {
        return Err(bad("Runtime path is outside its store"));
    }
    for part in target.ancestors().take_while(|p| *p != home) {
        match fs::symlink_metadata(part) {
            Ok(meta)
                if meta.is_symlink() || meta.uid() != account.uid || meta.mode() & 0o022 != 0 =>
            {
                return Err(bad("Runtime paths must belong to the store owner"))
            }
            Ok(_) => {}
            Err(e) if e.kind() == io::ErrorKind::NotFound => {}
            Err(e) => return Err(Failure::io("tools", part, e)),
        }
    }
    Ok(())
}
fn normalize(path: &Path) -> PathBuf {
    let mut normalized = PathBuf::new();
    for part in path.components() {
        match part {
            Component::ParentDir => {
                normalized.pop();
            }
            Component::CurDir => {}
            _ => normalized.push(part.as_os_str()),
        }
    }
    normalized
}
pub fn old_links(home: &Path, account: &Account) -> Result<Vec<(PathBuf, PathBuf)>, Failure> {
    let cache = home.join("js_runtime/node");
    if cache.is_symlink() {
        return Err(bad("Runtime cache is a symlink"));
    }
    let mut links = vec![];
    if !cache.is_dir() {
        return Ok(links);
    }
    for entry in fs::read_dir(&cache).map_err(bad)? {
        let path = entry.map_err(bad)?.path();
        let meta = fs::symlink_metadata(&path).map_err(bad)?;
        if !meta.is_symlink() {
            continue;
        }
        let name = path
            .file_name()
            .and_then(|v| v.to_str())
            .ok_or_else(|| bad("Invalid Node runtime name"))?;
        let target = normalize(&cache.join(fs::read_link(&path).map_err(bad)?));
        let relative = target
            .strip_prefix(home)
            .ok()
            .and_then(|v| v.to_str())
            .unwrap_or_default();
        let pattern = regex::Regex::new(&format!(
            r"\Arecipe/node-[a-f0-9]{{64}}/node-v{}-(?:linux|darwin)-(?:x64|arm64)\z",
            regex::escape(name)
        ))
        .unwrap();
        if !version(name) || meta.uid() != account.uid || !pattern.is_match(relative) {
            return Err(bad("Refusing unrelated Node runtime link"));
        }
        check_path(home, &target, account)?;
        if !target.is_dir() {
            return Err(bad("Former recipe runtime is missing"));
        }
        links.push((path, target));
    }
    links.sort();
    Ok(links)
}
pub fn recipe_version(home: &Path, account: &Account) -> Option<String> {
    fn inspect(home: &Path, account: &Account) -> Result<Option<String>, Failure> {
        check_home(home, account, false)?;
        if !old_links(home, account)?.is_empty() {
            return Ok(None);
        }
        let pin = home.join(REFERENCE);
        let meta = fs::symlink_metadata(&pin).map_err(bad)?;
        if !meta.is_file() || meta.uid() != account.uid || meta.mode() & 0o022 != 0 {
            return Ok(None);
        }
        let pin = json(&pin)?;
        let Some(version) = pin
            .get("version")
            .and_then(Value::as_str)
            .filter(|v| version(v))
        else {
            return Ok(None);
        };
        let runtime = home.join("js_runtime/node").join(version);
        check_path(home, &runtime, account)?;
        let config = json(&home.join("config.json"))?;
        Ok(
            (config.get("defaultNodeVersion").and_then(Value::as_str) == Some(version)
                && runtime.join("bin/node").is_file())
            .then(|| version.into()),
        )
    }
    inspect(home, account).ok().flatten()
}
pub(crate) fn lock(home: &Path, account: &Account, deadline: Instant) -> Result<fs::File, Failure> {
    let path = home.join(".orbit-store.lock");
    let mut options = fs::OpenOptions::new();
    options
        .read(true)
        .write(true)
        .custom_flags(libc::O_NOFOLLOW | libc::O_CLOEXEC)
        .mode(0o644);
    let file = match options.create_new(true).open(&path) {
        Ok(file) => {
            rustix::fs::fchown(
                &file,
                Some(rustix::fs::Uid::from_raw(account.uid)),
                Some(rustix::fs::Gid::from_raw(account.gid)),
            )
            .map_err(bad)?;
            file
        }
        Err(e) if e.kind() == io::ErrorKind::AlreadyExists => {
            options.create_new(false).open(&path).map_err(bad)?
        }
        Err(e) => return Err(bad(e)),
    };
    let metadata = file.metadata().map_err(bad)?;
    if !metadata.is_file() || metadata.uid() != account.uid {
        return Err(bad("Vite+ store lock must belong to the store owner"));
    }
    file.set_permissions(fs::Permissions::from_mode(0o644))
        .map_err(bad)?;
    let deadline = deadline.min(Instant::now() + Duration::from_secs(120));
    loop {
        super::interruption::check()?;
        match rustix::fs::flock(&file, rustix::fs::FlockOperation::NonBlockingLockExclusive) {
            Ok(()) => return Ok(file),
            Err(rustix::io::Errno::WOULDBLOCK) if Instant::now() < deadline => {
                std::thread::sleep(Duration::from_millis(100))
            }
            Err(rustix::io::Errno::WOULDBLOCK) => {
                return Err(Failure::new(
                    "store_lock_timeout",
                    "Vite+ store lock timed out",
                ))
            }
            Err(e) => return Err(bad(format!("Vite+ store lock failed: {e}"))),
        }
    }
}
fn normalized_directories(files: &Files, home: &Path, account: &Account) -> Result<(), Failure> {
    let paths = [
        home.to_owned(),
        home.join("js_runtime"),
        home.join("js_runtime/node"),
    ];
    for path in &paths {
        if let Ok(meta) = fs::symlink_metadata(path) {
            if !meta.is_dir() || meta.uid() != account.uid {
                return Err(bad("Store component is not an owned directory"));
            }
        }
    }
    for path in paths {
        files.directory(&path, account.uid, account.gid)?;
        let mode = fs::metadata(&path).map_err(bad)?.mode() & 0o7777 & !0o022;
        fs::set_permissions(&path, fs::Permissions::from_mode(mode)).map_err(bad)?;
    }
    Ok(())
}
fn migrate(home: &Path, account: &Account) -> Result<(), Failure> {
    for (link, source) in old_links(home, account)? {
        let candidate = link.with_file_name(format!(".orbit-stage-{:016x}", rand::random::<u64>()));
        let result = (|| {
            artifacts::copy_tree(&source, &candidate).map_err(bad)?;
            artifacts::own_tree(&candidate, account.uid, account.gid)?;
            fs::remove_file(&link).map_err(bad)?;
            fs::rename(&candidate, &link).map_err(bad)
        })();
        let _ = fs::remove_dir_all(&candidate);
        result?;
    }
    // Keep the old targets and the deployment ledger; no runtime is collected by convergence.
    Ok(())
}
pub fn matches(source: &Path, current: &Path, account: &Account) -> Result<bool, Failure> {
    if current.is_symlink() || !current.is_dir() {
        return Ok(false);
    }
    let expected = artifacts::contents(source).map_err(bad)?;
    let actual = artifacts::contents(current).map_err(bad)?;
    if expected != actual {
        return Ok(false);
    }
    for relative in expected {
        let original = source.join(&relative);
        let path = current.join(relative);
        let before = fs::symlink_metadata(&original).map_err(bad)?;
        let after = fs::symlink_metadata(&path).map_err(bad)?;
        if after.uid() != account.uid || before.mode() & 0o170000 != after.mode() & 0o170000 {
            return Ok(false);
        }
        if after.is_symlink() {
            if fs::read_link(&original).map_err(bad)? != fs::read_link(&path).map_err(bad)? {
                return Ok(false);
            }
        } else if after.mode() & 0o022 != 0
            || before.mode() & 0o111 != after.mode() & 0o111
            || (after.is_file()
                && crate::command::run::file_sha256(&original).map_err(bad)?
                    != crate::command::run::file_sha256(&path).map_err(bad)?)
        {
            return Ok(false);
        }
    }
    Ok(true)
}
pub fn publish_node(
    files: &Files,
    home: &Path,
    account: &Account,
    version: &str,
    archive: &Path,
    sha256: &str,
    deadline: Instant,
) -> Result<(), Failure> {
    if !self::version(version) || !types::valid_digest(sha256, 64) {
        return Err(bad("Invalid pinned Node runtime"));
    }
    if crate::command::run::file_sha256(archive).map_err(bad)? != sha256 {
        return Err(bad("Node checksum mismatch"));
    }
    check_home(home, account, true)?;
    let _lock = lock(home, account, deadline)?;
    normalized_directories(files, home, account)?;
    migrate(home, account)?;
    let destination = home.join("js_runtime/node").join(version);
    check_path(home, &destination, account)?;
    let stage = destination.with_file_name(format!(".orbit-stage-{:016x}", rand::random::<u64>()));
    let result = (|| {
        artifacts::extract(archive, &stage)?;
        let system = if cfg!(target_os = "macos") {
            "darwin"
        } else {
            "linux"
        };
        let architecture = if cfg!(target_arch = "aarch64") {
            "arm64"
        } else {
            "x64"
        };
        let source = stage.join(format!("node-v{version}-{system}-{architecture}"));
        if source.is_symlink() || !source.is_dir() {
            return Err(bad("Node archive has an unexpected layout"));
        }
        artifacts::own_tree(&source, account.uid, account.gid)?;
        if destination.exists() {
            if !matches(&source, &destination, account)? {
                return Err(bad(
                    "Existing Node runtime differs from the verified archive",
                ));
            }
        } else {
            fs::rename(&source, &destination).map_err(bad)?;
        }
        files.publish(
            &home.join(REFERENCE),
            &serde_json::to_vec(&serde_json::json!({"version":version})).unwrap(),
            0o644,
            account.uid,
            account.gid,
            false,
        )
    })();
    let _ = fs::remove_dir_all(stage);
    result
}

#[cfg(test)]
mod tests;

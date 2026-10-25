//! Publish data for compiled PHP-FPM startup hooks, then retire only recorded files.
use super::recipe::{self, Files, Host, Invocation};
use crate::{
    command::{
        lock,
        registry::{self, HookEntry},
        Context, Outcome, Platform,
    },
    command_errors,
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{
    collections::BTreeSet,
    fs,
    io::{self, Read},
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::Path,
};
const DIRECTORY: &str = "/etc/orbit";
const UNITS: &str = "/etc/systemd/system/php8.5-fpm.service.d";
const MANIFEST: &str = "/etc/orbit/php-fpm-start-hooks";
const MAX_FILE: u64 = 64 * 1024;
command_errors! {
    pub enum Error {
        Invalid { hook: String } => "gateway.start_hooks_invalid", "A startup hook is unregistered, duplicated, or has invalid configuration.";
        Unsafe { path: String } => "gateway.start_hooks_unsafe", "A startup path or ownership manifest is unsafe.";
        Failed { stage: String } => "gateway.start_hooks_failed", "The Gateway startup hooks could not be published.";
        HookFailed { hook: String, cause: String } => "gateway.start_hook_failed", "A Gateway startup hook failed before pool activation.";
    }
}
#[derive(Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct HookInput {
    pub command: String,
    pub input: Value,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub hooks: Vec<HookInput>,
    pub run: bool,
}
#[derive(Debug, Serialize)]
pub struct Output {
    pub changed: bool,
}
/// FPM activation invokes these fixed, synchronous hooks, then releases their target so
/// ExecStartPre can acquire it while the enclosing FPM convergence service is still running.
pub(super) fn before_activation(input: Input, context: &Context) -> Result<Output, Error> {
    let directory = lock::lock_directory().map_err(|_| failed("lock-directory"))?;
    let held = lock::acquire_all(
        &directory,
        Some(orbit_agent_contract::START_HOOK_TARGET),
        &[],
        context.deadline(),
    )
    .map_err(|_| failed("lock"))?;
    let result = converge(
        &Files::system(),
        &mut recipe::Native(context),
        &input,
        registry::hooks(),
        context,
    )?;
    for lock in held {
        lock.release_after_children()
            .map_err(|_| failed("lock-release"))?;
    }
    Ok(result)
}

fn failed(stage: &str) -> Error {
    Error::Failed {
        stage: stage.into(),
    }
}
fn unsafe_path(path: &Path) -> Error {
    Error::Unsafe {
        path: path.display().to_string(),
    }
}
fn name(value: &str) -> bool {
    !value.is_empty()
        && value.split('-').all(|part| {
            !part.is_empty()
                && part
                    .bytes()
                    .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit())
        })
}
fn valid_pair(hook: &str, drop_in: &str) -> bool {
    hook.strip_suffix("-start").is_some_and(name)
        && drop_in != "orbit-vpn.conf"
        && drop_in
            .strip_prefix("orbit-")
            .and_then(|s| s.strip_suffix(".conf"))
            .is_some_and(name)
}
pub(super) fn validate<'a>(
    input: &Input,
    registry: &'a [HookEntry],
) -> Result<Vec<&'a HookEntry>, Error> {
    let mut names = BTreeSet::new();
    let mut units = BTreeSet::new();
    input
        .hooks
        .iter()
        .map(|config| {
            let invalid = || Error::Invalid {
                hook: config.command.clone(),
            };
            let hook = registry
                .iter()
                .find(|entry| entry.command == config.command)
                .ok_or_else(invalid)?;
            if !valid_pair(hook.name, hook.drop_in)
                || hook.description.is_empty()
                || !hook.description.bytes().all(|b| (32..=126).contains(&b))
                || !names.insert(hook.name)
                || !units.insert(hook.drop_in)
                || serde_json::to_vec(&config.input)
                    .map_err(|_| invalid())?
                    .len()
                    > MAX_FILE as usize
            {
                return Err(invalid());
            }
            (hook.validate)(config.input.clone()).map_err(|_| invalid())?;
            Ok(hook)
        })
        .collect()
}
fn metadata(path: &Path) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(meta) => Ok(Some(meta)),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(unsafe_path(path)),
    }
}
fn check_directory(files: &Files, path: &Path) -> Result<(), Error> {
    for parent in path.ancestors() {
        if metadata(parent)?.is_some_and(|meta| {
            !meta.is_dir()
                || meta.uid() != files.uid
                || meta.gid() != files.gid
                || meta.mode() & 0o022 != 0
        }) {
            return Err(unsafe_path(parent));
        }
        if parent == files.root {
            break;
        }
    }
    Ok(())
}
fn read(files: &Files, path: &Path) -> Result<Option<(Vec<u8>, fs::Metadata)>, Error> {
    let Some(meta) = metadata(path)? else {
        return Ok(None);
    };
    if !meta.is_file()
        || meta.uid() != files.uid
        || meta.gid() != files.gid
        || meta.mode() & 0o022 != 0
        || meta.len() > MAX_FILE
        || meta.nlink() != 1
    {
        return Err(unsafe_path(path));
    }
    let mut file = fs::OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW | libc::O_NONBLOCK)
        .open(path)
        .map_err(|_| unsafe_path(path))?;
    let opened = file.metadata().map_err(|_| unsafe_path(path))?;
    if opened.dev() != meta.dev() || opened.ino() != meta.ino() {
        return Err(unsafe_path(path));
    }
    let mut bytes = Vec::new();
    (&mut file)
        .take(MAX_FILE + 1)
        .read_to_end(&mut bytes)
        .map_err(|_| unsafe_path(path))?;
    if bytes.len() >= MAX_FILE as usize {
        return Err(unsafe_path(path));
    }
    Ok(Some((bytes, meta)))
}
fn write(files: &Files, path: &Path, bytes: &[u8], mode: u32) -> Result<bool, Error> {
    if read(files, path)?
        .is_some_and(|(existing, meta)| existing == bytes && meta.mode() & 0o7777 == mode)
    {
        return Ok(false);
    }
    files
        .publish(path, bytes, mode, files.uid, files.gid, false)
        .map_err(|_| failed("publish"))?;
    Ok(true)
}
fn remove(files: &Files, path: &Path) -> Result<bool, Error> {
    if read(files, path)?.is_none() {
        return Ok(false);
    }
    fs::remove_file(path).map_err(|_| failed("retire"))?;
    Ok(true)
}
fn manifest(files: &Files) -> Result<BTreeSet<(String, String)>, Error> {
    let path = files.path(MANIFEST);
    let Some((bytes, _)) = read(files, &path)? else {
        return Ok(BTreeSet::new());
    };
    let text = std::str::from_utf8(&bytes).map_err(|_| unsafe_path(&path))?;
    let mut entries = BTreeSet::new();
    for line in text.lines() {
        let Some((hook, unit)) = line.split_once(' ') else {
            return Err(unsafe_path(&path));
        };
        if !valid_pair(hook, unit) || !entries.insert((hook.into(), unit.into())) {
            return Err(unsafe_path(&path));
        }
    }
    Ok(entries)
}
fn manifest_bytes(entries: &BTreeSet<(String, String)>) -> Vec<u8> {
    entries
        .iter()
        .map(|(name, unit)| format!("{name} {unit}\n"))
        .collect::<String>()
        .into_bytes()
}
fn converge(
    files: &Files,
    host: &mut impl Host,
    input: &Input,
    registry: &[HookEntry],
    context: &Context,
) -> Result<Output, Error> {
    let hooks = validate(input, registry)?;
    let directory = files.path(DIRECTORY);
    let units = files.path(UNITS);
    check_directory(files, &directory)?;
    check_directory(files, &units)?;
    let previous = manifest(files)?;
    let desired: BTreeSet<_> = hooks
        .iter()
        .map(|h| (h.name.to_string(), h.drop_in.to_string()))
        .collect();
    let union: BTreeSet<_> = previous.union(&desired).cloned().collect();
    // Inspect all affected files before any publication or removal.
    for (name, unit) in &union {
        for path in [
            directory.join(name),
            directory.join(format!("{name}.json")),
            units.join(unit),
        ] {
            read(files, &path)?;
        }
    }
    for (path, mode) in [(&directory, 0o711), (&units, 0o755)] {
        if metadata(path)?.is_none() {
            fs::DirBuilder::new()
                .mode(mode)
                .create(path)
                .map_err(|_| failed("directory"))?;
            fs::set_permissions(path, fs::Permissions::from_mode(mode))
                .map_err(|_| failed("directory"))?;
            std::os::unix::fs::chown(path, Some(files.uid), Some(files.gid))
                .map_err(|_| failed("directory"))?;
        }
    }
    let mut changed = false;
    if !union.is_empty() || metadata(&files.path(MANIFEST))?.is_some() {
        changed |= write(files, &files.path(MANIFEST), &manifest_bytes(&union), 0o644)?;
    }
    let mut reload = false;
    for (hook, config) in hooks.iter().zip(&input.hooks) {
        let mut bytes = serde_json::to_vec(&config.input).map_err(|_| failed("configuration"))?;
        bytes.push(b'\n');
        changed |= write(
            files,
            &directory.join(format!("{}.json", hook.name)),
            &bytes,
            0o600,
        )?;
        let unit=format!("# Managed by Orbit: {}\n[Service]\nExecStartPre=+/usr/local/bin/orbit-agent service {}\n",hook.description,hook.command);
        reload |= write(files, &units.join(hook.drop_in), unit.as_bytes(), 0o644)?;
        // A previous delivered program stays executable until systemd forgets its old ExecStartPre.
        reload |= metadata(&directory.join(hook.name))?.is_some();
    }
    let wanted_units: BTreeSet<_> = hooks.iter().map(|h| h.drop_in).collect();
    let wanted_names: BTreeSet<_> = hooks.iter().map(|h| h.name).collect();
    for (_, unit) in &previous {
        if !wanted_units.contains(unit.as_str()) {
            reload = true;
            changed |= remove(files, &units.join(unit))?;
        }
    }
    if !reload {
        let result = recipe::run(
            host,
            &Invocation::new(&[
                "systemctl",
                "show",
                "--property=NeedDaemonReload",
                "--value",
                "php8.5-fpm.service",
            ]),
            true,
        )
        .map_err(|_| failed("inspect-unit"))?;
        reload = result.stdout.trim() == "yes";
    }
    if reload {
        recipe::run(
            host,
            &Invocation::new(&["systemctl", "daemon-reload"]),
            true,
        )
        .map_err(|_| failed("daemon-reload"))?;
        changed = true;
    }
    for (name, _) in &union {
        changed |= remove(files, &directory.join(name))?;
        if !wanted_names.contains(name.as_str()) {
            changed |= remove(files, &directory.join(format!("{name}.json")))?;
        }
    }
    if !union.is_empty() || metadata(&files.path(MANIFEST))?.is_some() {
        changed |= write(
            files,
            &files.path(MANIFEST),
            &manifest_bytes(&desired),
            0o644,
        )?;
    }
    if input.run {
        for (hook, config) in hooks.iter().zip(&input.hooks) {
            match (hook.run)(config.input.clone(), context) {
                Outcome::Ok(_) => {}
                Outcome::Refused { code, .. } => {
                    return Err(Error::HookFailed {
                        hook: hook.command.into(),
                        cause: code,
                    })
                }
            }
        }
    }
    Ok(Output { changed })
}
/// A fixed service name loads only its registered, root-owned data, then uses normal supervision.
pub fn service(arguments: &[String]) -> i32 {
    let [name] = arguments else {
        return 64;
    };
    let Some(hook) = registry::hooks().iter().find(|hook| hook.command == name) else {
        return 64;
    };
    if Platform::current() != Platform::Linux || crate::command::identity::effective_uid() != 0 {
        return 77;
    }
    let files = Files::system();
    let path = files.path(&format!("{DIRECTORY}/{}.json", hook.name));
    let input = (|| {
        check_directory(&files, path.parent().unwrap())?;
        let (bytes, meta) = read(&files, &path)?.ok_or_else(|| unsafe_path(&path))?;
        if meta.mode() & 0o7777 != 0o600 {
            return Err(unsafe_path(&path));
        }
        serde_json::from_slice::<Value>(&bytes).map_err(|_| unsafe_path(&path))
    })();
    let Ok(input) = input else {
        eprintln!("orbit-agent: startup configuration is unavailable");
        return 1;
    };
    let host = crate::command::run::Host::current();
    let Ok(digest) = &host.executable_sha256 else {
        return 1;
    };
    let envelope =
        serde_json::to_vec(&json!({"agent_sha256":digest,"deadline_ms":60000,"input":input}))
            .expect("service envelope serializes");
    match crate::command::run::execute(name, &envelope, &host) {
        Outcome::Ok(_) => 0,
        Outcome::Refused { code, .. } => {
            eprintln!("orbit-agent: startup hook refused: {code}");
            1
        }
    }
}
#[cfg(test)]
mod tests;

//! Inspect exact cached runtimes and install only the recipe's pinned JavaScript inputs.
use super::{
    apt, artifacts,
    assessment::Versions,
    run,
    store::{self, Account},
    types::{Input, Source, Tools},
    Failure, Files, Host, WorkDir,
};
use crate::command::{Command, Context, Platform, Privilege};
use serde::Serialize;
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::{
    collections::BTreeMap,
    fs,
    os::unix::fs::{MetadataExt, PermissionsExt},
    path::{Path, PathBuf},
    time::{Duration, Instant},
};
pub const NAMES: [&str; 5] = ["node", "vp", "vp-cli", "pnpm", "bun"];

pub(crate) fn launcher(scope: &Path, binary: &str) -> Vec<u8> {
    format!(
        "#!/bin/sh\nexport VP_HOME=\"{}\"\nexec \"{}/bin/{binary}\" \"$@\"\n",
        scope.display(),
        scope.display()
    )
    .into_bytes()
}

pub fn converge(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    account: &Account,
    deadline: Instant,
) -> Result<bool, Failure> {
    input.validate()?;
    if input
        .tools
        .keys()
        .any(|name| !NAMES.contains(&name.as_str()))
    {
        return Err(bad("JavaScript installation accepts only JavaScript tools"));
    }
    if input.tools.is_empty() {
        return Ok(false);
    }
    let current = |versions: &Versions| {
        input
            .tools
            .iter()
            .all(|(name, pin)| versions.get(name).and_then(Option::as_ref) == Some(&pin.version))
    };
    let observed = inspect(host, files, &input.tools, Some(account))?;
    if current(&observed) {
        return Ok(false);
    }
    let work = WorkDir::new()?;
    let mut downloads = BTreeMap::new();
    for (name, pin) in input.tools.iter().filter(|(name, _)| *name != "vp") {
        let path = work.0.join(format!("{name}.archive"));
        artifacts::download(host, name, pin, input, &path)?;
        downloads.insert(name.clone(), path);
    }
    install(host, files, input, &downloads, &work.0, account, deadline)?;
    if !current(&inspect(host, files, &input.tools, Some(account))?) {
        return Err(bad("Installed JavaScript tools do not match the recipe"));
    }
    Ok(true)
}

#[derive(Serialize)]
pub struct Installed {
    pub changed: bool,
    pub retired: Vec<String>,
}
pub struct Install;
impl Command for Install {
    const NAME: &'static str = "recipe.install-javascript";
    const SUMMARY: &'static str =
        "Install and verify recipe JavaScript runtimes before retiring duplicate Node packages.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    const RESOURCES: &'static [&'static str] = &["packages"];
    type Input = Input;
    type Output = Installed;
    type Error = Failure;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("machine-recipe".into())
    }
    fn run(input: Input, context: &Context) -> Result<Installed, Failure> {
        let mut host = super::Native(context);
        let files = Files::system();
        let account = Account::named(&input.user)?;
        let changed = converge(&mut host, &files, &input, &account, context.deadline())?;
        let versions = inspect(&mut host, &files, &input.tools, Some(&account))?;
        let retired = super::ownership::consolidate_node(
            &mut host,
            &files,
            &input,
            versions.get("node").and_then(Option::as_deref),
        )?;
        Ok(Installed { changed, retired })
    }
}
fn bad(reason: impl ToString) -> Failure {
    Failure::new("tools", reason)
}
pub(crate) fn binary_version(
    host: &mut impl Host,
    account: &Account,
    scope: &Path,
    binary: &Path,
) -> Result<Option<String>, Failure> {
    let invocation = account.invocation(scope, &[&binary.display().to_string(), "--version"]);
    let output = run(host, &invocation, false)?;
    if output.code != 0 {
        return Ok(None);
    }
    Ok(
        regex::Regex::new(r"(?:\A|[\s v])([0-9]+\.[0-9]+\.[0-9]+)\b")
            .unwrap()
            .captures(&output.stdout)
            .map(|m| m[1].into()),
    )
}
fn metadata_version(path: &Path) -> Result<Option<String>, Failure> {
    let object = store::json(path)?;
    match object.get("version") {
        Some(Value::String(s)) => Ok(Some(s.clone())),
        None | Some(Value::Null) => Ok(None),
        _ => Err(bad("Runtime version is not a string")),
    }
}
pub fn inspect(
    host: &mut impl Host,
    files: &Files,
    tools: &Tools,
    account: Option<&Account>,
) -> Result<Versions, Failure> {
    let installed = apt::packages(host)?;
    let js = tools.keys().any(|key| NAMES.contains(&key.as_str()));
    let scope = if js {
        Some(store::scope(
            files,
            account.ok_or_else(|| bad("JavaScript inventory requires the managed account"))?,
        )?)
    } else {
        None
    };
    let mut versions = Versions::new();
    for (name, pin) in tools {
        let version = if pin.source == Source::Ubuntu || pin.deb() {
            installed
                .get(if pin.source == Source::Ubuntu {
                    pin.package
                        .as_deref()
                        .ok_or_else(|| bad("Ubuntu package name is missing"))?
                } else {
                    name
                })
                .cloned()
        } else {
            let scope = scope
                .as_ref()
                .ok_or_else(|| bad("Unknown non-package recipe tool"))?;
            let account = account.ok_or_else(|| bad("Managed account is missing"))?;
            match name.as_str() {
                "bun" => {
                    binary_version(host, account, scope, &files.path("/opt/orbit/bun/bin/bun"))?
                }
                "vp-cli" => match fs::canonicalize(scope.join("bin/vp")) {
                    Ok(binary)
                        if binary.is_file()
                            && binary
                                .parent()
                                .unwrap()
                                .join(".vp-setup-complete")
                                .is_file() =>
                    {
                        binary_version(host, account, scope, &binary)?
                    }
                    _ => None,
                },
                "vp" => {
                    let digest = pin
                        .lock_sha256
                        .as_deref()
                        .ok_or_else(|| bad("Vite+ lock digest is missing"))?;
                    let target = scope.join("recipe").join(format!("vp-runtime-{digest}"));
                    let package = target.join("node_modules/vite-plus");
                    let lock = target.join("package-lock.json");
                    let active = scope.join("node_modules/vite-plus");
                    if lock.is_file()
                        && crate::command::run::file_sha256(&lock).map_err(bad)? == digest
                        && active.is_symlink()
                        && fs::canonicalize(active).is_ok_and(|p| p == package)
                    {
                        metadata_version(&package.join("package.json"))?
                    } else {
                        None
                    }
                }
                "pnpm" => {
                    let recorded =
                        metadata_version(&scope.join("recipe/pnpm-current/package.json"))?;
                    let executable = binary_version(host, account, scope, &scope.join("bin/pnpm"))?;
                    if recorded == executable {
                        executable
                    } else {
                        None
                    }
                }
                "node" => match store::recipe_version(scope, account) {
                    Some(version) => binary_version(
                        host,
                        account,
                        scope,
                        &scope.join("js_runtime/node").join(version).join("bin/node"),
                    )?,
                    None => None,
                },
                _ => return Err(bad("Unknown non-package recipe tool")),
            }
        };
        versions.insert(name.clone(), version);
    }
    Ok(versions)
}
fn link_owner(path: &Path, account: &Account) -> Result<(), Failure> {
    rustix::fs::chownat(
        rustix::fs::CWD,
        path,
        Some(rustix::fs::Uid::from_raw(account.uid)),
        Some(rustix::fs::Gid::from_raw(account.gid)),
        rustix::fs::AtFlags::SYMLINK_NOFOLLOW,
    )
    .map_err(bad)
}
fn link(path: &Path, target: &Path, candidate: &Path, account: &Account) -> Result<(), Failure> {
    match fs::symlink_metadata(path) {
        Ok(meta) if !meta.is_symlink() || meta.uid() != account.uid => {
            return Err(Failure::io(
                "tools",
                path,
                "Runtime alias is not an owned symlink",
            ))
        }
        Ok(_) => {}
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
        Err(e) => return Err(bad(e)),
    }
    if fs::symlink_metadata(candidate).is_ok() {
        return Err(Failure::io(
            "tools",
            candidate,
            "Runtime candidate already exists",
        ));
    }
    std::os::unix::fs::symlink(target, candidate).map_err(bad)?;
    let result =
        link_owner(candidate, account).and_then(|()| fs::rename(candidate, path).map_err(bad));
    let _ = fs::remove_file(candidate);
    result
}
fn package_link(
    runtime: &Path,
    name: &str,
    package: &Path,
    account: &Account,
) -> Result<(), Failure> {
    link(
        &runtime.join(format!("{name}-current")),
        package,
        &runtime.join(format!("{name}-candidate")),
        account,
    )
}
pub fn install_vite(
    host: &mut impl Host,
    files: &Files,
    scope: &Path,
    runtime: &Path,
    input: &Input,
    account: &Account,
) -> Result<(), Failure> {
    let pin = input
        .tools
        .get("vp")
        .ok_or_else(|| bad("Vite+ pin is missing"))?;
    let content = input
        .vp_lock
        .as_deref()
        .ok_or_else(|| bad("Vite+ lock is missing"))?;
    let digest = pin
        .lock_sha256
        .as_deref()
        .ok_or_else(|| bad("Vite+ lock digest is missing"))?;
    if format!("{:x}", Sha256::digest(content.as_bytes())) != digest {
        return Err(bad("Vite+ lock digest differs from the pin"));
    }
    let lock: Value = serde_json::from_str(content).map_err(bad)?;
    let dependencies = json!({"vite-plus":pin.version});
    if lock["packages"][""]["dependencies"] != dependencies
        || !lock["name"].is_string()
        || !lock["version"].is_string()
    {
        return Err(bad("Vite+ lock has unexpected root dependencies"));
    }
    let target = runtime.join(format!("vp-runtime-{digest}"));
    if !target.exists() {
        let candidate = runtime.join(format!(".vp-runtime-{:016x}", rand::random::<u64>()));
        files.directory(&candidate, account.uid, account.gid)?;
        let result = (|| {
            let manifest = json!({"name":lock["name"],"version":lock["version"],"private":true,"dependencies":dependencies});
            files.publish(
                &candidate.join("package.json"),
                &serde_json::to_vec(&manifest).unwrap(),
                0o644,
                account.uid,
                account.gid,
                false,
            )?;
            files.publish(
                &candidate.join("package-lock.json"),
                content.as_bytes(),
                0o644,
                account.uid,
                account.gid,
                false,
            )?;
            let node = scope.join("js_runtime/node").join(
                &input
                    .tools
                    .get("node")
                    .ok_or_else(|| bad("Vite+ requires the pinned Node runtime"))?
                    .version,
            );
            let cache = runtime.join("npm-cache");
            files.directory(&cache, account.uid, account.gid)?;
            let invocation = account.invocation(
                scope,
                &[
                    &format!("PATH={}/bin:/bin:/usr/bin", node.display()),
                    &format!("npm_config_cache={}", cache.display()),
                    &node.join("bin/node").display().to_string(),
                    &node
                        .join("lib/node_modules/npm/bin/npm-cli.js")
                        .display()
                        .to_string(),
                    "ci",
                    "--ignore-scripts",
                    "--no-audit",
                    "--no-fund",
                    "--prefix",
                    &candidate.display().to_string(),
                ],
            );
            run(host, &invocation, true)?;
            fs::rename(&candidate, &target).map_err(bad)
        })();
        let _ = fs::remove_dir_all(&candidate);
        result?;
    }
    files.directory(&target, account.uid, account.gid)?;
    if crate::command::run::file_sha256(&target.join("package-lock.json")).map_err(bad)? != digest {
        return Err(bad("Installed Vite+ lock digest differs from the pin"));
    }
    let package = target.join("node_modules/vite-plus");
    if metadata_version(&package.join("package.json"))?.as_deref() != Some(pin.version.as_str()) {
        return Err(bad("Installed Vite+ runtime version differs from the pin"));
    }
    let modules = scope.join("node_modules");
    if modules.is_symlink() {
        if fs::symlink_metadata(&modules).map_err(bad)?.uid() != account.uid {
            return Err(bad("Vite+ module link belongs to another account"));
        }
        files.directory(
            &fs::canonicalize(&modules).map_err(bad)?,
            account.uid,
            account.gid,
        )?;
    } else {
        files.directory(&modules, account.uid, account.gid)?;
    }
    link(
        &modules.join("vite-plus"),
        &package,
        &modules.join(".orbit-recipe-vite-plus"),
        account,
    )?;
    package_link(runtime, "vp", &package, account)
}
pub fn install(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    downloads: &BTreeMap<String, PathBuf>,
    work: &Path,
    account: &Account,
    deadline: Instant,
) -> Result<(), Failure> {
    let scope = store::scope(files, account)?;
    if !scope.exists() {
        for directory in [
            account.home.join(".local"),
            account.home.join(".local/share"),
        ] {
            files.directory(&directory, account.uid, account.gid)?;
        }
    }
    files.directory(&files.path("/opt/orbit"), files.uid, files.gid)?;
    files.directory(&scope, account.uid, account.gid)?;
    let bin = scope.join("bin");
    let runtime = scope.join("recipe");
    for path in [&bin, &runtime] {
        files.directory(path, account.uid, account.gid)?;
    }
    for name in ["node", "vp-cli", "pnpm", "bun"] {
        let Some(archive) = downloads.get(name) else {
            continue;
        };
        let pin = input
            .tools
            .get(name)
            .ok_or_else(|| bad("Downloaded runtime has no pin"))?;
        let digest = crate::command::run::file_sha256(archive).map_err(bad)?;
        if name == "node" {
            store::publish_node(
                files,
                &scope,
                account,
                &pin.version,
                archive,
                &digest,
                deadline,
            )?;
            continue;
        }
        let unpacked = work.join(format!("unpack-{name}"));
        artifacts::extract(archive, &unpacked)?;
        let target = runtime.join(format!("{name}-{digest}"));
        if !target.exists() {
            artifacts::copy_tree(&unpacked, &target).map_err(bad)?;
            artifacts::own_tree(&target, account.uid, account.gid)?;
        }
        files.directory(&target, account.uid, account.gid)?;
        match name {
            "vp-cli" => {
                files.publish(
                    &bin.join("vp"),
                    &fs::read(target.join("vp")).map_err(bad)?,
                    0o755,
                    account.uid,
                    account.gid,
                    true,
                )?;
                files.publish(
                    &bin.join(".vp-setup-complete"),
                    b"",
                    0o644,
                    account.uid,
                    account.gid,
                    false,
                )?;
            }
            "pnpm" => {
                if metadata_version(&target.join("package/package.json"))?.as_deref()
                    != Some(pin.version.as_str())
                {
                    return Err(bad("pnpm package version differs from the pin"));
                }
                let native = target.join("package/pnpm");
                let executable = target.join("package/bin/pnpm.cjs");
                let bytes = fs::read(&native).unwrap_or_default();
                let content = if bytes.starts_with(b"\x7fELF") {
                    bytes
                } else if executable.is_file() {
                    format!(
                        "#!/bin/sh\nexec \"{}\" \"{}\" \"$@\"\n",
                        bin.join("node").display(),
                        executable.display()
                    )
                    .into_bytes()
                } else {
                    return Err(bad("pnpm executable is missing"));
                };
                package_link(&runtime, "pnpm", &target.join("package"), account)?;
                files.publish(
                    &bin.join("pnpm"),
                    &content,
                    0o755,
                    account.uid,
                    account.gid,
                    true,
                )?;
            }
            "bun" => {
                let mut executables = fs::read_dir(&target)
                    .map_err(bad)?
                    .map(|e| e.map(|e| e.path().join("bun")))
                    .collect::<Result<Vec<_>, _>>()
                    .map_err(bad)?;
                executables.retain(|p| p.is_file());
                executables.sort();
                let [executable] = executables.as_slice() else {
                    return Err(bad("Bun archive has an unexpected layout"));
                };
                fs::set_permissions(executable, fs::Permissions::from_mode(0o755)).map_err(bad)?;
                for path in [
                    files.path("/opt/orbit/bun"),
                    files.path("/opt/orbit/bun/bin"),
                ] {
                    files.directory(&path, account.uid, account.gid)?;
                }
                files.publish(
                    &files.path("/opt/orbit/bun/bin/bun"),
                    &fs::read(executable).map_err(bad)?,
                    0o755,
                    account.uid,
                    account.gid,
                    true,
                )?;
                files.publish(
                    &files.path("/usr/local/bin/bun"),
                    b"#!/bin/sh\nexec /opt/orbit/bun/bin/bun \"$@\"\n",
                    0o755,
                    files.uid,
                    files.gid,
                    true,
                )?;
            }
            _ => unreachable!(),
        }
    }
    if input.tools.contains_key("vp") {
        install_vite(host, files, &scope, &runtime, input, account)?;
    }
    if let Some(pin) = input.tools.get("node") {
        for binary in ["node", "npm", "npx"] {
            let path = bin.join(binary);
            if fs::symlink_metadata(&path).is_ok() {
                if fs::symlink_metadata(&path).map_err(bad)?.uid() != account.uid {
                    return Err(bad("Runtime shim belongs to another account"));
                }
                fs::remove_file(&path).map_err(bad)?;
            }
            std::os::unix::fs::symlink("vp", &path).map_err(bad)?;
            link_owner(&path, account)?;
        }
        run(
            host,
            &account.invocation(
                &scope,
                &[
                    &bin.join("vp").display().to_string(),
                    "env",
                    "default",
                    &pin.version,
                ],
            ),
            true,
        )?;
    }
    for binary in ["vp", "node", "pnpm", "npm", "npx"] {
        let content = launcher(&scope, binary);
        files.publish(
            &files.path("/usr/local/bin").join(binary),
            &content,
            0o755,
            files.uid,
            files.gid,
            false,
        )?;
    }
    Ok(())
}

#[cfg(test)]
mod tests;
